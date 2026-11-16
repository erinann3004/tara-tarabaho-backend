<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Analytics_model extends Model
{
    protected $table = 'Applications';
    protected $primary_key = 'application_id';
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function studentApplicationStatuses(int $userId): array
    {
        return array_merge(
            $this->db->table('Applications')
                ->select('application_status AS status')
                ->select_count('application_id', 'total')
                ->where('user_id', $userId)
                ->group_by('application_status')
                ->get_all(),
            $this->db->table('TrainingApplications')
                ->select('application_status AS status')
                ->select_count('application_id', 'total')
                ->where('user_id', $userId)
                ->group_by('application_status')
                ->get_all()
        );
    }

    public function employerOverview(int $userId): array
    {
        $employer = $this->db->table('Employers')
            ->select('employer_id')
            ->where('user_id', $userId)
            ->get();
        $employerId = (int) ($employer['employer_id'] ?? 0);
        if (!$employerId) {
            return ['total_jobs' => 0, 'active_jobs' => 0, 'closed_jobs' => 0];
        }

        return [
            'total_jobs' => $this->jobCount($employerId),
            'active_jobs' => $this->jobCount($employerId, 'published'),
            'closed_jobs' => $this->jobCount($employerId, 'rejected'),
        ];
    }

    public function employerApplicantCount(int $userId): int
    {
        $row = $this->db->table('Applications')
            ->select_count('Applications.application_id', 'total')
            ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
            ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
            ->where('Employers.user_id', $userId)
            ->get();

        return (int) ($row['total'] ?? 0);
    }

    public function applicationsPerJob(int $userId): array
    {
        return $this->db->table('JobVacancies')
            ->select('JobVacancies.job_title AS label')
            ->select_count('Applications.application_id', 'total')
            ->left_join('Applications', 'Applications.vacancy_id = JobVacancies.vacancy_id')
            ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
            ->where('Employers.user_id', $userId)
            ->group_by(['JobVacancies.vacancy_id', 'JobVacancies.job_title'])
            ->order_by('total', 'DESC')
            ->limit(6)
            ->get_all();
    }

    public function employerApplicationStatuses(int $userId): array
    {
        return $this->db->table('Applications')
            ->select('Applications.application_status AS status')
            ->select_count('Applications.application_id', 'total')
            ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
            ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
            ->where('Employers.user_id', $userId)
            ->group_by('Applications.application_status')
            ->get_all();
    }

    public function providerOverview(int $userId): array
    {
        $provider = $this->db->table('TrainingProviders')
            ->select('provider_id')
            ->where('user_id', $userId)
            ->get();
        $providerId = (int) ($provider['provider_id'] ?? 0);
        if (!$providerId) {
            return ['total_programs' => 0, 'published_programs' => 0];
        }

        return [
            'total_programs' => $this->programCount($providerId),
            'published_programs' => $this->programCount($providerId, 'published'),
        ];
    }

    public function providerApplicantCount(int $userId): int
    {
        $row = $this->db->table('TrainingApplications')
            ->select_count('TrainingApplications.application_id', 'total')
            ->inner_join('TrainingPrograms', 'TrainingPrograms.program_id = TrainingApplications.program_id')
            ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
            ->where('TrainingProviders.user_id', $userId)
            ->get();

        return (int) ($row['total'] ?? 0);
    }

    public function popularPrograms(int $userId): array
    {
        return $this->db->table('TrainingPrograms')
            ->select('TrainingPrograms.program_name AS label')
            ->select_count('TrainingApplications.application_id', 'total')
            ->left_join('TrainingApplications', 'TrainingApplications.program_id = TrainingPrograms.program_id')
            ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
            ->where('TrainingProviders.user_id', $userId)
            ->group_by(['TrainingPrograms.program_id', 'TrainingPrograms.program_name'])
            ->order_by('total', 'DESC')
            ->limit(6)
            ->get_all();
    }

    public function providerApplicationStatuses(int $userId): array
    {
        return $this->db->table('TrainingApplications')
            ->select('TrainingApplications.application_status AS status')
            ->select_count('TrainingApplications.application_id', 'total')
            ->inner_join('TrainingPrograms', 'TrainingPrograms.program_id = TrainingApplications.program_id')
            ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
            ->where('TrainingProviders.user_id', $userId)
            ->group_by('TrainingApplications.application_status')
            ->get_all();
    }

    public function userDistribution(): array
    {
        return $this->db->table('Users')
            ->select('role')
            ->select_count('user_id', 'total')
            ->group_by('role')
            ->order_by('total', 'DESC')
            ->get_all();
    }

    public function jobsByCategory(): array
    {
        $rows = $this->db->table('JobVacancies')
            ->select('category AS label')
            ->select_count('vacancy_id', 'total')
            ->group_by('category')
            ->order_by('total', 'DESC')
            ->limit(7)
            ->get_all();

        foreach ($rows as &$row) {
            $row['label'] = trim((string) ($row['label'] ?? '')) ?: 'Uncategorized';
        }
        unset($row);

        return $rows;
    }

    public function jobSkills(): array
    {
        return $this->db->table('JobVacancies')
            ->select('required_skills')
            ->order_by('vacancy_id', 'DESC')
            ->get_all();
    }

    public function jobListingStatuses(): array
    {
        return $this->db->table('JobVacancies')
            ->select('status')
            ->select_count('vacancy_id', 'total')
            ->group_by('status')
            ->order_by('total', 'DESC')
            ->get_all();
    }

    public function adminApplicationStatuses(): array
    {
        return array_merge(
            $this->db->table('Applications')
                ->select('application_status AS status')
                ->select_count('application_id', 'total')
                ->group_by('application_status')
                ->get_all(),
            $this->db->table('TrainingApplications')
                ->select('application_status AS status')
                ->select_count('application_id', 'total')
                ->group_by('application_status')
                ->get_all()
        );
    }

    private function jobCount(int $employerId, ?string $status = null): int
    {
        $query = $this->db->table('JobVacancies')
            ->select_count('vacancy_id', 'total')
            ->where('employer_id', $employerId);
        if ($status !== null) {
            $query->where('LOWER(status)', $status);
        }

        return (int) (($query->get()['total'] ?? 0));
    }

    private function programCount(int $providerId, ?string $status = null): int
    {
        $query = $this->db->table('TrainingPrograms')
            ->select_count('program_id', 'total')
            ->where('provider_id', $providerId);
        if ($status !== null) {
            $query->where('LOWER(status)', $status);
        }

        return (int) (($query->get()['total'] ?? 0));
    }
}
