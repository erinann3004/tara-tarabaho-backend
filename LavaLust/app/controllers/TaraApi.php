<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TaraApi extends Controller
{
    private static $environmentLoaded = false;

    public function me()
    {
        self::run(function () {
            self::startSession();
            $user = self::currentUser(false);
            self::respond(['user' => $user ? self::publicUser($user) : null]);
        });
    }

    public function login()
    {
        self::run(function () {
            self::startSession();
            self::verifyOrigin();
            $input = self::input();
            $email = strtolower(trim(self::stringValue($input['email'] ?? '')));
            $password = self::stringValue($input['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
                self::respond(['error' => 'Enter a valid email and password.'], 422);
            }

            $user = self::findUserByEmail($email);
            if (!$user) {
                self::respond(['error' => 'Email or password is incorrect.'], 401);
            }
            $storedPassword = (string) $user['password_hash'];
            if (!password_verify($password, $storedPassword)) {
                $passwordInfo = password_get_info($storedPassword);
                if ($passwordInfo['algo'] === null && hash_equals($storedPassword, $password)) {
                    lava_instance()->call->model('Account_model')->upgradePassword(
                        (int) $user['id'],
                        password_hash($password, PASSWORD_DEFAULT)
                    );
                } else {
                    self::respond(['error' => 'Email or password is incorrect.'], 401);
                }
            }

            session_regenerate_id(true);
            $_SESSION['tara_user_id'] = (int) $user['id'];
            self::respond(['user' => self::publicUser(self::findUserById((int) $user['id']))]);
        });
    }

    public function register()
    {
        self::run(function () {
            self::startSession();
            self::verifyOrigin();
            $input = self::input();
            $name = trim(self::stringValue($input['name'] ?? ''));
            $email = strtolower(trim(self::stringValue($input['email'] ?? '')));
            $password = self::stringValue($input['password'] ?? '');
            $role = self::stringValue($input['role'] ?? 'student');
            $location = trim(self::stringValue($input['location'] ?? ''));
            $organization = trim(self::stringValue($input['organization'] ?? ''));

            if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
                self::respond(['error' => 'Name must be between 2 and 200 characters.'], 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                self::respond(['error' => 'Enter a valid email address.'], 422);
            }
            if (strlen($password) < 8 || strlen($password) > 200) {
                self::respond(['error' => 'Password must be between 8 and 200 characters.'], 422);
            }
            if (!in_array($role, ['student', 'employer', 'provider'], true)) {
                self::respond(['error' => 'Choose a valid account type.'], 422);
            }
            if (mb_strlen($location) > 160 || mb_strlen($organization) > 150) {
                self::respond(['error' => 'Location or organization is too long.'], 422);
            }
            if ($organization === '' && in_array($role, ['employer', 'provider'], true)) {
                $organization = mb_substr($name, 0, 150);
            }

            $nameParts = preg_split('/\s+/', $name, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
                self::respond(['error' => 'First and last names must each be 100 characters or fewer.'], 422);
            }

            try {
                $roleMap = ['student' => 'job_seeker', 'employer' => 'employer', 'provider' => 'training_provider'];
                $userId = $this->call->model('Account_model')->create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $roleMap[$role],
                    'location' => $location,
                ], $organization);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    self::respond(['error' => 'An account with this email already exists.'], 409);
                }
                throw $exception;
            }

            session_regenerate_id(true);
            $_SESSION['tara_user_id'] = $userId;
            self::respond(['user' => self::publicUser(self::findUserById($userId))], 201);
        });
    }

    public function logout()
    {
        self::run(function () {
            self::startSession();
            self::verifyOrigin();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $parameters = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $parameters['path'],
                    'domain' => $parameters['domain'],
                    'secure' => $parameters['secure'],
                    'httponly' => $parameters['httponly'],
                    'samesite' => $parameters['samesite'] ?? 'Lax'
                ]);
            }
            session_destroy();
            self::respond(['success' => true]);
        });
    }

    public function jobs()
    {
        self::run(function () {
            $listings = $this->call->model('Listing_model');
            self::respond(['items' => $listings->getPublished('job')]);
        });
    }

    public function trainings()
    {
        self::run(function () {
            $listings = $this->call->model('Listing_model');
            self::respond(['items' => $listings->getPublished('training')]);
        });
    }

    public function recommendations()
    {
        self::run(function () {
            $user = self::requireRole(['student']);
            $listings = $this->call->model('Listing_model')->getPublished();
            $recommendations = $this->call->model('Recommendation_model')
                ->recommendBySkills($user['skills'], $listings, 10);

            $recommendations['ai_guidance'] = null;
            $recommendations['ai_guidance_status'] = 'not_configured';
            $openai = $this->call->library('Openai_service');
            if ($openai->isConfigured()) {
                try {
                    $this->call->library('api')->rate_limit('ai-skill-guidance-' . (int) $user['id'], 8, 60);
                    $recommendations['ai_guidance'] = $openai->analyzeSkills($user['skills'], $recommendations);
                    $recommendations['ai_guidance_status'] = $recommendations['ai_guidance'] ? 'available' : 'unavailable';
                } catch (Throwable $exception) {
                    error_log('[TARA-TRABAHO OpenAI] Skills guidance failed: ' . $exception->getMessage());
                    $recommendations['ai_guidance_status'] = 'unavailable';
                }
            }

            self::respond(['recommendations' => $recommendations]);
        });
    }

    public function dashboard()
    {
        self::run(function () {
            $user = self::requireUser();
            $listings = $this->call->model('Listing_model');
            $jobs = $this->call->model('Job_model');
            $trainings = $this->call->model('Training_model');
            $applications = $this->call->model('Application_model');
            $stats = [];

            if ($user['role'] === 'student') {
                $stats['jobs'] = $jobs->countAvailablePublished();
                $stats['trainings'] = $trainings->count(null, 'published');
                $stats['applications'] = $applications->countForDashboard('student', (int) $user['id']);
                $stats['unread'] = $this->call->model('Notification_model')->countUnread((int) $user['id']);
                $recent = $listings->getPublished(null, 4);
            } elseif (in_array($user['role'], ['employer', 'provider'], true)) {
                $kind = $user['role'] === 'employer' ? 'job' : 'training';
                $listingModel = $kind === 'job' ? $jobs : $trainings;
                $stats['listings'] = $listingModel->count((int) $user['id']);
                $stats['pending'] = $listingModel->count((int) $user['id'], 'pending');
                $stats['published'] = $listingModel->count((int) $user['id'], 'published');
                $stats['applications'] = $applications->countForDashboard($kind === 'job' ? 'employer' : 'provider', (int) $user['id']);
                $recent = $listings->getForOwner((int) $user['id'], $kind, 4);
            } else {
                $stats['users'] = $this->call->model('Account_model')->countAll();
                $stats['jobs'] = $jobs->count();
                $stats['trainings'] = $trainings->count();
                $stats['pending'] = $jobs->count(null, 'pending') + $trainings->count(null, 'pending');
                $stats['applications'] = $applications->countForDashboard('admin');
                $recent = $listings->getPending();
            }

            self::respond([
                'stats' => $stats,
                'recent_listings' => $recent,
                'pending_listings' => $user['role'] === 'admin' ? $recent : [],
                'analytics' => self::buildDashboardAnalytics($user)
            ]);
        });
    }

    private static function buildDashboardAnalytics(array $user): array
    {
        $role = $user['role'] ?? '';
        if ($role === 'student') {
            return self::studentAnalytics((int) ($user['id'] ?? 0), $user['skills'] ?? []);
        }
        if ($role === 'employer') {
            return self::employerAnalytics((int) ($user['id'] ?? 0));
        }
        if ($role === 'provider') {
            return self::providerAnalytics((int) ($user['id'] ?? 0));
        }
        if ($role === 'admin') {
            return self::adminAnalytics();
        }

        return [];
    }

    private static function studentAnalytics(int $userId, array $skills): array
    {
        $listings = lava_instance()->call->model('Listing_model')->getPublished();
        $recommendations = lava_instance()->call->model('Recommendation_model')->recommendBySkills($skills, $listings, 50);
        $jobMatches = $recommendations['jobs'] ?? [];
        $matchPercentages = array_values(array_map(static fn (array $job): int => (int) ($job['match_percentage'] ?? 0), $jobMatches));
        $averageMatch = $matchPercentages ? (int) round(array_sum($matchPercentages) / count($matchPercentages)) : 0;
        $analytics = lava_instance()->call->model('Analytics_model');
        $applicationStatus = self::statusChartData($analytics->studentApplicationStatuses($userId));

        return [
            'skill_match' => [
                'available' => true,
                'labels' => ['Average match'],
                'values' => [$averageMatch],
                'message' => null,
            ],
            'application_status' => $applicationStatus,
            'recommended_opportunities' => [
                'jobs' => count($jobMatches),
                'trainings' => count($recommendations['trainings'] ?? []),
            ],
        ];
    }

    private static function employerAnalytics(int $userId): array
    {
        $analytics = lava_instance()->call->model('Analytics_model');
        $overview = $analytics->employerOverview($userId);
        $applicantTotal = $analytics->employerApplicantCount($userId);
        $applicationsPerJob = $analytics->applicationsPerJob($userId);
        $applicationStatus = self::statusChartData($analytics->employerApplicationStatuses($userId));

        return [
            'job_overview' => [
                'published' => (int) ($overview['total_jobs'] ?? 0),
                'applicants' => $applicantTotal,
                'active' => (int) ($overview['active_jobs'] ?? 0),
                'closed' => (int) ($overview['closed_jobs'] ?? 0),
            ],
            'applications_per_job' => [
                'available' => !empty($applicationsPerJob),
                'labels' => array_map(static fn (array $row): string => (string) ($row['label'] ?? 'Unknown'), $applicationsPerJob),
                'values' => array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $applicationsPerJob),
                'message' => empty($applicationsPerJob) ? 'No applications have been received yet for your job vacancies.' : null,
            ],
            'application_status' => $applicationStatus,
        ];
    }

    private static function providerAnalytics(int $userId): array
    {
        $analytics = lava_instance()->call->model('Analytics_model');
        $programSummary = $analytics->providerOverview($userId);
        $applicantTotal = $analytics->providerApplicantCount($userId);
        $popularPrograms = $analytics->popularPrograms($userId);
        $applicationStatus = self::statusChartData($analytics->providerApplicationStatuses($userId));

        return [
            'training_overview' => [
                'total_programs' => (int) ($programSummary['total_programs'] ?? 0),
                'published_programs' => (int) ($programSummary['published_programs'] ?? 0),
                'applicants' => $applicantTotal,
            ],
            'training_applications_status' => $applicationStatus,
            'popular_training_programs' => [
                'available' => !empty($popularPrograms),
                'labels' => array_map(static fn (array $row): string => (string) ($row['label'] ?? 'Unknown'), $popularPrograms),
                'values' => array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $popularPrograms),
                'message' => empty($popularPrograms) ? 'No applications have been submitted for your training programs yet.' : null,
            ],
        ];
    }

    private static function adminAnalytics(): array
    {
        $analytics = lava_instance()->call->model('Analytics_model');
        $userDistribution = $analytics->userDistribution();
        $userDistributionMap = [
            'job_seeker' => 'Job seekers',
            'employer' => 'Employers',
            'training_provider' => 'Training providers',
            'admin' => 'Administrators',
        ];
        $userLabels = [];
        $userValues = [];
        foreach ($userDistributionMap as $roleKey => $label) {
            $total = 0;
            foreach ($userDistribution as $row) {
                if ((string) ($row['role'] ?? '') === $roleKey) {
                    $total = (int) ($row['total'] ?? 0);
                    break;
                }
            }
            $userLabels[] = $label;
            $userValues[] = $total;
        }

        $jobIndustry = $analytics->jobsByCategory();

        $skillsRaw = $analytics->jobSkills();
        $skillsFrequency = [];
        foreach ($skillsRaw as $row) {
            foreach (self::normalizeSkillList($row['required_skills'] ?? '') as $skill) {
                $key = mb_strtolower(trim($skill));
                if ($key === '') {
                    continue;
                }
                $skillsFrequency[$key] = ($skillsFrequency[$key] ?? 0) + 1;
            }
        }
        arsort($skillsFrequency, SORT_NUMERIC);
        $topSkills = array_slice($skillsFrequency, 0, 6, true);

        $applicationStatus = self::statusChartData($analytics->adminApplicationStatuses());

        $jobStatuses = $analytics->jobListingStatuses();
        $jobStatusLabels = [];
        $jobStatusValues = [];
        foreach ($jobStatuses as $row) {
            $rawStatus = strtolower(trim((string) ($row['status'] ?? '')));
            $label = match ($rawStatus) {
                'published', 'approved', 'active', 'open' => 'Published',
                'pending', 'submitted', 'in_review' => 'Pending review',
                'rejected', 'declined' => 'Rejected',
                default => $rawStatus !== '' ? ucfirst($rawStatus) : 'Other',
            };
            $jobStatusLabels[] = $label;
            $jobStatusValues[] = (int) ($row['total'] ?? 0);
        }

        return [
            'user_distribution' => [
                'available' => true,
                'labels' => $userLabels,
                'values' => $userValues,
                'message' => null,
            ],
            'job_vacancies_by_industry' => [
                'available' => !empty($jobIndustry),
                'labels' => array_map(static fn (array $row): string => (string) ($row['label'] ?? 'Unknown'), $jobIndustry),
                'values' => array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $jobIndustry),
                'message' => null,
            ],
            'most_demanded_skills' => [
                'available' => !empty($topSkills),
                'labels' => array_keys($topSkills),
                'values' => array_values($topSkills),
                'message' => null,
            ],
            'application_status' => $applicationStatus,
            'job_vacancy_trend' => [
                'available' => !empty($jobStatusLabels),
                'labels' => $jobStatusLabels,
                'values' => $jobStatusValues,
                'message' => null,
            ],
        ];
    }

    private static function statusChartData(array $rows): array
    {
        $counts = [
            'Pending' => 0,
            'Accepted' => 0,
            'Rejected' => 0,
            'Other' => 0,
        ];

        foreach ($rows as $row) {
            $status = self::statusLabel((string) ($row['status'] ?? ''));
            $total = max(1, (int) ($row['total'] ?? 1));
            if (isset($counts[$status])) {
                $counts[$status] += $total;
            } else {
                $counts['Other'] += $total;
            }
        }

        $labels = ['Pending', 'Accepted', 'Rejected', 'Other'];
        $values = [
            $counts['Pending'],
            $counts['Accepted'],
            $counts['Rejected'],
            $counts['Other'],
        ];

        return [
            'available' => array_sum($values) > 0,
            'labels' => $labels,
            'values' => $values,
            'message' => array_sum($values) === 0 ? 'No application data is available yet.' : null,
        ];
    }

    private static function statusLabel(string $status): string
    {
        $value = strtolower(trim($status));
        if ($value === '' || $value === 'submitted' || $value === 'pending' || $value === 'in_review') {
            return 'Pending';
        }
        if ($value === 'accepted' || $value === 'approved' || $value === 'hired' || $value === 'shortlisted') {
            return 'Accepted';
        }
        if ($value === 'rejected' || $value === 'declined' || $value === 'withdrawn') {
            return 'Rejected';
        }
        return 'Other';
    }

    private static function normalizeSkillList(string $skills): array
    {
        $decoded = json_decode($skills, true);
        if (is_array($decoded)) {
            $flattened = [];
            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $flattened[] = $item;
                }
            }
            $skills = implode(', ', $flattened);
        }

        $parts = preg_split('/[,;\r\n]+/', (string) $skills, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            $label = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? (string) $part);
            if ($label !== '') {
                $normalized[] = $label;
            }
        }

        return $normalized;
    }

    public function applications()
    {
        self::run(function () {
            $user = self::requireUser();
            if ($user['role'] === 'admin') {
                self::respond(['error' => 'This account cannot access applications.'], 403);
            }
            $applications = self::applicationRows($user);
            self::respond(['applications' => $applications]);
        });
    }

    public function apply()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['student']);
            $isMultipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
            $input = $isMultipart ? $_POST : self::input();
            $listingId = filter_var($input['listing_id'] ?? null, FILTER_VALIDATE_INT);
            $kind = self::stringValue($input['kind'] ?? 'job');
            $note = trim(self::stringValue($input['note'] ?? ''));
            $phone = trim(self::stringValue($input['phone'] ?? ''));
            $location = trim(self::stringValue($input['location'] ?? ''));
            if (!$listingId || !in_array($kind, ['job', 'training'], true) || mb_strlen($note) > 3000 || mb_strlen($phone) > 40 || mb_strlen($location) > 160) {
                self::respond(['error' => 'Check the application details and keep your cover letter under 3,000 characters.'], 422);
            }
            if ($kind === 'training' && isset($_FILES['resume']) && ($_FILES['resume']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                self::respond(['error' => 'Training registrations do not accept resume attachments.'], 422);
            }

            $listings = $this->call->model('Listing_model');
            $listing = $listings->getById((int) $listingId, $kind, 'published');
            if (!$listing) {
                self::respond(['error' => 'This opportunity is no longer available.'], 404);
            }
            if ($kind === 'job' && $listing['deadline'] && $listing['deadline'] < date('Y-m-d')) {
                self::respond(['error' => 'The application deadline has passed.'], 409);
            }
            if ($kind === 'job' && (
                !preg_match('/^[0-9+().\-\s]{7,40}$/D', $phone) ||
                mb_strlen($location) < 2
            )) {
                self::respond(['error' => 'Enter a valid phone number and current location before applying.'], 422);
            }
            if ($kind === 'training' && $isMultipart && (
                !preg_match('/^[0-9+().\-\s]{7,40}$/D', $phone) ||
                mb_strlen($location) < 2
            )) {
                self::respond(['error' => 'Enter a valid phone number and current location to register.'], 422);
            }

            $resume = null;
            if ($kind === 'job' && (!$isMultipart || !isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE)) {
                self::respond(['error' => 'Upload your resume to apply for this job.'], 422);
            }
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE) {
                $resume = self::validateResumeUpload($_FILES['resume']);
            }

            $cloudinaryAsset = null;
            $localResumeName = null;
            try {
                if ($resume !== null) {
                    $cloudinary = $this->call->library('Cloudinary_service');
                    if ($cloudinary->isConfigured()) {
                        $publicId = 'tara-private/' . (int) $user['id'] . '-' . bin2hex(random_bytes(16)) . '.' . $resume['extension'];
                        try {
                            $cloudinaryAsset = $cloudinary->upload(
                                $resume['temporary_name'],
                                $publicId,
                                'raw',
                                'authenticated'
                            );
                        } catch (Throwable $exception) {
                            error_log('[TARA-TRABAHO Cloudinary] Resume upload failed; saving privately on this server: ' . $exception->getMessage());
                        }
                    }
                    if ($cloudinaryAsset === null) {
                        $localResumeName = self::storePrivateResume($resume);
                        $resume['stored_name'] = $localResumeName;
                    }
                }
                $applicationId = $this->call->model('Application_model')->submit(
                    $kind,
                    (int) $user['id'],
                    (int) $listingId,
                    $note,
                    $phone,
                    $location,
                    $resume,
                    $cloudinaryAsset
                );
            } catch (PDOException $exception) {
                if ($cloudinaryAsset !== null) {
                    try {
                        $this->call->library('Cloudinary_service')->delete(
                            $cloudinaryAsset['public_id'],
                            'raw',
                            'authenticated'
                        );
                    } catch (Throwable $cleanupException) {
                        error_log('[TARA-TRABAHO Cloudinary] Could not remove an unlinked upload: ' . $cleanupException->getMessage());
                    }
                }
                if ($localResumeName !== null) self::deletePrivateResume($localResumeName);
                if ($exception->getCode() === '23000') {
                    self::respond(['error' => 'You already applied to this opportunity.'], 409);
                }
                throw $exception;
            } catch (Throwable $exception) {
                if ($cloudinaryAsset !== null) {
                    try {
                        $this->call->library('Cloudinary_service')->delete(
                            $cloudinaryAsset['public_id'],
                            'raw',
                            'authenticated'
                        );
                    } catch (Throwable $cleanupException) {
                        error_log('[TARA-TRABAHO Cloudinary] Could not remove an unlinked upload: ' . $cleanupException->getMessage());
                    }
                }
                if ($localResumeName !== null) self::deletePrivateResume($localResumeName);
                throw $exception;
            }

            $kindLabel = $kind === 'training' ? 'training registration' : 'job application';
            $details = $user['name'] . ' submitted a ' . $kindLabel . ' for ' . $listing['title'] . '.';
            try {
                $applicationUrl = '/applications?application_id=' . $applicationId . '&kind=' . rawurlencode($kind);
                self::addNotification((int) $listing['owner_id'], null, 'application', 'New application received', $details, $applicationUrl);
            } catch (Throwable $exception) {
                error_log('[TARA-TRABAHO notifications] Could not create the new-application alert: ' . $exception->getMessage());
            }
            try {
                self::emailUsers([(int) $listing['owner_id']], 'New application received', $details, '/applications');
            } catch (Throwable $exception) {
                error_log('[TARA-TRABAHO notifications] Could not send the new-application email: ' . $exception->getMessage());
            }
            self::respond(['success' => true], 201);
        });
    }

    public function downloadResume()
    {
        self::run(function () {
            $user = self::requireUser();
            $applicationId = filter_var($_GET['application_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$applicationId) self::respond(['error' => 'A valid application is required.'], 422);
            $kind = self::stringValue($_GET['kind'] ?? 'job');
            if (!in_array($kind, ['job', 'training'], true)) self::respond(['error' => 'Choose a valid application type.'], 422);
            if ($kind === 'training') self::respond(['error' => 'Training registrations do not have attachments.'], 404);
            $applications = $this->call->model('Application_model');
            $application = $applications->getResume($kind, (int) $applicationId);
            if (!$application || !$application['file_path']) self::respond(['error' => 'Resume not found.'], 404);
            $isApplicant = $user['role'] === 'student' && (int) $application['user_id'] === (int) $user['id'];
            $isListingOwner = in_array($user['role'], ['employer', 'provider'], true) && (int) $application['owner_id'] === (int) $user['id'];
            if (!$isApplicant && !$isListingOwner) self::respond(['error' => 'You do not have permission to access this resume.'], 403);

            if (!empty($application['cloudinary_public_id'])) {
                try {
                    $contents = $this->call->library('Cloudinary_service')->downloadAuthenticated(
                        (string) $application['cloudinary_public_id'],
                        (string) ($application['cloudinary_resource_type'] ?: 'raw'),
                        (int) $application['cloudinary_version']
                    );
                } catch (Throwable $exception) {
                    error_log('[TARA-TRABAHO Cloudinary] Private document download failed: ' . $exception->getMessage());
                    self::respond(['error' => 'The private document could not be retrieved. Please try again later.'], 502);
                }
                if (strlen($contents) > 2 * 1024 * 1024) {
                    self::respond(['error' => 'The stored document exceeds the allowed size.'], 502);
                }
                $downloadName = preg_replace('/[\r\n"]/', '', (string) $application['document_name']);
                $isPdf = $application['document_type'] === 'application/pdf';
                $preview = isset($_GET['preview']) && $_GET['preview'] === '1' && $isPdf;
                header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
                header('Content-Length: ' . strlen($contents));
                header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="resume"; filename*=UTF-8\'\'' . rawurlencode($downloadName ?: 'resume'));
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                if ($preview) {
                    header_remove('X-Frame-Options');
                    header("Content-Security-Policy: frame-ancestors 'self'");
                }
                echo $contents;
                exit;
            }

            $storageName = basename((string) $application['file_path']);
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private-resumes' . DIRECTORY_SEPARATOR . $storageName;
            if (!is_file($path)) self::respond(['error' => 'The stored resume is no longer available.'], 404);

            $downloadName = preg_replace('/[\r\n"]/', '', (string) $application['document_name']);
            $isPdf = $application['document_type'] === 'application/pdf';
            $preview = isset($_GET['preview']) && $_GET['preview'] === '1' && $isPdf;
            header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="resume"; filename*=UTF-8\'\'' . rawurlencode($downloadName ?: 'resume'));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            if ($preview) {
                header_remove('X-Frame-Options');
                header("Content-Security-Policy: frame-ancestors 'self'");
            }
            readfile($path);
            exit;
        });
    }

    public function updateApplication()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['employer', 'provider']);
            $input = self::input();
            $applicationId = filter_var($input['application_id'] ?? null, FILTER_VALIDATE_INT);
            $status = self::stringValue($input['status'] ?? '');
            $kind = self::stringValue($input['kind'] ?? ($user['role'] === 'employer' ? 'job' : 'training'));
            $allowedStatuses = $kind === 'training'
                ? ['Submitted', 'Reviewing', 'Accepted', 'Waitlisted', 'Rejected', 'Completed']
                : ['Submitted', 'Reviewing', 'Shortlisted', 'Interview', 'Hired', 'Rejected'];
            if (!$applicationId || !in_array($status, $allowedStatuses, true) || !in_array($kind, ['job', 'training'], true)) {
                self::respond(['error' => 'Choose a valid application status.'], 422);
            }

            if (($user['role'] === 'employer' && $kind !== 'job') || ($user['role'] === 'provider' && $kind !== 'training')) {
                self::respond(['error' => 'You cannot update this application.'], 403);
            }
            $applications = $this->call->model('Application_model');
            $application = $applications->findForOwner($kind, (int) $applicationId, (int) $user['id']);
            if (!$application) self::respond(['error' => 'Application not found.'], 404);
            if ($application['status'] !== $status) {
                $applications->updateStatus($kind, (int) $applicationId, $status);
                $details = 'Your ' . ($kind === 'training' ? 'training registration' : 'application') . ' for ' . $application['title'] . ' is now ' . $status . '.';
                $applicationUrl = '/applications?application_id=' . (int) $applicationId . '&kind=' . rawurlencode($kind);
                self::addNotification((int) $application['user_id'], null, 'application', 'Application status updated', $details, $applicationUrl);
                self::emailUsers([(int) $application['user_id']], 'Application status updated', $details, $applicationUrl);
                self::pushUsers([(int) $application['user_id']], 'application-' . (int) $applicationId, 'Application status updated', $details, $applicationUrl);
            }
            self::respond(['success' => true]);
        });
    }

    public function withdrawApplication()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['student']);
            $input = self::input();
            $applicationId = filter_var($input['application_id'] ?? null, FILTER_VALIDATE_INT);
            $kind = self::stringValue($input['kind'] ?? 'job');
            if (!$applicationId || !in_array($kind, ['job', 'training'], true)) {
                self::respond(['error' => 'Choose a valid application.'], 422);
            }

            $applications = $this->call->model('Application_model');
            $application = $applications->findForApplicant($kind, (int) $applicationId, (int) $user['id']);
            if (!$application) self::respond(['error' => 'Application not found.'], 404);
            $withdrawableStatuses = $kind === 'training'
                ? ['Submitted', 'Reviewing', 'Accepted', 'Waitlisted']
                : ['Submitted', 'Reviewing', 'Shortlisted', 'Interview'];
            if (!in_array($application['status'], $withdrawableStatuses, true)) {
                self::respond(['error' => 'Only active applications can be withdrawn.'], 409);
            }
            if (!$applications->withdrawForApplicant($kind, (int) $applicationId, (int) $user['id'])) {
                self::respond(['error' => 'The application status changed. Refresh and try again.'], 409);
            }

            self::respond(['success' => true, 'status' => 'Withdrawn']);
        });
    }

    public function notifications()
    {
        self::run(function () {
            $user = self::requireUser();
            $notifications = $this->call->model('Notification_model');
            self::respond(['notifications' => $notifications->getForUser((int) $user['id'])]);
        });
    }

    public function markNotificationRead()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireUser();
            $id = filter_var(self::input()['notification_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) self::respond(['error' => 'A valid notification is required.'], 422);
            $notifications = $this->call->model('Notification_model');
            $notifications->markRead((int) $id, (int) $user['id']);
            self::respond(['success' => true]);
        });
    }

    public function markAllNotificationsRead()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireUser();
            $notifications = $this->call->model('Notification_model');
            $notifications->markAllRead((int) $user['id']);
            self::respond(['success' => true]);
        });
    }

    public function updateNotificationPreferences()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireUser();
            $input = self::input();
            if (!array_key_exists('email_alerts', $input) || !is_bool($input['email_alerts'])) {
                self::respond(['error' => 'Choose whether email alerts are enabled.'], 422);
            }
            $this->call->model('Account_model')->updateEmailAlerts((int) $user['id'], $input['email_alerts']);
            self::respond(['email_alerts' => $input['email_alerts']]);
        });
    }

    public function updateProfile()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireUser();
            $input = self::input();
            $updates = [];

            if (array_key_exists('name', $input)) {
                $name = trim(self::stringValue($input['name']));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
                    self::respond(['error' => 'Name must be between 2 and 200 characters.'], 422);
                }
                $parts = preg_split('/\s+/', $name, 2);
                $firstName = $parts[0] ?? '';
                $lastName = $parts[1] ?? '';
                if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
                    self::respond(['error' => 'First and last names must each be 100 characters or fewer.'], 422);
                }
                $updates['first_name'] = $firstName;
                $updates['last_name'] = $lastName;
            }
            if (array_key_exists('location', $input)) {
                $location = trim(self::stringValue($input['location']));
                if (mb_strlen($location) > 160) self::respond(['error' => 'Location must be 160 characters or fewer.'], 422);
                $updates['location'] = $location;
            }
            if (array_key_exists('qualifications', $input)) {
                if ($user['role'] !== 'student') self::respond(['error' => 'Only job seekers can edit qualifications.'], 403);
                $qualifications = trim(self::stringValue($input['qualifications']));
                if (mb_strlen($qualifications) > 2000) {
                    self::respond(['error' => 'Qualifications must be 2,000 characters or fewer.'], 422);
                }
                $updates['qualifications'] = $qualifications;
            }
            if (array_key_exists('experience', $input)) {
                if ($user['role'] !== 'student') self::respond(['error' => 'Only job seekers can edit experience.'], 403);
                $experience = trim(self::stringValue($input['experience']));
                if (mb_strlen($experience) > 5000) {
                    self::respond(['error' => 'Experience must be 5,000 characters or fewer.'], 422);
                }
                $updates['experience'] = $experience;
            }
            if (array_key_exists('organization', $input)) {
                if (!in_array($user['role'], ['employer', 'provider'], true)) {
                    self::respond(['error' => 'Only organization accounts can edit organization details.'], 403);
                }
                $organization = trim(self::stringValue($input['organization']));
                if (mb_strlen($organization) < 2 || mb_strlen($organization) > 150) {
                    self::respond(['error' => 'Organization must be between 2 and 150 characters.'], 422);
                }
                $this->call->model('Account_model')->updateOrganization(
                    (int) $user['id'],
                    $user['role'],
                    $organization
                );
                $organizationUpdated = true;
            }
            if (array_key_exists('skills', $input)) {
                if ($user['role'] !== 'student') self::respond(['error' => 'Only job seeker accounts can edit skills.'], 403);
                $skills = self::validateSkills($input['skills'], 50, 80);
                $this->call->model('Skills_model')->replaceForUser((int) $user['id'], $skills);
            }
            if (!$updates && !isset($organizationUpdated) && !isset($skills)) self::respond(['error' => 'No profile changes were provided.'], 422);

            if ($updates) {
                $this->call->model('Account_model')->updateProfile((int) $user['id'], $updates);
            }
            self::respond(['user' => self::publicUser(self::findUserById((int) $user['id']))]);
        });
    }

    public function updateProfilePhoto()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireUser();
            $photo = self::validateProfilePhotoUpload($_FILES['photo'] ?? []);
            $cloudinary = $this->call->library('Cloudinary_service');
            if (!$cloudinary->isConfigured()) {
                self::respond(['error' => 'Profile photo uploads are not configured on the server.'], 503);
            }

            $publicId = 'tara-profiles/' . (int) $user['id'] . '-' . bin2hex(random_bytes(16));
            $asset = $cloudinary->upload($photo['temporary_name'], $publicId, 'image', 'upload');
            try {
                $oldPublicId = $this->call->model('Account_model')->updateProfilePhoto(
                    (int) $user['id'],
                    $asset['secure_url'],
                    $asset['public_id']
                );
            } catch (Throwable $exception) {
                try {
                    $cloudinary->delete($asset['public_id'], 'image', 'upload');
                } catch (Throwable $cleanupException) {
                    error_log('[TARA-TRABAHO Cloudinary] Could not remove an unlinked profile image: ' . $cleanupException->getMessage());
                }
                throw $exception;
            }
            if ($oldPublicId !== null) {
                try {
                    $cloudinary->delete($oldPublicId, 'image', 'upload');
                } catch (Throwable $exception) {
                    error_log('[TARA-TRABAHO Cloudinary] Could not remove an old profile image: ' . $exception->getMessage());
                }
            }

            self::respond(['user' => self::publicUser(self::findUserById((int) $user['id']))]);
        });
    }

    public function createListing()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['employer', 'provider']);
            $input = self::input();
            $kind = $user['role'] === 'employer' ? 'job' : 'training';
            $title = trim(self::stringValue($input['title'] ?? ''));
            $location = trim(self::stringValue($input['location'] ?? ''));
            $category = trim(self::stringValue($input['category'] ?? ''));
            $description = trim(self::stringValue($input['description'] ?? ''));
            $skills = self::validateSkills($input['skills'] ?? [], 30, 80);
            if (mb_strlen($title) < 3 || mb_strlen($title) > 150 || mb_strlen($location) < 2 || mb_strlen($location) > 255) {
                self::respond(['error' => 'Enter a valid listing title and location.'], 422);
            }
            if (mb_strlen($description) < 25 || mb_strlen($description) > 10000 || mb_strlen($category) > 100) {
                self::respond(['error' => 'Add a description of at least 25 characters and a category under 100 characters.'], 422);
            }
            $listingType = trim(self::stringValue($input['listing_type'] ?? ''));
            $salary = trim(self::stringValue($input['salary'] ?? ''));
            $duration = trim(self::stringValue($input['duration'] ?? ''));
            if (mb_strlen($listingType) > 50 || mb_strlen($salary) > 100 || mb_strlen($duration) > 100) {
                self::respond(['error' => 'Listing type, salary, or duration is too long.'], 422);
            }

            $deadline = self::optionalDate($input['deadline'] ?? null, 'application deadline');
            $starts = self::optionalDate($input['starts'] ?? null, 'start date');
            $ends = self::optionalDate($input['ends'] ?? null, 'end date');
            $slots = filter_var($input['slots'] ?? 0, FILTER_VALIDATE_INT);
            if ($kind === 'training' && (!$starts || !$slots || $slots < 1 || $slots > 10000)) {
                self::respond(['error' => 'Training programs need a start date and between 1 and 10,000 slots.'], 422);
            }
            if ($starts && $ends && $ends < $starts) {
                self::respond(['error' => 'The end date cannot be before the start date.'], 422);
            }

            if ($kind === 'job') {
                $job = $this->call->model('Job_model');
                $listingId = $job->createForOwner((int) $user['id'], [
                    'job_title' => $title,
                    'job_description' => $description,
                    'required_skills' => implode(', ', $skills),
                    'qualifications' => $category,
                    'employment_type' => $listingType,
                    'salary_range' => $salary,
                    'location' => $location,
                    'deadline' => $deadline,
                    'category' => $category,
                ]);
                if ($listingId === null) self::respond(['error' => 'Employer profile not found.'], 409);
            } else {
                $schedule = trim(self::stringValue($input['schedule'] ?? $starts));
                if (mb_strlen($schedule) > 100) self::respond(['error' => 'Schedule must be 100 characters or fewer.'], 422);
                $training = $this->call->model('Training_model');
                $listingId = $training->createForOwner((int) $user['id'], [
                    'program_name' => $title,
                    'description' => $description,
                    'skills_covered' => implode(', ', $skills),
                    'duration' => $duration,
                    'schedule' => $schedule,
                    'location' => $location,
                    'category' => $category,
                    'slots_available' => $slots,
                    'starts' => $starts,
                    'ends' => $ends,
                ]);
                if ($listingId === null) self::respond(['error' => 'Training provider profile not found.'], 409);
            }
            self::respond(['success' => true, 'id' => $listingId, 'status' => 'pending'], 201);
        });
    }

    public function myListings()
    {
        self::run(function () {
            $user = self::requireRole(['employer', 'provider']);
            $kind = $user['role'] === 'employer' ? 'job' : 'training';
            $listings = $this->call->model('Listing_model');
            self::respond(['items' => $listings->getForOwner((int) $user['id'], $kind)]);
        });
    }

    public function updateJobListing()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['employer']);
            $input = self::input();
            $listingId = filter_var($input['listing_id'] ?? null, FILTER_VALIDATE_INT);
            $title = trim(self::stringValue($input['title'] ?? ''));
            $location = trim(self::stringValue($input['location'] ?? ''));
            $category = trim(self::stringValue($input['category'] ?? ''));
            $description = trim(self::stringValue($input['description'] ?? ''));
            $skills = self::validateSkills($input['skills'] ?? [], 30, 80);
            $listingType = trim(self::stringValue($input['listing_type'] ?? ''));
            $salary = trim(self::stringValue($input['salary'] ?? ''));
            if (!$listingId || mb_strlen($title) < 3 || mb_strlen($title) > 150 || mb_strlen($location) < 2 || mb_strlen($location) > 255) {
                self::respond(['error' => 'Enter a valid listing and job title/location.'], 422);
            }
            if (mb_strlen($description) < 25 || mb_strlen($description) > 10000 || mb_strlen($category) > 100) {
                self::respond(['error' => 'Add a description of at least 25 characters and a category under 100 characters.'], 422);
            }
            if (mb_strlen($listingType) > 50 || mb_strlen($salary) > 100) {
                self::respond(['error' => 'Employment type or salary range is too long.'], 422);
            }

            $deadline = self::optionalDate($input['deadline'] ?? null, 'application deadline');
            $status = $this->call->model('Job_model')->updateForOwner((int) $user['id'], (int) $listingId, [
                'job_title' => $title,
                'job_description' => $description,
                'required_skills' => implode(', ', $skills),
                'qualifications' => $category,
                'employment_type' => $listingType,
                'salary_range' => $salary,
                'location' => $location,
                'deadline' => $deadline,
                'category' => $category,
            ]);
            if ($status === null) self::respond(['error' => 'Job listing not found.'], 404);

            self::respond(['success' => true, 'status' => $status]);
        });
    }

    public function closeJobListing()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['employer']);
            $listingId = filter_var(self::input()['listing_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$listingId) self::respond(['error' => 'Choose a valid job listing.'], 422);

            $closed = $this->call->model('Job_model')->closeForOwner((int) $user['id'], (int) $listingId);
            if (!$closed) self::respond(['error' => 'This listing cannot be closed or does not belong to your account.'], 409);

            self::respond(['success' => true, 'status' => 'closed']);
        });
    }

    public function closeTrainingListing()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['provider']);
            $listingId = filter_var(self::input()['listing_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$listingId) self::respond(['error' => 'Choose a valid training program.'], 422);

            $closed = $this->call->model('Training_model')->closeForOwner((int) $user['id'], (int) $listingId);
            if (!$closed) self::respond(['error' => 'This program cannot be marked completed or does not belong to your account.'], 409);

            self::respond(['success' => true, 'status' => 'closed']);
        });
    }

    public function moderateListing()
    {
        self::run(function () {
            self::verifyOrigin();
            self::requireRole(['admin']);
            $input = self::input();
            $listingId = filter_var($input['listing_id'] ?? null, FILTER_VALIDATE_INT);
            $status = self::stringValue($input['status'] ?? '');
            $kind = self::stringValue($input['kind'] ?? '');
            if (!$listingId || !in_array($status, ['published', 'rejected'], true)) {
                self::respond(['error' => 'Choose a valid listing and moderation status.'], 422);
            }

            if ($kind === '') {
                $listings = $this->call->model('Listing_model');
                $job = $listings->getById((int) $listingId, 'job', 'pending');
                $training = $listings->getById((int) $listingId, 'training', 'pending');
                if ($job && $training) self::respond(['error' => 'Specify whether this is a job or training listing.'], 422);
                $kind = $job ? 'job' : 'training';
            }
            if (!in_array($kind, ['job', 'training'], true)) {
                self::respond(['error' => 'Choose a valid listing type.'], 422);
            }
            $listings = $this->call->model('Listing_model');
            $currentListing = $listings->getById((int) $listingId, $kind);
            if (!$currentListing) {
                self::respond(['error' => 'Listing not found.'], 404);
            }
            $currentStatus = strtolower((string) ($currentListing['status'] ?? ''));
            if ($currentStatus === $status) {
                self::respond(['success' => true, 'status' => $status, 'already_moderated' => true]);
            }
            if ($currentStatus !== 'pending') {
                self::respond(['error' => 'This listing is no longer awaiting review.'], 409);
            }
            $db = self::db();
            $db->transaction();
            try {
                $listing = $listings->moderatePending(
                    (int) $listingId,
                    $kind,
                    $status
                );
                if (!$listing) {
                    $db->roll_back();
                    self::respond(['error' => 'This listing is no longer awaiting review.'], 409);
                }
                $students = [];
                if ($status === 'published') {
                    $students = $this->call->model('Account_model')->getJobSeekers();
                    $title = $kind === 'training' ? 'New training program' : 'New job opportunity';
                    $listingTitle = $kind === 'training' ? $listing['program_name'] : $listing['job_title'];
                    $details = $listingTitle . ' is now available in ' . $listing['location'] . '.';
                    $url = ($kind === 'training' ? '/training' : '/jobs') . '?listing_id=' . (int) $listingId;
                    $this->call->model('Notification_model')->createForUsers(
                        array_column($students, 'id'),
                        $kind,
                        $title,
                        $details,
                        $url
                    );
                }
                $db->commit();
            } catch (Throwable $exception) {
                $db->roll_back();
                throw $exception;
            }

            self::respond([
                'success' => true,
                'status' => $status,
                'delivery' => ['in_app' => count($students), 'email' => 0, 'push' => 0],
                'alerts_pending' => $status === 'published' && (bool) $students,
            ]);
        });
    }

    public function dispatchListingAlerts()
    {
        self::run(function () {
            self::verifyOrigin();
            self::requireRole(['admin']);
            $input = self::input();
            $listingId = filter_var($input['listing_id'] ?? null, FILTER_VALIDATE_INT);
            $kind = self::stringValue($input['kind'] ?? '');
            if (!$listingId || !in_array($kind, ['job', 'training'], true)) {
                self::respond(['error' => 'Choose a valid published listing.'], 422);
            }

            $listing = $this->call->model('Listing_model')->getById((int) $listingId, $kind, 'published');
            if (!$listing) {
                self::respond(['error' => 'Published listing not found.'], 404);
            }

            $students = $this->call->model('Account_model')->getJobSeekers();
            $studentIds = array_column($students, 'id');
            if (!$studentIds) {
                self::respond(['success' => true, 'delivery' => ['email' => 0, 'push' => 0]]);
            }

            $title = $kind === 'training' ? 'New training program' : 'New job opportunity';
            $details = $listing['title'] . ' is now available in ' . $listing['location'] . '.';
            $url = ($kind === 'training' ? '/training' : '/jobs') . '?listing_id=' . (int) $listingId;
            $emailCount = self::emailUsers($studentIds, $title, $details, $url);
            $pushCount = self::pushUsers(
                $studentIds,
                'listing-' . $kind . '-' . (int) $listingId,
                $title,
                $details,
                $url
            );

            self::respond(['success' => true, 'delivery' => ['email' => $emailCount, 'push' => $pushCount]]);
        });
    }

    public function pushKey()
    {
        self::run(function () {
            self::respond(['public_key' => self::env('VAPID_PUBLIC_KEY')]);
        });
    }

    public function savePushSubscription()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['student']);
            $input = self::input();
            $endpoint = self::stringValue($input['endpoint'] ?? '');
            $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
            $publicKey = self::stringValue($keys['p256dh'] ?? '');
            $authToken = self::stringValue($keys['auth'] ?? '');
            $endpointParts = parse_url($endpoint);
            if (
                !filter_var($endpoint, FILTER_VALIDATE_URL) ||
                !is_array($endpointParts) ||
                strtolower($endpointParts['scheme'] ?? '') !== 'https' ||
                strlen($endpoint) > 2048 ||
                strlen($publicKey) > 255 ||
                strlen($authToken) > 255 ||
                !$publicKey ||
                !$authToken
            ) {
                self::respond(['error' => 'The browser returned an invalid push subscription.'], 422);
            }
            $this->call->model('Notification_model')->savePushSubscription(
                (int) $user['id'],
                $endpoint,
                $publicKey,
                $authToken
            );
            self::respond(['success' => true], 201);
        });
    }

    public function removePushSubscription()
    {
        self::run(function () {
            self::verifyOrigin();
            $user = self::requireRole(['student']);
            $endpoint = self::stringValue(self::input()['endpoint'] ?? '');
            if (!$endpoint || strlen($endpoint) > 2048) {
                self::respond(['error' => 'A valid push endpoint is required.'], 422);
            }
            $this->call->model('Notification_model')->removePushSubscription((int) $user['id'], $endpoint);
            self::respond(['success' => true]);
        });
    }

    private static function run(callable $action): void
    {
        $GLOBALS['TARA_API_REQUEST'] = true;
        try {
            $action();
        } catch (Throwable $exception) {
            unset($GLOBALS['TARA_API_REQUEST']);
            error_log(
                '[TARA-TRABAHO API] ' . get_class($exception) . ': ' . $exception->getMessage() .
                ' at ' . $exception->getFile() . ':' . $exception->getLine()
            );
            self::respond(['error' => 'The request could not be completed. Check the server configuration and try again.'], 500);
        }
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $forwardedScheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
        $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedScheme === 'https';
        $sameSite = self::env('SESSION_COOKIE_SAMESITE') ?: ($secureCookie ? 'None' : 'Lax');
        if (strcasecmp($sameSite, 'None') === 0 && !$secureCookie) {
            $sameSite = 'Lax';
        }
        session_name('TaraTrabahoSession');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        session_start();
    }

    private static function verifyOrigin(): void
    {
        self::api();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') return;

        $allowedOrigins = function_exists('config_item') ? config_item('allow_origin') : [];
        if (is_string($allowedOrigins)) $allowedOrigins = [$allowedOrigins];
        $normalizedAllowed = array_map(static fn ($allowedOrigin) => rtrim((string) $allowedOrigin, '/'), (array) $allowedOrigins);
        if (in_array(rtrim($origin, '/'), $normalizedAllowed, true)) return;

        $originParts = parse_url($origin);
        $requestScheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $requestParts = parse_url($requestScheme . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
        if (
            !is_array($originParts) ||
            !is_array($requestParts) ||
            strtolower($originParts['scheme'] ?? '') !== $requestScheme ||
            strcasecmp($originParts['host'] ?? '', $requestParts['host'] ?? '') !== 0 ||
            (int) ($originParts['port'] ?? ($requestScheme === 'https' ? 443 : 80)) !==
                (int) ($requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80))
        ) {
            self::respond(['error' => 'The request origin is not allowed.'], 403);
        }
    }

    private static function input(): array
    {
        return self::api()->body();
    }

    private static function respond(array $body, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        self::api()->respond($body, $status);
    }

    private static function api()
    {
        $lava = lava_instance();
        $lava->call->library('api');
        return $lava->api;
    }

    private static function db()
    {
        return lava_instance()->call->database();
    }

    private static function env(string $name): string
    {
        if (!self::$environmentLoaded) {
            self::$environmentLoaded = true;
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) throw new RuntimeException('Could not read the application environment file.');
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    if (
                        $key === '' ||
                        array_key_exists($key, $_ENV) ||
                        array_key_exists($key, $_SERVER) ||
                        getenv($key) !== false
                    ) continue;
                    $value = trim($value);
                    if (strlen($value) > 1 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                        $value = substr($value, 1, -1);
                    }
                    putenv($key . '=' . $value);
                }
            }
        }
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return $value === false ? '' : trim((string) $value);
    }

    private static function currentUser(bool $required = true): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) self::startSession();
        $userId = filter_var($_SESSION['tara_user_id'] ?? null, FILTER_VALIDATE_INT);
        // Release PHP's session lock before database work so other requests
        // from the same browser can proceed while this user lookup runs.
        session_write_close();
        if (!$userId) {
            if ($required) self::respond(['error' => 'Please sign in to continue.'], 401);
            return null;
        }
        $user = self::findUserById((int) $userId);
        if (!$user) {
            self::startSession();
            if ((int) ($_SESSION['tara_user_id'] ?? 0) === (int) $userId) {
                unset($_SESSION['tara_user_id']);
            }
            session_write_close();
            if ($required) self::respond(['error' => 'Your account is no longer available. Please sign in again.'], 401);
            return null;
        }
        return $user;
    }

    private static function findUserById(int $userId): ?array
    {
        return lava_instance()->call->model('Account_model')->findById($userId);
    }

    private static function findUserByEmail(string $email): ?array
    {
        return lava_instance()->call->model('Account_model')->findByEmail($email);
    }

    private static function applicationRows(array $user): array
    {
        return lava_instance()->call->model('Application_model')->getForUser($user);
    }

    private static function requireUser(): array
    {
        return self::currentUser(true);
    }

    private static function requireRole(array $allowed): array
    {
        $user = self::requireUser();
        if (!in_array($user['role'], $allowed, true)) {
            self::respond(['error' => 'You do not have permission to do that.'], 403);
        }
        return $user;
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'location' => $user['location'],
            'organization' => $user['organization'],
            'qualifications' => (string) ($user['qualifications'] ?? ''),
            'experience' => (string) ($user['experience'] ?? ''),
            'profile_image_url' => $user['profile_image_url'] ?? null,
            'skills' => $user['skills'] ?? [],
            'email_alerts' => (bool) $user['email_alerts']
        ];
    }

    private static function stringValue($value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    private static function validateSkills($skills, int $maxItems, int $maxLength): array
    {
        if (!is_array($skills) || count($skills) > $maxItems) {
            self::respond(['error' => 'Skills must be a list of up to ' . $maxItems . ' items.'], 422);
        }
        $result = [];
        foreach ($skills as $skill) {
            if (!is_string($skill)) self::respond(['error' => 'Each skill must be text.'], 422);
            $skill = trim($skill);
            if ($skill === '') continue;
            if (mb_strlen($skill) > $maxLength) {
                self::respond(['error' => 'Each skill must be ' . $maxLength . ' characters or fewer.'], 422);
            }
            $result[] = $skill;
        }
        return array_values(array_unique($result));
    }

    private static function optionalDate($value, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) self::respond(['error' => 'Enter a valid ' . $label . '.'], 422);
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            self::respond(['error' => 'Enter a valid ' . $label . '.'], 422);
        }
        return $value;
    }

    private static function validateResumeUpload(array $file): array
    {
        if (!isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            self::respond(['error' => 'Choose a resume file to continue.'], 422);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Your resume must be 2 MB or smaller.'
                : 'The resume upload did not complete. Please choose the file again.';
            self::respond(['error' => $message], 422);
        }
        if ((int) $file['size'] < 1 || (int) $file['size'] > 2 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
            self::respond(['error' => 'Your resume must be a valid file no larger than 2 MB.'], 422);
        }

        $originalName = basename(str_replace('\\', '/', (string) $file['name']));
        $originalName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $originalName));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (strlen($originalName) > 150 || !in_array($extension, ['pdf', 'docx'], true)) {
            self::respond(['error' => 'Upload your resume as a PDF or DOCX file with a filename under 150 characters.'], 422);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if ($extension === 'pdf') {
            $handle = fopen($file['tmp_name'], 'rb');
            $signature = $handle ? fread($handle, 5) : '';
            if ($handle) fclose($handle);
            if ($detectedMime !== 'application/pdf' || $signature !== '%PDF-') {
                self::respond(['error' => 'The selected file is not a valid PDF resume.'], 422);
            }
            $mime = 'application/pdf';
        } else {
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('DOCX uploads require the PHP zip extension.');
            }
            $archive = new ZipArchive();
            if ($archive->open($file['tmp_name']) !== true || $archive->locateName('word/document.xml') === false || $archive->locateName('word/vbaProject.bin') !== false) {
                if ($archive->status === ZipArchive::ER_OK) $archive->close();
                self::respond(['error' => 'The selected file is not a valid DOCX resume.'], 422);
            }
            $archive->close();
            if (!in_array($detectedMime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
                self::respond(['error' => 'The selected file is not a valid DOCX resume.'], 422);
            }
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        return [
            'temporary_name' => $file['tmp_name'],
            'original_name' => $originalName,
            'extension' => $extension,
            'mime' => $mime
        ];
    }

    private static function privateResumeDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private-resumes';
    }

    private static function storePrivateResume(array $resume): string
    {
        $directory = self::privateResumeDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private resume storage could not be created. Check the server storage permissions.');
        }

        $storageName = bin2hex(random_bytes(24)) . '.' . $resume['extension'];
        $destination = $directory . DIRECTORY_SEPARATOR . $storageName;
        if (!move_uploaded_file($resume['temporary_name'], $destination)) {
            throw new RuntimeException('The resume could not be saved to private server storage.');
        }
        @chmod($destination, 0600);
        return $storageName;
    }

    private static function deletePrivateResume(string $storageName): void
    {
        $path = self::privateResumeDirectory() . DIRECTORY_SEPARATOR . basename($storageName);
        if (is_file($path)) @unlink($path);
    }

    private static function validateProfilePhotoUpload(array $file): array
    {
        if (!isset($file['error'], $file['tmp_name'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK) {
            self::respond(['error' => 'Choose a profile photo to upload.'], 422);
        }
        if (
            (int) $file['size'] < 1 ||
            (int) $file['size'] > 2 * 1024 * 1024 ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            self::respond(['error' => 'Profile photos must be valid images no larger than 2 MB.'], 422);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $image = @getimagesize($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (
            !$image ||
            !in_array($mime, $allowed, true) ||
            !in_array($image['mime'] ?? '', $allowed, true) ||
            $mime !== $image['mime'] ||
            (int) $image[0] > 6000 ||
            (int) $image[1] > 6000 ||
            (int) $image[0] * (int) $image[1] > 20000000
        ) {
            self::respond(['error' => 'Upload a valid JPG, PNG, or WebP profile photo.'], 422);
        }

        return ['temporary_name' => $file['tmp_name']];
    }

    private static function addNotification(int $userId, ?int $listingId, string $kind, string $title, string $details, string $url): void
    {
        lava_instance()->call->model('Notification_model')->create($userId, $kind, $title, $details, $url);
    }

    private static function emailUsers(array $userIds, string $subject, string $message, string $url): int
    {
        if (!$userIds) return 0;
        $resendApiKey = self::env('RESEND_API_KEY');
        if ($resendApiKey !== '') {
            if (!function_exists('curl_init')) {
                error_log('[TARA-TRABAHO notifications] Email delivery failed: PHP cURL is unavailable.');
                return 0;
            }
            $from = self::env('MAIL_FROM');
            if ($from === '' || str_contains($from, 'example.com')) {
                error_log('[TARA-TRABAHO notifications] Email delivery skipped: set MAIL_FROM to a verified sender address.');
                return 0;
            }
            $recipients = array_values(array_unique(array_filter(
                lava_instance()->call->model('Account_model')->getAlertEmails($userIds),
                static fn($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            )));
            if (!$recipients) return 0;

            $siteUrl = rtrim(self::env('APP_URL') ?: 'http://localhost/backend/backend/LavaLust', '/');
            $html = '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p><a href="' . htmlspecialchars($siteUrl . $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">View on TARA-TRABAHO</a></p>';
            $sender = (self::env('MAIL_FROM_NAME') ?: 'TARA-TRABAHO') . ' <' . $from . '>';
            $emails = array_map(static fn($recipient) => [
                'from' => $sender,
                'to' => [$recipient],
                'subject' => $subject,
                'html' => $html,
                'text' => $message . ' ' . $siteUrl . $url,
            ], $recipients);
            $sent = 0;
            foreach (array_chunk($emails, 100) as $batch) {
                $curl = curl_init('https://api.resend.com/emails/batch');
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $resendApiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $response = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $curlError = curl_error($curl);
                curl_close($curl);
                $body = is_string($response) ? json_decode($response, true) : null;
                if ($status >= 200 && $status < 300 && is_array($body['data'] ?? null)) {
                    $sent += count($body['data']);
                } else {
                    error_log('[TARA-TRABAHO notifications] Resend delivery failed (HTTP ' . $status . ')' . ($curlError !== '' ? ': ' . $curlError : '.'));
                }
            }
            return $sent;
        }

        $host = self::env('MAIL_HOST');
        if (!$host) {
            error_log('[TARA-TRABAHO notifications] Email delivery skipped: MAIL_HOST is not configured.');
            return 0;
        }
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            error_log('[TARA-TRABAHO notifications] Email delivery failed: Composer autoload file is missing.');
            return 0;
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log('[TARA-TRABAHO notifications] Email delivery failed: PHPMailer is unavailable.');
            return 0;
        }

        $recipients = lava_instance()->call->model('Account_model')->getAlertEmails($userIds);
        $sent = 0;
        foreach ($recipients as $recipient) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Port = (int) (self::env('MAIL_PORT') ?: '587');
                $mail->SMTPAuth = self::env('MAIL_USERNAME') !== '';
                $mail->Username = self::env('MAIL_USERNAME');
                $mail->Password = self::env('MAIL_PASSWORD');
                $encryption = strtolower(self::env('MAIL_ENCRYPTION'));
                if ($encryption === 'ssl') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                elseif ($encryption === 'tls' || $encryption === '') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom(self::env('MAIL_FROM') ?: 'no-reply@tara-trabaho.local', self::env('MAIL_FROM_NAME') ?: 'TARA-TRABAHO');
                $mail->addAddress($recipient);
                $mail->isHTML(true);
                $siteUrl = rtrim(self::env('APP_URL') ?: 'http://localhost/backend/backend/LavaLust', '/');
                $mail->Subject = $subject;
                $mail->Body = '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p><a href="' . htmlspecialchars($siteUrl . $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">View on TARA-TRABAHO</a></p>';
                $mail->AltBody = $message . ' ' . $siteUrl . $url;
                $mail->send();
                $sent++;
            } catch (Throwable $exception) {
                error_log('[TARA-TRABAHO notifications] Email delivery failed: ' . $exception->getMessage());
            }
        }
        return $sent;
    }

    private static function pushUsers(array $userIds, string $tag, string $title, string $message, string $url): int
    {
        if (!$userIds) return 0;
        $publicKey = self::env('VAPID_PUBLIC_KEY');
        $privateKey = self::env('VAPID_PRIVATE_KEY');
        $subject = self::env('VAPID_SUBJECT');
        if (!$publicKey || !$privateKey || !$subject) return 0;

        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            error_log('[TARA-TRABAHO notifications] Push delivery failed: Composer autoload file is missing.');
            return 0;
        }
        require_once $autoload;
        if (!class_exists(\Minishlink\WebPush\WebPush::class) || !class_exists(\Minishlink\WebPush\Subscription::class)) {
            error_log('[TARA-TRABAHO notifications] Push delivery failed: Web Push library is unavailable.');
            return 0;
        }

        try {
            $subscriptions = lava_instance()->call->model('Notification_model')->getPushSubscriptionsForUsers($userIds);
            if (!$subscriptions) return 0;

            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey
                ]
            ]);
            $payload = json_encode([
                'title' => $title,
                'body' => $message,
                'url' => $url,
                'tag' => $tag
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $subscription['endpoint'],
                        'keys' => [
                            'p256dh' => $subscription['public_key'],
                            'auth' => $subscription['auth_token']
                        ]
                    ]),
                    $payload
                );
            }

            $sent = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    lava_instance()->call->model('Notification_model')
                        ->removeExpiredPushSubscription($report->getEndpoint());
                } else {
                    error_log('[TARA-TRABAHO notifications] Push delivery failed: ' . $report->getReason());
                }
            }
            return $sent;
        } catch (Throwable $exception) {
            error_log('[TARA-TRABAHO notifications] Push delivery failed: ' . $exception->getMessage());
            return 0;
        }
    }
}
