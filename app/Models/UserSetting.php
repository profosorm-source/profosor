<?php

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * UserSetting Model
 */
class UserSetting extends Model
{
    protected $table = 'user_settings';
    protected $fillable = ['user_id', 'setting_key', 'setting_value'];
    protected $timestamps = true;

    /**
     * دریافت تنظیمات کاربر
     */
    public function getUserSettings(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM {$this->table} WHERE user_id = ?",
            [$userId]
        ) ?: [];
    }

    /**
     * دریافت یک تنظیم
     */
    public function getSetting(int $userId, string $key): ?string
    {
        $result = $this->db->queryOne(
            "SELECT setting_value FROM {$this->table} WHERE user_id = ? AND setting_key = ?",
            [$userId, $key]
        );

        return $result['setting_value'] ?? null;
    }
}
