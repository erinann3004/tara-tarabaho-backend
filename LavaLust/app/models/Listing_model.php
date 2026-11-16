<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Listing_model extends Model
{
    protected $timestamps = false;

    public function getPublished(?string $kind = null, int $limit = 0): array
    {
        $rows = [];
        foreach ($this->modelsForKind($kind) as $model) {
            $rows = array_merge($rows, $model->getPublished($limit));
        }

        return $this->sortAndLimit($rows, $limit);
    }

    public function getForOwner(int $ownerId, string $kind, int $limit = 0): array
    {
        return $this->modelForKind($kind)->getForOwner($ownerId, $limit);
    }

    public function getById(int $listingId, string $kind, ?string $status = null): ?array
    {
        return $this->modelForKind($kind)->getById($listingId, $status);
    }

    public function getPending(int $limit = 100): array
    {
        $rows = [];
        foreach ($this->modelsForKind(null) as $model) {
            $rows = array_merge($rows, $model->getPending($limit));
        }

        return $this->sortAndLimit($rows, $limit);
    }

    public function moderatePending(int $listingId, string $kind, string $status): ?array
    {
        if (!in_array($status, ['published', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid moderation status.');
        }

        if ($kind === 'job') {
            $table = 'JobVacancies';
            $idColumn = 'vacancy_id';
        } elseif ($kind === 'training') {
            $table = 'TrainingPrograms';
            $idColumn = 'program_id';
        } else {
            throw new InvalidArgumentException('Listing kind must be job or training.');
        }

        $listing = $this->db->table($table)
            ->where($idColumn, $listingId)
            ->where('LOWER(status)', 'pending')
            ->limit(1)
            ->get();
        if (!$listing) {
            return null;
        }

        $this->db->table($table)
            ->where($idColumn, $listingId)
            ->update(['status' => $status]);

        return $listing;
    }

    private function modelsForKind(?string $kind): array
    {
        if ($kind === null) {
            return [
                $this->modelForKind('job'),
                $this->modelForKind('training'),
            ];
        }

        return [$this->modelForKind($kind)];
    }

    private function modelForKind(string $kind)
    {
        if ($kind === 'job') {
            return lava_instance()->call->model('Job_model');
        }
        if ($kind === 'training') {
            return lava_instance()->call->model('Training_model');
        }

        throw new InvalidArgumentException('Listing kind must be job or training.');
    }

    private function sortAndLimit(array $rows, int $limit): array
    {
        usort($rows, static function (array $left, array $right): int {
            return (int) $right['id'] <=> (int) $left['id'];
        });

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }
}
