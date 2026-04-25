<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Contracts\FeatureFlagRepositoryInterface;
use Core\Cache;
use Core\Logger;

/**
 * Feature Flag Service
 * 
 * مدیریت Feature Flags با targeting پیشرفته
 * شامل: user targeting، role، کشور، پلن، device، route، age، percentage rollout
 */
class FeatureFlagService
{
    private \Core\Database $db;
    private FeatureFlag $featureModel;
    private Cache $cache;
    private Logger $logger;
    
    public function __construct(
        FeatureFlag $featureModel,
        \Core\Database $db,
        ?Cache $cache = null,
        ?Logger $logger = null
    ) {
        $this->featureModel = $featureModel;
        $this->db = $db;
        $this->cache = $cache ?? Cache::getInstance();
        $this->logger = $logger ?? new Logger();
    }
    
    /**
     * بررسی آیا یک فیچر برای کاربر فعال است
     * شامل: targeting، زمان‌بندی، درصد کاربران
     */
    public function isEnabled(string $name, ?int $userId = null, ?array $context = null): bool
    {
        // cache check
        $cacheKey = "ff:enabled:{$name}:{$userId}";
        if ($cached = $this->cache->get($cacheKey)) {
            return (bool)$cached;
        }

        $feature = $this->featureModel->findByName($name);
        if (!$feature || !$feature->enabled) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        // بررسی زمان‌بندی
        if (!$this->checkTimeSchedule($feature)) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        // اگر user id نیست، فقط check عمومی
        if (!$userId) {
            $result = (bool)$feature->enabled;
            $this->cache->put($cacheKey, $result ? 1 : 0, 5);
            return $result;
        }

        // دریافت اطلاعات کاربر
        $userContext = $this->getUserContext($userId, $context);
        
        // بررسی targeting
        if (!$this->checkTargeting($feature, $userContext)) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        // بررسی percentage rollout (consistent و reproducible)
        if (!$this->checkPercentageRollout($feature, $userId)) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        $this->cache->put($cacheKey, 1, 5);
        return true;
    }

