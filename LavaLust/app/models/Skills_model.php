<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Skills_model extends Model
{
    protected $table = 'UserSkills';
    protected $primary_key = 'user_skill_id';
    protected $fillable = [
        'user_id',
        'skill_name',
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
            ->select('skill_name')
            ->where('user_id', $userId)
            ->order_by('user_skill_id', 'ASC')
            ->get_all();

        return array_map(static function (array $row): string {
            return (string) $row['skill_name'];
        }, $rows);
    }

    public function replaceForUser(int $userId, array $skills): void
    {
        $this->db->transaction();
        try {
            $this->db->table($this->table)
                ->where('user_id', $userId)
                ->delete();
            foreach (array_values(array_unique($skills)) as $skill) {
                $this->db->table($this->table)->insert([
                    'user_id' => $userId,
                    'skill_name' => $skill,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }
}
