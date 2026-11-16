<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Application_model extends Model
{
    protected $table = 'Applications';
    protected $primary_key = 'application_id';
    protected $fillable = [
        'vacancy_id',
        'user_id',
        'application_status',
        'cover_letter',
        'applicant_phone',
        'applicant_location',
        'resume_id',
    ];
    protected $timestamps = false;

    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function getForUser(array $user): array
    {
        $jobs = [];
        $training = [];

        if ($user['role'] === 'student') {
            $jobs = $this->db->table('Applications')
                ->select("Applications.application_id AS id, Applications.application_status AS status,
                        Applications.cover_letter AS note, Applications.applicant_phone, Applications.applicant_location,
                        UploadedDocuments.document_name AS resume_original_name, UploadedDocuments.document_type AS resume_mime,
                        Applications.application_date AS created_at, JobVacancies.vacancy_id AS listing_id, JobVacancies.job_title AS title,
                        Employers.company_name AS organization, JobVacancies.location,
                        Users.first_name AS applicant_first_name, Users.last_name AS applicant_last_name,
                        Users.email AS applicant_email")
                ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
                ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
                ->inner_join('Users', 'Users.user_id = Applications.user_id')
                ->left_join('UploadedDocuments', 'UploadedDocuments.document_id = Applications.resume_id')
                ->where('Applications.user_id', $user['id'])
                ->get_all();

            $training = $this->db->table('TrainingApplications')
                ->select("TrainingApplications.application_id AS id, TrainingApplications.application_status AS status,
                        TrainingApplications.cover_letter AS note, TrainingApplications.applicant_phone, TrainingApplications.applicant_location,
                        TrainingApplications.application_date AS created_at, TrainingPrograms.program_id AS listing_id, TrainingPrograms.program_name AS title,
                        TrainingPrograms.starts AS program_starts, TrainingPrograms.ends AS program_ends,
                        TrainingPrograms.status AS program_status,
                        TrainingProviders.institution_name AS organization, TrainingPrograms.location,
                        Users.first_name AS applicant_first_name, Users.last_name AS applicant_last_name,
                        Users.email AS applicant_email")
                ->inner_join('TrainingPrograms', 'TrainingPrograms.program_id = TrainingApplications.program_id')
                ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
                ->inner_join('Users', 'Users.user_id = TrainingApplications.user_id')
                ->where('TrainingApplications.user_id', $user['id'])
                ->get_all();
        } elseif ($user['role'] === 'employer') {
            $jobs = $this->db->table('Applications')
                ->select("Applications.application_id AS id, Applications.application_status AS status,
                        Applications.cover_letter AS note, Applications.applicant_phone, Applications.applicant_location,
                        UploadedDocuments.document_name AS resume_original_name, UploadedDocuments.document_type AS resume_mime,
                        Applications.application_date AS created_at, JobVacancies.vacancy_id AS listing_id, JobVacancies.job_title AS title,
                        Employers.company_name AS organization, JobVacancies.location,
                        Users.first_name AS applicant_first_name, Users.last_name AS applicant_last_name,
                        Users.email AS applicant_email")
                ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
                ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
                ->inner_join('Users', 'Users.user_id = Applications.user_id')
                ->left_join('UploadedDocuments', 'UploadedDocuments.document_id = Applications.resume_id')
                ->where('Employers.user_id', $user['id'])
                ->get_all();
        } else {
            $training = $this->db->table('TrainingApplications')
                ->select("TrainingApplications.application_id AS id, TrainingApplications.application_status AS status,
                        TrainingApplications.cover_letter AS note, TrainingApplications.applicant_phone, TrainingApplications.applicant_location,
                        TrainingApplications.application_date AS created_at, TrainingPrograms.program_id AS listing_id, TrainingPrograms.program_name AS title,
                        TrainingPrograms.starts AS program_starts, TrainingPrograms.ends AS program_ends,
                        TrainingPrograms.status AS program_status,
                        TrainingProviders.institution_name AS organization, TrainingPrograms.location,
                        Users.first_name AS applicant_first_name, Users.last_name AS applicant_last_name,
                        Users.email AS applicant_email")
                ->inner_join('TrainingPrograms', 'TrainingPrograms.program_id = TrainingApplications.program_id')
                ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
                ->inner_join('Users', 'Users.user_id = TrainingApplications.user_id')
                ->where('TrainingProviders.user_id', $user['id'])
                ->get_all();
        }

        $jobs = array_map(static function (array $row): array {
            $row['kind'] = 'job';
            $row['applicant_name'] = trim($row['applicant_first_name'] . ' ' . $row['applicant_last_name']);
            unset($row['applicant_first_name'], $row['applicant_last_name']);
            return $row;
        }, $jobs);

        $training = array_map(static function (array $row): array {
            $row['kind'] = 'training';
            $row['applicant_name'] = trim($row['applicant_first_name'] . ' ' . $row['applicant_last_name']);
            unset($row['applicant_first_name'], $row['applicant_last_name']);
            return $row;
        }, $training);

        $rows = array_merge($jobs, $training);
        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) $right['created_at'], (string) $left['created_at']);
        });

        return $rows;
    }

    public function countForDashboard(string $role, int $userId = 0): int
    {
        if ($role === 'student') {
            $jobCount = $this->db->table('Applications')
                ->select_count('application_id', 'total')
                ->where('user_id', $userId)
                ->get()['total'] ?? 0;

            $trainingCount = $this->db->table('TrainingApplications')
                ->select_count('application_id', 'total')
                ->where('user_id', $userId)
                ->get()['total'] ?? 0;

            return (int) $jobCount + (int) $trainingCount;
        } elseif ($role === 'employer') {
            $row = $this->db->table('Applications')
                ->select_count('Applications.application_id', 'total')
                ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
                ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
                ->where('Employers.user_id', $userId)
                ->get();

            return (int) ($row['total'] ?? 0);
        } elseif ($role === 'provider') {
            $row = $this->db->table('TrainingApplications')
                ->select_count('TrainingApplications.application_id', 'total')
                ->inner_join('TrainingPrograms', 'TrainingPrograms.program_id = TrainingApplications.program_id')
                ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
                ->where('TrainingProviders.user_id', $userId)
                ->get();

            return (int) ($row['total'] ?? 0);
        } elseif ($role === 'admin') {
            $jobCount = $this->db->table('Applications')
                ->select_count('application_id', 'total')
                ->get()['total'] ?? 0;

            $trainingCount = $this->db->table('TrainingApplications')
                ->select_count('application_id', 'total')
                ->get()['total'] ?? 0;

            return (int) $jobCount + (int) $trainingCount;
        } else {
            throw new InvalidArgumentException('Unsupported role for application count.');
        }
    }

    public function submit(
        string $kind,
        int $userId,
        int $listingId,
        string $note,
        string $phone,
        string $location,
        ?array $resume,
        ?array $cloudinaryAsset
    ): int {
        $table = $this->tableForKind($kind);
        if ($kind === 'training' && $resume !== null) {
            throw new InvalidArgumentException('Training registrations do not accept resume attachments.');
        }
        $listingColumn = $kind === 'job' ? 'vacancy_id' : 'program_id';
        $this->db->transaction();

        try {
            $resumeId = null;
            if ($resume !== null) {
                $filePath = $cloudinaryAsset !== null
                    ? 'cloudinary:' . $cloudinaryAsset['public_id']
                    : ($resume['stored_name'] ?? '');
                if ($filePath === '') {
                    throw new RuntimeException('The uploaded resume has no valid private storage path.');
                }
                $document = [
                    'user_id' => $userId,
                    'document_name' => mb_substr($resume['original_name'], 0, 150),
                    'document_type' => $resume['mime'],
                    'file_path' => $filePath,
                ];
                if ($cloudinaryAsset !== null) {
                    $document += [
                        'cloudinary_url' => $cloudinaryAsset['secure_url'],
                        'cloudinary_public_id' => $cloudinaryAsset['public_id'],
                        'cloudinary_asset_id' => $cloudinaryAsset['asset_id'],
                        'cloudinary_resource_type' => $cloudinaryAsset['resource_type'],
                        'cloudinary_version' => $cloudinaryAsset['version'],
                    ];
                }
                $this->db->table('UploadedDocuments')->insert($document);
                $resumeId = (int) $this->db->last_id();
            }

            $application = [
                $listingColumn => $listingId,
                'user_id' => $userId,
                'application_status' => 'Submitted',
                'cover_letter' => $note,
                'applicant_phone' => $phone,
                'applicant_location' => $location,
            ];
            if ($kind === 'job') $application['resume_id'] = $resumeId;
            $this->db->table($table)->insert($application);
            $applicationId = (int) $this->db->last_id();
            $this->db->commit();
            return $applicationId;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function findForOwner(string $kind, int $applicationId, int $ownerId): ?array
    {
        if ($kind === 'job') {
            $row = $this->db->table('Applications')
                ->select('Applications.user_id, Applications.application_status AS status, JobVacancies.job_title AS title')
                ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
                ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
                ->where('Applications.application_id', $applicationId)
                ->where('Employers.user_id', $ownerId)
                ->get();
        } elseif ($kind === 'training') {
            $row = $this->db->table('TrainingApplications')
                ->select('TrainingApplications.user_id, TrainingApplications.application_status AS status, TrainingPrograms.program_name AS title')
                ->inner_join('TrainingPrograms', 'TrainingPrograms.program_id = TrainingApplications.program_id')
                ->inner_join('TrainingProviders', 'TrainingProviders.provider_id = TrainingPrograms.provider_id')
                ->where('TrainingApplications.application_id', $applicationId)
                ->where('TrainingProviders.user_id', $ownerId)
                ->get();
        } else {
            throw new InvalidArgumentException('Application kind must be job or training.');
        }

        return $row ?: null;
    }

    public function updateStatus(string $kind, int $applicationId, string $status): void
    {
        $table = $this->tableForKind($kind);
        $this->db->table($table)
            ->where('application_id', $applicationId)
            ->update(['application_status' => $status]);
    }

    public function findForApplicant(string $kind, int $applicationId, int $userId): ?array
    {
        $row = $this->db->table($this->tableForKind($kind))
            ->select('application_id, application_status AS status')
            ->where('application_id', $applicationId)
            ->where('user_id', $userId)
            ->get();

        return $row ?: null;
    }

    public function withdrawForApplicant(string $kind, int $applicationId, int $userId): bool
    {
        $table = $this->tableForKind($kind);
        $withdrawableStatuses = $kind === 'training'
            ? ['Submitted', 'Reviewing', 'Accepted', 'Waitlisted']
            : ['Submitted', 'Reviewing', 'Shortlisted', 'Interview'];
        $affected = $this->db->table($table)
            ->where('application_id', $applicationId)
            ->where('user_id', $userId)
            ->in('application_status', $withdrawableStatuses)
            ->update(['application_status' => 'Withdrawn']);

        return $affected > 0;
    }

    public function getResume(string $kind, int $applicationId): ?array
    {
        if ($kind === 'job') {
            $row = $this->db->table('Applications')
                ->select('Applications.user_id, UploadedDocuments.file_path, UploadedDocuments.document_name, UploadedDocuments.document_type,
                           UploadedDocuments.cloudinary_public_id, UploadedDocuments.cloudinary_resource_type, UploadedDocuments.cloudinary_version,
                           Employers.user_id AS owner_id')
                ->inner_join('JobVacancies', 'JobVacancies.vacancy_id = Applications.vacancy_id')
                ->inner_join('Employers', 'Employers.employer_id = JobVacancies.employer_id')
                ->left_join('UploadedDocuments', 'UploadedDocuments.document_id = Applications.resume_id')
                ->where('Applications.application_id', $applicationId)
                ->get();
        } elseif ($kind === 'training') {
            return null;
        } else {
            throw new InvalidArgumentException('Application kind must be job or training.');
        }

        return $row ?: null;
    }

    private function tableForKind(string $kind): string
    {
        if ($kind === 'job') {
            return 'Applications';
        }
        if ($kind === 'training') {
            return 'TrainingApplications';
        }

        throw new InvalidArgumentException('Application kind must be job or training.');
    }
}
