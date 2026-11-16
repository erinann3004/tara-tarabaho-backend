<?php

define('PREVENT_DIRECT_ACCESS', true);

class Model
{
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'Recommendation_model.php';

$model = new Recommendation_model();
$listings = [
    [
        'id' => 1,
        'kind' => 'job',
        'title' => 'Frontend Developer',
        'skills' => ['HTML', 'CSS', 'JavaScript', 'PHP'],
    ],
    [
        'id' => 2,
        'kind' => 'job',
        'title' => 'PHP Developer',
        'skills' => ['PHP', 'Laravel'],
    ],
    [
        'id' => 10,
        'kind' => 'training',
        'title' => 'PHP Programming',
        'skills' => ['php'],
    ],
    [
        'id' => 11,
        'kind' => 'training',
        'title' => 'Python Programming',
        'skills' => ['Python'],
    ],
];

$results = $model->recommendBySkills(['html', ' CSS ', 'Javascript', 'HTML'], $listings);
if (count($results['jobs']) !== 1) {
    throw new RuntimeException('Only jobs with at least one matched required skill should be recommended.');
}
if ($results['jobs'][0]['match_percentage'] !== 75 || $results['jobs'][0]['missing_skills'] !== ['PHP']) {
    throw new RuntimeException('The job match must be measured against required skills and report PHP as missing.');
}
if (count($results['trainings']) !== 1 || $results['trainings'][0]['title'] !== 'PHP Programming') {
    throw new RuntimeException('Training recommendations must cover missing skills from actual job listings.');
}
if ($results['trainings'][0]['covered_missing_skills'] !== ['PHP'] || $results['trainings'][0]['match_percentage'] !== 100) {
    throw new RuntimeException('The PHP training should cover all missing skills for its related job.');
}

$noProfileMatch = $model->recommendBySkills(['Cooking'], $listings);
if ($noProfileMatch['jobs'] !== [] || count($noProfileMatch['trainings']) !== 1) {
    throw new RuntimeException('Unmatched jobs must not be recommended, but relevant gap training may still be shown.');
}

fwrite(STDOUT, "Recommendation model checks passed.\n");
