<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Notification_model extends Model
{
    protected $table = 'Notifications';
    protected $primary_key = 'notification_id';
    protected $fillable = [
        'user_id',
        'title',
        'message',
        'notification_type',
        'is_read',
        'url',
    ];
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function getForUser(int $userId): array
    {
        $rows = $this->db->table($this->table)
            ->select('notification_id AS id, notification_type AS kind, title, message AS details, url, is_read, created_at')
            ->where('user_id', $userId)
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();

        foreach ($rows as &$row) {
            $row['read_at'] = !empty($row['is_read']) ? $row['created_at'] : null;
        }
        unset($row);

        return $rows;
    }

    public function countUnread(int $userId): int
    {
        $row = $this->db->table($this->table)
            ->select_count('notification_id', 'total')
            ->where('user_id', $userId)
            ->where('COALESCE(is_read, 0)', 0)
            ->get();

        return (int) ($row['total'] ?? 0);
    }

    public function create(int $userId, string $kind, string $title, string $details, string $url): void
    {
        $this->db->table($this->table)->insert([
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 150),
            'message' => mb_substr($details, 0, 500),
            'notification_type' => $kind,
            'is_read' => 0,
            'url' => $url,
        ]);
    }

    public function createForUsers(array $userIds, string $kind, string $title, string $details, string $url): void
    {
        $title = mb_substr($title, 0, 150);
        $details = mb_substr($details, 0, 500);
        $records = [];
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId < 1) continue;
            $records[] = [
                'user_id' => $userId,
                'title' => $title,
                'message' => $details,
                'notification_type' => $kind,
                'is_read' => 0,
                'url' => $url,
            ];
        }

        foreach (array_chunk($records, 500) as $batch) {
            $this->db->table($this->table)->bulk_insert($batch);
        }
    }

    public function markRead(int $notificationId, int $userId): void
    {
        $this->db->table($this->table)
            ->where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->update(['is_read' => 1]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->table($this->table)
            ->where('user_id', $userId)
            ->where('COALESCE(is_read, 0)', 0)
            ->update(['is_read' => 1]);
    }

    public function savePushSubscription(int $userId, string $endpoint, string $publicKey, string $authToken): void
    {
        $hash = hash('sha256', $endpoint);
        $existing = $this->db->table('TaraPushSubscriptions')
            ->where('endpoint_hash', $hash)
            ->limit(1)
            ->get();

        if ($existing) {
            $this->db->table('TaraPushSubscriptions')
                ->where('endpoint_hash', $hash)
                ->update([
                    'user_id' => $userId,
                    'endpoint' => $endpoint,
                    'public_key' => $publicKey,
                    'auth_token' => $authToken,
                ]);
            return;
        }

        $this->db->table('TaraPushSubscriptions')->insert([
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'endpoint_hash' => $hash,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
        ]);
    }

    public function removePushSubscription(int $userId, string $endpoint): void
    {
        $this->db->table('TaraPushSubscriptions')
            ->where('user_id', $userId)
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->delete();
    }

    public function getJobSeekerPushSubscriptions(): array
    {
        return $this->db->table('TaraPushSubscriptions')
            ->select('TaraPushSubscriptions.endpoint, TaraPushSubscriptions.endpoint_hash, TaraPushSubscriptions.public_key, TaraPushSubscriptions.auth_token')
            ->inner_join('Users', 'Users.user_id = TaraPushSubscriptions.user_id')
            ->where('Users.role', 'job_seeker')
            ->get_all();
    }

    public function getPushSubscriptionsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$userIds) {
            return [];
        }

        return $this->db->table('TaraPushSubscriptions')
            ->select('TaraPushSubscriptions.endpoint, TaraPushSubscriptions.endpoint_hash, TaraPushSubscriptions.public_key, TaraPushSubscriptions.auth_token')
            ->inner_join('Users', 'Users.user_id = TaraPushSubscriptions.user_id')
            ->where('Users.role', 'job_seeker')
            ->in('Users.user_id', $userIds)
            ->get_all();
    }

    public function removeExpiredPushSubscription(string $endpoint): void
    {
        $this->db->table('TaraPushSubscriptions')
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->delete();
    }
}
