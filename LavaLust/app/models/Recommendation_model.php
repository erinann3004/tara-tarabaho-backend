<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Recommendation_model extends Model
{
    protected $timestamps = false;

    public function recommendBySkills(array $skills, array $listings, int $limit = 10): array
    {
        $profileSkills = $this->normalizeSkills($skills);
        $jobs = [];
        $trainingPrograms = [];

        foreach ($listings as $listing) {
            if (!is_array($listing)) {
                continue;
            }

            if (($listing['kind'] ?? '') === 'job') {
                $requiredSkills = $this->normalizeSkills($listing['skills'] ?? []);
                if (!$requiredSkills) {
                    continue;
                }

                $matchedSkills = array_intersect_key($requiredSkills, $profileSkills);
                $missingSkills = array_diff_key($requiredSkills, $profileSkills);
                $listing['match_percentage'] = (int) round(count($matchedSkills) / count($requiredSkills) * 100);
                $listing['matched_skills'] = array_values($matchedSkills);
                $listing['missing_skills'] = array_values($missingSkills);
                $jobs[] = [
                    'listing' => $listing,
                    'missing_skills' => $missingSkills,
                ];
            } elseif (($listing['kind'] ?? '') === 'training') {
                $listing['skills'] = array_values($this->normalizeSkills($listing['skills'] ?? []));
                $trainingPrograms[] = $listing;
            }
        }

        usort($jobs, static function (array $left, array $right): int {
            $scoreOrder = $right['listing']['match_percentage'] <=> $left['listing']['match_percentage'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $matchedOrder = count($right['listing']['matched_skills']) <=> count($left['listing']['matched_skills']);
            return $matchedOrder !== 0
                ? $matchedOrder
                : ((int) $right['listing']['id'] <=> (int) $left['listing']['id']);
        });

        $jobGaps = array_slice($jobs, 0, max(0, $limit));
        $recommendedJobs = array_values(array_filter(
            array_column($jobGaps, 'listing'),
            static function (array $job): bool {
                return $job['match_percentage'] > 0;
            }
        ));
        $recommendedTrainings = [];

        foreach ($trainingPrograms as $program) {
            $programSkills = $this->normalizeSkills($program['skills']);
            $coveredMissingSkills = [];
            $relatedJobs = [];
            $bestGapCoverage = 0;

            foreach ($jobGaps as $jobGap) {
                if (!$jobGap['missing_skills']) {
                    continue;
                }

                $coveredForJob = array_intersect_key($jobGap['missing_skills'], $programSkills);
                if (!$coveredForJob) {
                    continue;
                }

                foreach ($coveredForJob as $skillKey => $skillName) {
                    $coveredMissingSkills[$skillKey] = $skillName;
                }
                $relatedJobs[] = [
                    'id' => (int) $jobGap['listing']['id'],
                    'title' => (string) $jobGap['listing']['title'],
                    'match_percentage' => $jobGap['listing']['match_percentage'],
                    'covered_missing_skills' => array_values($coveredForJob),
                ];
                $bestGapCoverage = max(
                    $bestGapCoverage,
                    (int) round(count($coveredForJob) / count($jobGap['missing_skills']) * 100)
                );
            }

            if (!$coveredMissingSkills) {
                continue;
            }

            $program['covered_missing_skills'] = array_values($coveredMissingSkills);
            $program['related_jobs'] = $relatedJobs;
            $program['match_percentage'] = $bestGapCoverage;
            $program['_covered_skill_count'] = count($coveredMissingSkills);
            $recommendedTrainings[] = $program;
        }

        usort($recommendedTrainings, static function (array $left, array $right): int {
            $scoreOrder = $right['match_percentage'] <=> $left['match_percentage'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $coverageOrder = $right['_covered_skill_count'] <=> $left['_covered_skill_count'];
            return $coverageOrder !== 0
                ? $coverageOrder
                : ((int) $right['id'] <=> (int) $left['id']);
        });

        foreach ($recommendedTrainings as &$program) {
            unset($program['_covered_skill_count']);
        }
        unset($program);

        return [
            'profile_skills' => array_values($profileSkills),
            'jobs' => array_slice($recommendedJobs, 0, max(0, $limit)),
            'trainings' => array_slice($recommendedTrainings, 0, max(0, $limit)),
        ];
    }

    private function normalizeSkills($skills): array
    {
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            $skills = is_array($decoded)
                ? $decoded
                : (preg_split('/[,;\r\n]+/', $skills, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        if (!is_array($skills)) {
            return [];
        }

        $normalized = [];
        foreach ($skills as $skill) {
            if (!is_string($skill)) {
                continue;
            }

            $label = trim(preg_replace('/\s+/u', ' ', $skill) ?? $skill);
            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);
            $normalized[$key] = $normalized[$key] ?? $label;
        }

        return $normalized;
    }
}
