<?php

namespace App\Models;

use Core\Model;
use Core\Database;
use App\Events\FeatureFlagChanged;

class FeatureFlagEnhanced extends Model 
{
    private static array $cachedFeatures = [];
    private static bool $loaded = false;
    private array $decodedCache = [];
    private static array $userRoleCache = [];
    
    // Request-level cache برای جلوگیری از Query های تکراری
    private array $requestCache = [];
    
    private const ALLOWED_UPDATE_FIELDS = [
        'enabled',
        'description',
        'enabled_percentage',
        'enabled_for_roles',
        'enabled_for_users',
        'metadata',
        'enabled_from',
        'enabled_until',
        'depends_on',
        'environments',
        'priority',
        'tags',
    ];
    
    private function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        
        $sql = "SELECT * FROM feature_flags ORDER BY priority DESC, name ASC";
        $features = $this->db->fetchAll($sql);
        
        foreach ($features as $feature) {
            self::$cachedFeatures[$feature->name] = $feature;
        }
        
        self::$loaded = true;
    }
    
    private function clearCache(): void
    {
        self::$cachedFeatures = [];
        self::$loaded = false;
        $this->decodedCache = [];
        $this->requestCache = [];
        
        // پاکسازی Database Cache
        $this->db->query("TRUNCATE TABLE feature_flag_cache");
    }
    
    public function getAll(): array
    {
        $this->loadAll();
        return array_values(self::$cachedFeatures);
    }
    
    public function findByName(string $name): ?object
    {
        $this->loadAll();
        return self::$cachedFeatures[$name] ?? null;
    }
    
    private function getDecodedRoles(object $feature): array
    {
        $cacheKey = "roles_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->enabled_for_roles 
                ? json_decode($feature->enabled_for_roles, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getDecodedUsers(object $feature): array
    {
        $cacheKey = "users_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->enabled_for_users 
                ? json_decode($feature->enabled_for_users, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getDecodedDependencies(object $feature): array
    {
        $cacheKey = "deps_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->depends_on ?? null
                ? json_decode($feature->depends_on, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getUserRole(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        
        if (!isset(self::$userRoleCache[$userId])) {
            $user = $this->db->fetch("SELECT role FROM users WHERE id = ?", [$userId]);
            self::$userRoleCache[$userId] = $user?->role;
        }
        
        return self::$userRoleCache[$userId];
    }
    
    /**
     * بررسی Time-based Scheduling
     */
    private function checkTimeSchedule(object $feature): bool
    {
        $now = new \DateTime();
        
        // اگر enabled_from تنظیم شده و هنوز نرسیده
        if ($feature->enabled_from ?? null) {
            $enabledFrom = new \DateTime($feature->enabled_from);
            if ($now < $enabledFrom) {
                return false;
            }
        }
        
        // اگر enabled_until تنظیم شده و گذشته
        if ($feature->enabled_until ?? null) {
            $enabledUntil = new \DateTime($feature->enabled_until);
            if ($now > $enabledUntil) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * بررسی Dependencies
     */
    private function checkDependencies(string $featureName, ?int $userId = null): bool
    {
        $feature = $this->findByName($featureName);
        if (!$feature) {
            return true; // اگر فیچر وجود نداره، Dependency نداریم
        }
        
        $dependencies = $this->getDecodedDependencies($feature);
        
        if (empty($dependencies)) {
            return true;
        }
        
        // بررسی همه Dependencies
        foreach ($dependencies as $depName) {
            if (!$this->isEnabled($depName, $userId)) {
                $this->logger->debug('feature_flag.dependency_failed', [
                    'channel' => 'feature_flag',
                    'feature' => $featureName,
                    'dependency' => $depName,
                ]);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * بررسی Environment
     */
    private function checkEnvironment(object $feature): bool
    {
        $environments = $feature->environments ?? null
            ? json_decode($feature->environments, true)
            : null;
        
        if (empty($environments)) {
            return true; // اگر محدودیتی نداره، همه environment ها OK هستند
        }
        
        $currentEnv = getenv('APP_ENV') ?: 'production';
        
        return in_array($currentEnv, $environments, true);
    }
    
    /**
     * بررسی فعال بودن فیچر با همه شرایط
     */
    public function isEnabled(string $name, ?int $userId = null, ?string $role = null): bool
    {
        $startTime = microtime(true);
        
        // Request-level cache
        $cacheKey = "{$name}:{$userId}";
        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }
        
        // بررسی Database Cache (برای Performance بهتر)
        $dbCacheResult = $this->checkDatabaseCache($name, $userId);
        if ($dbCacheResult !== null) {
            $this->requestCache[$cacheKey] = $dbCacheResult;
            return $dbCacheResult;
        }
        
        $feature = $this->findByName($name);
        $denyReason = null;
        
        if (!$feature) {
            $this->logger->warning('feature_flag.not_found', [
                'channel' => 'feature_flag',
                'name' => $name,
            ]);
            $result = false;
            $denyReason = 'not_found';
        } elseif (!$feature->enabled) {
            $result = false;
            $denyReason = 'disabled';
        } elseif (!$this->checkTimeSchedule($feature)) {
            $result = false;
            $denyReason = 'time_schedule';
        } elseif (!$this->checkEnvironment($feature)) {
            $result = false;
            $denyReason = 'environment';
        } elseif (!$this->checkDependencies($name, $userId)) {
            $result = false;
            $denyReason = 'dependency';
        } else {
            // بررسی Role
            $allowedRoles = $this->getDecodedRoles($feature);
            if (!empty($allowedRoles)) {
                if ($role === null) {
                    $role = $this->getUserRole($userId);
                }
                
                if (!$role || !in_array($role, $allowedRoles, true)) {
                    $result = false;
                    $denyReason = 'role_denied';
                } else {
                    $result = true;
                }
            } else {
                $result = true;
            }
            
            // بررسی User
            if ($result) {
                $allowedUsers = $this->getDecodedUsers($feature);
                if (!empty($allowedUsers)) {
                    if (!$userId || !in_array($userId, $allowedUsers, true)) {
                        $result = false;
                        $denyReason = 'user_denied';
                    }
                }
            }
            
            // بررسی Percentage
            if ($result && $feature->enabled_percentage < 100) {
                if ($userId) {
                    $hash = crc32($userId . $name);
                    $userPercentage = ($hash % 100) + 1;
                    
                    if ($userPercentage > $feature->enabled_percentage) {
                        $result = false;
                        $denyReason = 'percentage';
                    }
                } else {
                    if (rand(1, 100) > $feature->enabled_percentage) {
                        $result = false;
                        $denyReason = 'percentage';
                    }
                }
            }
        }
        
        // ذخیره در Request Cache
        $this->requestCache[$cacheKey] = $result;
        
        // ذخیره در Database Cache (با TTL)
        $this->saveToDatabaseCache($name, $userId, $result);
        
        // ذخیره Metrics
        $responseTime = (microtime(true) - $startTime) * 1000; // ms
        $this->saveMetrics($name, $userId, $result, $denyReason, $responseTime);
        
        return $result;
    }
    
    /**
     * بررسی Database Cache
     */
    private function checkDatabaseCache(string $name, ?int $userId): ?bool
    {
        $cacheKey = "{$name}:" . ($userId ?? 'null');
        
        $cached = $this->db->fetch(
            "SELECT is_enabled FROM feature_flag_cache 
             WHERE cache_key = ? AND expires_at > NOW()",
            [$cacheKey]
        );
        
        return $cached ? (bool)$cached->is_enabled : null;
    }
    
    /**
     * ذخیره در Database Cache
     */
    private function saveToDatabaseCache(string $name, ?int $userId, bool $result): void
    {
        $cacheKey = "{$name}:" . ($userId ?? 'null');
        $ttl = 300; // 5 دقیقه
        
        try {
            $sql = "INSERT INTO feature_flag_cache (cache_key, is_enabled, cached_at, expires_at)
                    VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
                    ON DUPLICATE KEY UPDATE 
                        is_enabled = VALUES(is_enabled),
                        cached_at = VALUES(cached_at),
                        expires_at = VALUES(expires_at)";
            
            $this->db->query($sql, [$cacheKey, $result ? 1 : 0, $ttl]);
        } catch (\Exception $e) {
            // اگر جدول MEMORY پر شده، سایلنت ignore
        }
    }
    
    /**
     * ذخیره Metrics
     */
    private function saveMetrics(string $name, ?int $userId, bool $result, ?string $reason, float $responseTime): void
    {
        try {
            $sql = "INSERT INTO feature_flag_metrics 
                    (feature_name, user_id, check_result, check_reason, checked_at, response_time_ms)
                    VALUES (?, ?, ?, ?, NOW(), ?)";
            
            $this->db->query($sql, [
                $name,
                $userId,
                $result ? 1 : 0,
                $reason,
                round($responseTime, 2),
            ]);
        } catch (\Exception $e) {
            // Metrics نباید باعث خرابی سیستم بشه
        }
    }
    
    public function isEnabledForUser(string $name, ?int $userId = null): bool
    {
        return $this->isEnabled($name, $userId);
    }
    
    /**
     * Toggle با Event Dispatching
     */
    public function toggle(string $name): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            return false;
        }
        
        $oldValues = [
            'enabled' => (bool)$feature->enabled,
        ];
        
        $newStatus = !$feature->enabled;
        
        $sql = "UPDATE feature_flags SET enabled = ?, updated_at = NOW() WHERE name = ?";
        $result = $this->db->query($sql, [$newStatus ? 1 : 0, $name]);
        
        if ($result) {
            $this->clearCache();
            
            $newValues = [
                'enabled' => $newStatus,
            ];
            
            // Dispatch Event
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'toggled',
                $oldValues,
                $newValues,
                user_id()
            ));
        }
        
        return $result;
    }
    
    /**
     * Update با Event Dispatching
     */
    public function update(string $name, array $data): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            throw new \InvalidArgumentException("Feature '{$name}' not found");
        }
        
        // ذخیره مقادیر قدیمی
        $oldValues = [];
        foreach ($data as $key => $value) {
            if (property_exists($feature, $key)) {
                $oldValues[$key] = $feature->$key;
            }
        }
        
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid field for update: $key");
            }
            
            if (in_array($key, ['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'])) {
                $value = json_encode($value);
            }
            
            if ($key === 'enabled_percentage') {
                $value = max(0, min(100, (int)$value));
            }
            
            if ($key === 'enabled') {
                $value = $value ? 1 : 0;
            }
            
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $name;
        
        $sql = "UPDATE feature_flags SET " . implode(', ', $fields) . " WHERE name = ?";
        
        $result = $this->db->query($sql, $params);
        
        if ($result) {
            $this->clearCache();
            
            // Dispatch Event
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'updated',
                $oldValues,
                $data,
                user_id()
            ));
        }
        
        return $result;
    }
    
    /**
     * Create با Event Dispatching
     */
    public function create(array $data): bool
    {
        $required = ['name', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }
        
        if ($this->findByName($data['name'])) {
            throw new \InvalidArgumentException("Feature '{$data['name']}' already exists");
        }
        
        $defaults = [
            'enabled' => false,
            'enabled_percentage' => 100,
            'enabled_for_roles' => null,
            'enabled_for_users' => null,
            'metadata' => null,
            'enabled_from' => null,
            'enabled_until' => null,
            'depends_on' => null,
            'environments' => null,
            'priority' => 0,
            'tags' => null,
        ];
        
        $data = array_merge($defaults, $data);
        
        foreach (['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'] as $field) {
            if (is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }
        
        $sql = "INSERT INTO feature_flags 
                (name, description, enabled, enabled_percentage, enabled_for_roles, enabled_for_users, 
                 metadata, enabled_from, enabled_until, depends_on, environments, priority, tags, 
                 created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $result = $this->db->query($sql, [
            $data['name'],
            $data['description'],
            $data['enabled'] ? 1 : 0,
            $data['enabled_percentage'],
            $data['enabled_for_roles'],
            $data['enabled_for_users'],
            $data['metadata'],
            $data['enabled_from'],
            $data['enabled_until'],
            $data['depends_on'],
            $data['environments'],
            $data['priority'],
            $data['tags'],
        ]);
        
        if ($result) {
            $this->clearCache();
            
            // Dispatch Event
            $this->dispatchEvent(new FeatureFlagChanged(
                $data['name'],
                'created',
                [],
                $data,
                user_id()
            ));
        }
        
        return $result;
    }
    
    /**
     * Delete با Event Dispatching
     */
    public function delete(string $name): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            return false;
        }
        
        // ذخیره اطلاعات قبل از حذف
        $oldValues = (array)$feature;
        
        $sql = "DELETE FROM feature_flags WHERE name = ?";
        $result = $this->db->query($sql, [$name]);
        
        if ($result) {
            $this->clearCache();
            
            // Dispatch Event
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'deleted',
                $oldValues,
                [],
                user_id()
            ));
        }
        
        return $result;
    }
    
    /**
     * Dispatch Event به Event System
     */
    private function dispatchEvent(FeatureFlagChanged $event): void
    {
        try {
            // فراخوانی Listener ها
            $listener = new \App\Listeners\LogFeatureFlagChange($this->db, $this->logger);
            $listener->handle($event);
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.event_dispatch_failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    public function getStats(): array
    {
        $all = $this->getAll();
        
        $stats = [
            'total' => count($all),
            'enabled' => 0,
            'disabled' => 0,
            'role_restricted' => 0,
            'user_restricted' => 0,
            'percentage_based' => 0,
            'time_scheduled' => 0,
            'with_dependencies' => 0,
        ];
        
        foreach ($all as $feature) {
            if ($feature->enabled) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }
            
            if ($feature->enabled_for_roles) {
                $stats['role_restricted']++;
            }
            
            if ($feature->enabled_for_users) {
                $stats['user_restricted']++;
            }
            
            if ($feature->enabled_percentage < 100) {
                $stats['percentage_based']++;
            }
            
            if ($feature->enabled_from ?? null || $feature->enabled_until ?? null) {
                $stats['time_scheduled']++;
            }
            
            if ($feature->depends_on ?? null) {
                $stats['with_dependencies']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * دریافت تاریخچه تغییرات یک فیچر
     */
    public function getHistory(string $name, int $limit = 50): array
    {
        $sql = "SELECT * FROM feature_flag_history 
                WHERE feature_name = ? 
                ORDER BY changed_at DESC 
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$name, $limit]) ?: [];
    }
    
    /**
     * دریافت متریک‌های یک فیچر
     */
    public function getMetrics(string $name, int $hours = 24): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_checks,
                    SUM(check_result) as allowed_count,
                    COUNT(*) - SUM(check_result) as denied_count,
                    AVG(response_time_ms) as avg_response_time,
                    MAX(response_time_ms) as max_response_time,
                    check_reason,
                    COUNT(*) as reason_count
                FROM feature_flag_metrics
                WHERE feature_name = ?
                AND checked_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY check_reason";
        
        return $this->db->fetchAll($sql, [$name, $hours]) ?: [];
    }
}