    /**
     * بررسی چند فیچر به صورت AND
     */
    public function areEnabled(array $names, ?int $userId = null, ?array $context = null): bool
    {
        foreach ($names as $name) {
            if (!$this->isEnabled($name, $userId, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * دریافت تمام فیچرهای فعال برای کاربر
     */
    public function getEnabled(?int $userId = null, ?array $context = null): array
    {
        $all = $this->featureModel->getAll();
        $enabled = [];
        
        foreach ($all as $feature) {
            if ($this->isEnabled($feature->name, $userId, $context)) {
                $enabled[] = $feature->name;
            }
        }
        
        return $enabled;
    }

    /**
     * دریافت مقدار پارامتر فیچر (برای اعداد dynamic)
     * مثال: getValue('lottery_profit_percentage', 10) => 15
     */
    public function getValue(string $name, mixed $default = null): mixed
    {
        $feature = $this->featureModel->findByName($name);
        if (!$feature || !$feature->config_values) {
            return $default;
        }

        $config = json_decode($feature->config_values, true) ?? [];
        return $config;
    }

    /**
     * دریافت یک پارامتر خاص از feature flag
     */
    public function getConfig(string $featureName, string $configKey, mixed $default = null): mixed
    {
        $config = $this->getValue($featureName);
        if (is_array($config) && isset($config[$configKey])) {
            return $config[$configKey];
        }
        return $default;
    }

    /**
     * بررسی targeting پیشرفته
     */
    private function checkTargeting(object $feature, array $userContext): bool
    {
        // بررسی user_ids خاص
        if ($feature->targeted_user_ids) {
            $userIds = json_decode($feature->targeted_user_ids, true) ?? [];
            if (!empty($userIds) && !in_array($userContext['user_id'], $userIds)) {
                return false;
            }
        }

        // بررسی roles
        if ($feature->targeted_roles) {
            $roles = json_decode($feature->targeted_roles, true) ?? [];
            if (!empty($roles) && !in_array($userContext['role'], $roles)) {
                return false;
            }
        }

        // بررسی کشورها
        if ($feature->targeted_countries) {
            $countries = json_decode($feature->targeted_countries, true) ?? [];
            if (!empty($countries) && !in_array($userContext['country'] ?? null, $countries)) {
                return false;
            }
        }

        // بررسی پلن‌ها
        if ($feature->targeted_plans) {
            $plans = json_decode($feature->targeted_plans, true) ?? [];
            if (!empty($plans) && !in_array($userContext['plan'] ?? null, $plans)) {
                return false;
            }
        }

        // بررسی devices
        if ($feature->targeted_devices) {
            $devices = json_decode($feature->targeted_devices, true) ?? [];
            if (!empty($devices) && !in_array($userContext['device'] ?? null, $devices)) {
                return false;
            }
        }

        // بررسی routes
        if ($feature->targeted_routes) {
            $routes = json_decode($feature->targeted_routes, true) ?? [];
            $currentRoute = $userContext['route'] ?? ($_SERVER['REQUEST_URI'] ?? null);
            
            if (!empty($routes)) {
                $match = false;
                foreach ($routes as $route) {
                    if (strpos($currentRoute, $route) !== false) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) return false;
            }
        }

        // بررسی age
        if ($feature->target_age_min || $feature->target_age_max) {
            $age = $userContext['age'] ?? null;
            if ($age) {
                if ($feature->target_age_min && $age < $feature->target_age_min) return false;
                if ($feature->target_age_max && $age > $feature->target_age_max) return false;
            }
        }

        return true;
    }

    /**
     * بررسی percentage rollout (consistent عبر sessions)
     * استفاده از hash برای consistency
     */
    private function checkPercentageRollout(object $feature, int $userId): bool
    {
        if (!isset($feature->percentage_rollout) || $feature->percentage_rollout >= 100) {
            return true;
        }

        // استفاده از seed برای consistency
        $seed = $feature->rollout_seed ?? $feature->name;
        $hash = hexdec(substr(hash('sha256', "{$userId}:{$seed}"), 0, 8)) % 100;
        
        return $hash < (int)$feature->percentage_rollout;
    }

    /**
     * بررسی زمان‌بندی (time schedule)
     */
    private function checkTimeSchedule(object $feature): bool
    {
        $now = date('Y-m-d H:i:s');
        
        if ($feature->enabled_from && $now < $feature->enabled_from) {
            return false;
        }
        
        if ($feature->enabled_until && $now > $feature->enabled_until) {
            return false;
        }
        
        return true;
    }

    /**
     * دریافت اطلاعات کاربر برای targeting
     */
    private function getUserContext(int $userId, ?array $context = null): array
    {
        if ($context) {
            return array_merge(['user_id' => $userId], $context);
        }

        // دریافت از database
        $sql = "
            SELECT 
                u.id as user_id,
                u.role,
                u.birth_date,
                u.country,
                ks.plan,
                ks.device_type as device
            FROM users u
            LEFT JOIN kyc_verifications ks ON u.id = ks.user_id
            WHERE u.id = ?
            LIMIT 1
        ";
        
        $user = $this->db->fetch($sql, [$userId]);
        if (!$user) {
            return ['user_id' => $userId, 'role' => 'user'];
        }

        $age = $user->birth_date ? $this->calculateAge($user->birth_date) : null;

        return [
            'user_id' => $userId,
            'role' => $user->role ?? 'user',
            'country' => $user->country,
            'plan' => $user->plan,
            'device' => $user->device,
            'age' => $age,
            'route' => $_SERVER['REQUEST_URI'] ?? '/'
        ];
    }

    /**
     * محاسبه سن از birth date
     */
    private function calculateAge(string $birthDate): int
    {
        $birth = new \DateTime($birthDate);
        $today = new \DateTime();
        return $today->diff($birth)->y;
    }

    /**
     * پاک کردن cache
     */
    public function clearCache(string $featureName = null): void
    {
        if ($featureName) {
            $this->cache->tags(['feature_flag'])->forget($featureName);
        } else {
            $this->cache->tags(['feature_flag'])->flush();
        }
    }

    /**
     * دریافت تمام فیچرها
     */
    public function getAll(): array
    {
        return $this->featureModel->getAll();
    }

    /**
     * یافتن یک فیچر با نام
     */
    public function findByName(string $name): ?object
    {
        return $this->featureModel->findByName($name);
    }
}

