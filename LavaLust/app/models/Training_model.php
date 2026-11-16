<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Training_model extends Model
{
    protected $table = 'TrainingPrograms';
    protected $primary_key = 'program_id';
    protected $fillable = [
        'provider_id',
        'program_name',
        'description',
        'skills_covered',
        'duration',
        'schedule',
        'location',
        'category',
        'slots_available',
        'status',
        'starts',
        'ends',
    ];
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function getPublished(int $limit = 0): array
    {
        return $this->fetchListings(null, 'published', $limit);
    }

    public function getForOwner(int $ownerId, int $limit = 0): array
    {
        if ($ownerId < 1) {
            throw new InvalidArgumentException('Owner ID must be a positive integer.');
        }

        return $this->fetchListings($ownerId, null, $limit);
    }

    public function getById(int $listingId, ?string $status = null): ?array
    {
        if ($listingId < 1) {
            throw new InvalidArgumentException('Listing ID must be a positive integer.');
        }

        return $this->fetchListings(null, $status, 1, $listingId)[0] ?? null;
    }

    public function getPending(int $limit = 100): array
    {
        return $this->fetchListings(null, 'pending', $limit);
    }

    public function count(?int $ownerId = null, ?string $status = null): int
    {
        $providerId = null;
        if ($ownerId !== null) {
            $provider = $this->db->table('TrainingProviders')->select('provider_id')->where('user_id', $ownerId)->get();
            $providerId = (int) ($provider['provider_id'] ?? 0);
            if ($providerId) {
                $providerId = (int) $providerId;
            } else {
                return 0;
            }
        }

        $query = $this->db->table($this->table)->select_count($this->primary_key, 'total');
        if ($providerId !== null) {
            $query->where('provider_id', $providerId);
        }
        if ($status !== null) {
            $query->where('LOWER(status)', strtolower($status));
        }

        $result = $query->get();
        return (int) ($result['total'] ?? 0);
    }

    public function createForOwner(int $userId, array $program): ?int
    {
        $owner = $this->db->table('TrainingProviders')->select('provider_id')->where('user_id', $userId)->get();
        $providerId = $owner['provider_id'] ?? null;
        if (!$providerId) {
            return null;
        }

        $payload = $this->fillable_attributes(array_merge($program, [
            'provider_id' => $providerId,
            'status' => 'pending',
        ]));

        $this->db->table($this->table)->insert($payload);

        return (int) $this->db->last_id();
    }

    public function closeForOwner(int $userId, int $programId): bool
    {
        $owner = $this->db->table('TrainingProviders')->select('provider_id')->where('user_id', $userId)->get();
        $providerId = (int) ($owner['provider_id'] ?? 0);
        if (!$providerId) return false;

        $affected = $this->db->table($this->table)
            ->where($this->primary_key, $programId)
            ->where('provider_id', $providerId)
            ->where('LOWER(status)', 'published')
            ->update(['status' => 'closed']);

        return $affected > 0;
    }

    private function fetchListings(?int $ownerId, ?string $status, int $limit, ?int $listingId = null): array
    {
        $query = $this->db->table('TrainingPrograms')
            ->select('TrainingPrograms.program_id AS id, TrainingPrograms.program_name AS title,
                       TrainingProviders.institution_name AS organization, TrainingPrograms.location, TrainingPrograms.category,
                       TrainingPrograms.starts, TrainingPrograms.ends, TrainingPrograms.duration, TrainingPrograms.slots_available AS slots,
                       TrainingPrograms.description, TrainingPrograms.skills_covered AS skills, TrainingPrograms.status,
                       TrainingProviders.user_id AS owner_id')
            ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
            ->order_by('TrainingPrograms.program_id', 'DESC');

        if ($status !== null) {
            $query->where('LOWER(TrainingPrograms.status)', strtolower($status));
        }
        if ($ownerId !== null) {
            $query->where('TrainingProviders.user_id', $ownerId);
        }
        if ($listingId !== null) {
            $query->where('TrainingPrograms.program_id', $listingId);
        }
        if ($limit > 0) {
            $query->limit((int) $limit);
        }

        $rows = $query->get_all();
        foreach ($rows as &$row) {
            $row['kind'] = 'training';
            $row['listing_type'] = '';
            $row['salary'] = '';
            $row['deadline'] = null;
            $row['created_at'] = null;
        }
        unset($row);

        return array_map([$this, 'formatListing'], $rows);
    }

    private function formatListing(array $listing): array
    {
        $listing['id'] = (int) $listing['id'];
        $listing['owner_id'] = (int) $listing['owner_id'];
        $listing['slots'] = (int) $listing['slots'];
        $skills = preg_split('/[,;\r\n]+/', (string) ($listing['skills'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $listing['skills'] = array_values(array_map('trim', $skills));
        return $listing;
    }
}
