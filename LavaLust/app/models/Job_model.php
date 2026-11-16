<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Job_model extends Model
{
    protected $table = 'JobVacancies';
    protected $primary_key = 'vacancy_id';
    protected $fillable = [
        'employer_id',
        'job_title',
        'job_description',
        'required_skills',
        'qualifications',
        'employment_type',
        'salary_range',
        'location',
        'deadline',
        'status',
        'category',
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
        $employerId = null;
        if ($ownerId !== null) {
            $owner = $this->db->table('Employers')
                ->select('employer_id')
                ->where('user_id', $ownerId)
                ->get();
            $employerId = (int) ($owner['employer_id'] ?? 0);
            if (!$employerId) {
                return 0;
            }
        }

        $query = $this->db->table($this->table)->select_count($this->primary_key, 'total');
        if ($employerId !== null) {
            $query->where('employer_id', $employerId);
        }
        if ($status !== null) {
            $query->where('LOWER(status)', strtolower($status));
        }

        $result = $query->get();
        return (int) ($result['total'] ?? 0);
    }

    public function countAvailablePublished(): int
    {
        $result = $this->db->table($this->table)
            ->select_count($this->primary_key, 'total')
            ->where('LOWER(status)', 'published')
            ->grouped(static function ($query): void {
                $query->where_null('deadline')->or_where('deadline', '>=', date('Y-m-d'));
            })
            ->get();

        return (int) ($result['total'] ?? 0);
    }

    public function createForOwner(int $userId, array $listing): ?int
    {
        $owner = $this->db->table('Employers')->select('employer_id')->where('user_id', $userId)->get();
        $employerId = $owner['employer_id'] ?? null;
        if (!$employerId) {
            return null;
        }

        $payload = $this->fillable_attributes(array_merge($listing, [
            'employer_id' => $employerId,
            'status' => 'pending',
        ]));

        $this->db->table($this->table)->insert($payload);

        return (int) $this->db->last_id();
    }

    public function updateForOwner(int $userId, int $listingId, array $listing): ?string
    {
        $owner = $this->db->table('Employers')->select('employer_id')->where('user_id', $userId)->get();
        $employerId = (int) ($owner['employer_id'] ?? 0);
        if (!$employerId) {
            return null;
        }

        $current = $this->db->table($this->table)
            ->select('status')
            ->where($this->primary_key, $listingId)
            ->where('employer_id', $employerId)
            ->get();
        if (!$current) {
            return null;
        }

        $nextStatus = (string) $current['status'];
        $payload = $this->fillable_attributes(array_merge($listing, ['status' => $nextStatus]));
        $this->db->table($this->table)
            ->where($this->primary_key, $listingId)
            ->where('employer_id', $employerId)
            ->update($payload);

        return $nextStatus;
    }

    public function closeForOwner(int $userId, int $listingId): bool
    {
        $owner = $this->db->table('Employers')->select('employer_id')->where('user_id', $userId)->get();
        $employerId = (int) ($owner['employer_id'] ?? 0);
        if (!$employerId) {
            return false;
        }

        $affected = $this->db->table($this->table)
            ->where('vacancy_id', $listingId)
            ->where('employer_id', $employerId)
            ->in('status', ['pending', 'published'])
            ->update(['status' => 'closed']);

        return $affected > 0;
    }

    private function fetchListings(?int $ownerId, ?string $status, int $limit, ?int $listingId = null): array
    {
        $query = $this->db->table('JobVacancies')
            ->select('JobVacancies.vacancy_id AS id, JobVacancies.job_title AS title,
                       Employers.company_name AS organization, JobVacancies.location, JobVacancies.category,
                       JobVacancies.qualifications,
                       JobVacancies.employment_type AS listing_type, JobVacancies.salary_range AS salary,
                       JobVacancies.deadline, JobVacancies.job_description AS description,
                       JobVacancies.required_skills AS skills, JobVacancies.status,
                       Employers.user_id AS owner_id')
            ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
            ->order_by('JobVacancies.vacancy_id', 'DESC');

        if ($status !== null) {
            $query->where('LOWER(JobVacancies.status)', strtolower($status));
        }
        if ($ownerId !== null) {
            $query->where('Employers.user_id', $ownerId);
        }
        if ($listingId !== null) {
            $query->where('JobVacancies.vacancy_id', $listingId);
        }
        if ($status === 'published') {
            $query->grouped(static function ($query): void {
                $query->where_null('JobVacancies.deadline')
                    ->or_where('JobVacancies.deadline', '>=', date('Y-m-d'));
            });
        }
        if ($limit > 0) {
            $query->limit((int) $limit);
        }

        $rows = $query->get_all();
        foreach ($rows as &$row) {
            $row['kind'] = 'job';
            $row['starts'] = null;
            $row['ends'] = null;
            $row['duration'] = null;
            $row['slots'] = 0;
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
        $listing['skills'] = $this->formatSkills($listing['skills'] ?? '');
        return $listing;
    }

    private function formatSkills($skills): array
    {
        $decoded = json_decode((string) $skills, true);
        if (!is_array($decoded)) {
            $decoded = preg_split('/[,;\r\n]+/', (string) $skills, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_map('trim', $decoded));
    }
}
