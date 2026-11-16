<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Account_model extends Model
{
    protected $table = 'Users';
    protected $primary_key = 'user_id';
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'location',
        'email_alerts',
    ];
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function findById(int $userId): ?array
    {
        return $this->loadUser('Users.user_id', $userId);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->loadUser('Users.email', $email);
    }

    public function create(array $account, string $organization = ''): int
    {
        $this->db->transaction();
        try {
            $payload = $this->fillable_attributes($account, ['first_name', 'last_name', 'email', 'password', 'role', 'location']);
            $payload['email_alerts'] = $payload['email_alerts'] ?? 1;

            $this->db->table($this->table)->insert($payload);
            $userId = (int) $this->db->last_id();

            if (($account['role'] ?? '') === 'employer') {
                $this->db->table('Employers')->insert([
                    'user_id' => $userId,
                    'company_name' => $organization,
                    'email' => $account['email'],
                    'address' => $account['location'] ?? '',
                ]);
            } elseif (($account['role'] ?? '') === 'training_provider') {
                $this->db->table('TrainingProviders')->insert([
                    'user_id' => $userId,
                    'institution_name' => $organization,
                    'email' => $account['email'],
                    'address' => $account['location'] ?? '',
                ]);
            }

            $this->db->commit();
            return $userId;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function getAlertEmails(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$userIds) {
            return [];
        }

        $rows = $this->db->table($this->table)
            ->select('email')
            ->where('email_alerts', 1)
            ->in('user_id', $userIds)
            ->get_all();

        return array_map(static function (array $row): string {
            return (string) $row['email'];
        }, $rows);
    }

    public function updateEmailAlerts(int $userId, bool $enabled): void
    {
        $this->db->table($this->table)
            ->where('user_id', $userId)
            ->update(['email_alerts' => $enabled ? 1 : 0]);
    }

    public function upgradePassword(int $userId, string $passwordHash): void
    {
        $this->db->table($this->table)
            ->where('user_id', $userId)
            ->update(['password' => $passwordHash]);
    }

    public function countAll(): int
    {
        $row = $this->db->table($this->table)
            ->select_count('user_id', 'total')
            ->get();

        return (int) ($row['total'] ?? 0);
    }

    public function getJobSeekers(): array
    {
        return $this->db->table($this->table)
            ->select('user_id AS id, email')
            ->where('role', 'job_seeker')
            ->get_all();
    }

    public function updateOrganization(int $userId, string $role, string $organization): void
    {
        if ($role === 'employer') {
            $table = 'Employers';
            $column = 'company_name';
        } elseif ($role === 'provider') {
            $table = 'TrainingProviders';
            $column = 'institution_name';
        } else {
            throw new InvalidArgumentException('Only employer and provider accounts have organizations.');
        }

        $this->db->table($table)
            ->where('user_id', $userId)
            ->update([$column => $organization]);
    }

    public function updateProfile(int $userId, array $fields): void
    {
        $allowed = ['first_name', 'last_name', 'location', 'qualifications', 'experience'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if (!$fields) {
            return;
        }

        $this->db->table($this->table)
            ->where('user_id', $userId)
            ->update($fields);
    }

    public function updateProfilePhoto(int $userId, string $url, string $publicId): ?string
    {
        $current = $this->db->table($this->table)
            ->select('profile_image_public_id')
            ->where('user_id', $userId)
            ->get()['profile_image_public_id'] ?? null;

        $this->db->table($this->table)
            ->where('user_id', $userId)
            ->update([
                'profile_image_url' => $url,
                'profile_image_public_id' => $publicId,
            ]);

        return is_string($current) && $current !== '' ? $current : null;
    }

    private function loadUser(string $column, $value): ?array
    {
        $query = $this->db->table('Users')
            ->select('Users.user_id AS id, Users.first_name, Users.last_name,
                    Users.email, Users.password AS password_hash, Users.role,
                    Users.location, Users.qualifications, Users.experience,
                    Users.profile_image_url, Users.profile_image_public_id,
                    Employers.company_name, TrainingProviders.institution_name, Users.email_alerts')
            ->left_join('Employers', 'Employers.user_id = Users.user_id')
            ->left_join('TrainingProviders', 'TrainingProviders.user_id = Users.user_id')
            ->where($column, $value)
            ->limit(1);

        $user = $query->get();
        if (!$user) {
            return null;
        }

        $user['name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $user['qualifications'] = $user['qualifications'] ?? '';
        $user['experience'] = $user['experience'] ?? '';
        $user['organization'] = $user['company_name'] ?: ($user['institution_name'] ?: '');
        unset($user['first_name'], $user['last_name'], $user['company_name'], $user['institution_name']);
        $user['role'] = match ($user['role'] ?? '') {
            'job_seeker' => 'student',
            'training_provider' => 'provider',
            default => $user['role'] ?? '',
        };

        $skills = lava_instance()->call->model('Skills_model');
        $user['skills'] = $skills->getForUser((int) $user['id']);
        return $user;
    }
}
