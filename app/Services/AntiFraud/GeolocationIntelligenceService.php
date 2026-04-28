<?php

namespace App\Services\AntiFraud;

use App\Services\RiskPolicyService;
use Core\Database;
use Core\Logger;

/**
 * GeolocationIntelligenceService
 * 
 * تحلیل جغرافیایی پیشرفته و تشخیص سفرهای غیرممکن
 * 
 * Features:
 * - Impossible Travel Detection (سفرهای غیرممکن فیزیکی)
 * - Country/Region Risk Scoring
 * - Geo-velocity analysis
 * - IP Geolocation with multiple providers
 * - Timezone anomaly detection
 */
class GeolocationIntelligenceService
{
    private Database $db;
    private RiskPolicyService $policy;
    private Logger $logger;
    
    // Risk scores برای کشورها (مثال)
    private const COUNTRY_RISK_SCORES = [
        'IR' => 10,  // ایران
        'US' => 15,
        'GB' => 15,
        'DE' => 20,
        'CN' => 40,  // چین
        'RU' => 45,  // روسیه
        'KP' => 90,  // کره شمالی
        'CU' => 80,  // کوبا
    ];
    
    // سرعت حداکثر سفر (کیلومتر بر ساعت)
    private const MAX_TRAVEL_SPEED_KMH = 900; // سرعت هواپیما

    public function __construct(
        Database $db,
        RiskPolicyService $policy,
        Logger $logger
    ) {
        $this->db = $db;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    /**
     * تشخیص سفر غیرممکن (Impossible Travel)
     * 
     * اگر کاربر در زمان کوتاه از دو مکان دور از هم لاگین کند
     */
    public function detectImpossibleTravel(
        int $userId,
        string $currentIp,
        array $currentLocation
    ): array {
        // دریافت آخرین لاگین
        $lastLogin = $this->getLastLogin($userId);
        
        if (!$lastLogin) {
            return [
                'is_impossible' => false,
                'reason' => 'اولین لاگین یا داده کافی وجود ندارد'
            ];
        }
        
        // محاسبه فاصله
        $distance = $this->calculateDistance(
            $lastLogin['latitude'],
            $lastLogin['longitude'],
            $currentLocation['latitude'],
            $currentLocation['longitude']
        );
        
        // محاسبه زمان
        $timeDiffSeconds = time() - strtotime($lastLogin['login_at']);
        $timeDiffHours = $timeDiffSeconds / 3600;
        
        // سرعت لازم برای سفر
        $requiredSpeed = $timeDiffHours > 0 ? ($distance / $timeDiffHours) : PHP_FLOAT_MAX;
        
        $maxSpeed = $this->policy->getInt('fraud', 'geo.max_travel_speed_kmh', self::MAX_TRAVEL_SPEED_KMH);
        
        $isImpossible = $requiredSpeed > $maxSpeed;
        
        if ($isImpossible) {
            $this->logImpossibleTravel($userId, [
                'previous_location' => [
                    'country' => $lastLogin['country'],
                    'city' => $lastLogin['city'],
                    'ip' => $lastLogin['ip_address']
                ],
                'current_location' => [
                    'country' => $currentLocation['country'],
                    'city' => $currentLocation['city'],
                    'ip' => $currentIp
                ],
                'distance_km' => round($distance, 2),
                'time_diff_hours' => round($timeDiffHours, 2),
                'required_speed_kmh' => round($requiredSpeed, 2),
                'max_allowed_speed_kmh' => $maxSpeed
            ]);
        }
        
        return [
            'is_impossible' => $isImpossible,
            'distance_km' => round($distance, 2),
            'time_diff_hours' => round($timeDiffHours, 2),
            'required_speed_kmh' => round($requiredSpeed, 2),
            'max_allowed_speed_kmh' => $maxSpeed,
            'risk_score' => $isImpossible ? 90 : 0,
            'previous_location' => [
                'country' => $lastLogin['country'],
                'city' => $lastLogin['city'],
                'ip' => $lastLogin['ip_address']
            ],
            'current_location' => $currentLocation
        ];
    }

    /**
     * محاسبه فاصله بین دو نقطه جغرافیایی (Haversine formula)
     * 
     * @return float فاصله به کیلومتر
     */
    private function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371; // کیلومتر
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    /**
     * دریافت آخرین لاگین با اطلاعات جغرافیایی
     */
    private function getLastLogin(int $userId): ?array
    {
        $sql = "SELECT ip_address, country, city, latitude, longitude, created_at as login_at
                FROM user_sessions 
                WHERE user_id = ? 
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                ORDER BY created_at DESC 
                LIMIT 1 OFFSET 1"; // OFFSET 1 برای گرفتن لاگین قبلی (نه فعلی)
        
        $result = $this->db->fetch($sql, [$userId]);
        
        return $result ? (array)$result : null;
    }

    /**
     * امتیازدهی ریسک بر اساس کشور
     */
    public function getCountryRiskScore(string $countryCode): int
    {
        // بررسی تنظیمات سفارشی
        $customScore = $this->policy->getInt('fraud', "geo.country_risk.{$countryCode}", null);
        
        if ($customScore !== null) {
            return $customScore;
        }
        
        // استفاده از جدول پیش‌فرض
        return self::COUNTRY_RISK_SCORES[$countryCode] ?? 30; // پیش‌فرض: متوسط
    }

    /**
     * تحلیل سرعت جغرافیایی (Geo-Velocity)
     * 
     * بررسی چند لاگین اخیر برای الگوهای مشکوک
     */
    public function analyzeGeoVelocity(int $userId, int $lookbackHours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($lookbackHours * 3600));
        
        $sql = "SELECT ip_address, country, city, latitude, longitude, created_at
                FROM user_sessions 
                WHERE user_id = ? 
                AND created_at >= ?
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                ORDER BY created_at ASC";
        
        $sessions = $this->db->fetchAll($sql, [$userId, $since]);
        
        if (count($sessions) < 2) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل وجود ندارد',
                'locations_count' => count($sessions)
            ];
        }
        
        $anomalies = [];
        $totalDistance = 0;
        $maxSpeed = 0;
        $uniqueCountries = [];
        
        for ($i = 1; $i < count($sessions); $i++) {
            $prev = $sessions[$i - 1];
            $curr = $sessions[$i];
            
            $distance = $this->calculateDistance(
                $prev->latitude,
                $prev->longitude,
                $curr->latitude,
                $curr->longitude
            );
            
            $timeDiff = strtotime($curr->created_at) - strtotime($prev->created_at);
            $speed = $timeDiff > 0 ? ($distance / ($timeDiff / 3600)) : 0;
            
            $totalDistance += $distance;
            $maxSpeed = max($maxSpeed, $speed);
            
            $uniqueCountries[$prev->country] = true;
            $uniqueCountries[$curr->country] = true;
            
            if ($speed > self::MAX_TRAVEL_SPEED_KMH) {
                $anomalies[] = [
                    'type' => 'high_speed',
                    'from' => [
                        'country' => $prev->country,
                        'city' => $prev->city,
                        'time' => $prev->created_at
                    ],
                    'to' => [
                        'country' => $curr->country,
                        'city' => $curr->city,
                        'time' => $curr->created_at
                    ],
                    'distance_km' => round($distance, 2),
                    'speed_kmh' => round($speed, 2)
                ];
            }
        }
        
        $countriesCount = count($uniqueCountries);
        $isSuspicious = !empty($anomalies) || $countriesCount > 3;
        
        return [
            'is_suspicious' => $isSuspicious,
            'locations_count' => count($sessions),
            'unique_countries' => $countriesCount,
            'total_distance_km' => round($totalDistance, 2),
            'max_speed_kmh' => round($maxSpeed, 2),
            'anomalies' => $anomalies,
            'risk_score' => $this->calculateVelocityRiskScore($anomalies, $countriesCount)
        ];
    }

    /**
     * محاسبه امتیاز ریسک بر اساس velocity
     */
    private function calculateVelocityRiskScore(array $anomalies, int $countriesCount): int
    {
        $score = 0;
        
        // هر anomaly = 30 امتیاز
        $score += count($anomalies) * 30;
        
        // هر کشور اضافی بیش از 2 = 15 امتیاز
        if ($countriesCount > 2) {
            $score += ($countriesCount - 2) * 15;
        }
        
        return min(100, $score);
    }

    /**
     * تشخیص ناهماهنگی Timezone
     * 
     * مثلاً IP از کشور A ولی timezone مرورگر از کشور B
     */
    public function detectTimezoneAnomaly(
        string $ipTimezone,
        string $browserTimezone
    ): array {
        // تبدیل timezone به offset
        $ipOffset = $this->getTimezoneOffset($ipTimezone);
        $browserOffset = $this->getTimezoneOffset($browserTimezone);
        
        if ($ipOffset === null || $browserOffset === null) {
            return [
                'is_anomaly' => false,
                'reason' => 'اطلاعات timezone ناقص است'
            ];
        }
        
        // اختلاف بیش از 2 ساعت = مشکوک
        $difference = abs($ipOffset - $browserOffset);
        $isAnomaly = $difference > 2;
        
        return [
            'is_anomaly' => $isAnomaly,
            'ip_timezone' => $ipTimezone,
            'browser_timezone' => $browserTimezone,
            'offset_difference_hours' => $difference,
            'risk_score' => $isAnomaly ? 40 : 0
        ];
    }

    /**
     * دریافت offset timezone (ساعت)
     */
    private function getTimezoneOffset(string $timezone): ?float
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $offset = $tz->getOffset(new \DateTime());
            return $offset / 3600; // تبدیل ثانیه به ساعت
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Geolocation Lookup با چند Provider
     * 
     * اولویت: Local DB > MaxMind > IP-API > IPInfo
     */
    public function lookup(string $ip): ?array
    {
        // 1. بررسی کش محلی
        $cached = $this->getFromCache($ip);
        if ($cached) {
            return $cached;
        }
        
        // 2. استفاده از MaxMind (اگر نصب باشد)
        $location = $this->lookupMaxMind($ip);
        
        // 3. Fallback به IP-API (رایگان)
        if (!$location) {
            $location = $this->lookupIPAPI($ip);
        }
        
        // 4. ذخیره در کش
        if ($location) {
            $this->saveToCache($ip, $location);
        }
        
        return $location;
    }

    /**
     * MaxMind GeoIP2 Lookup (نیاز به نصب کتابخانه)
     */
    private function lookupMaxMind(string $ip): ?array
    {
        // بررسی وجود MaxMind Reader
        $dbPath = __DIR__ . '/../../../storage/geoip/GeoLite2-City.mmdb';
        
        if (!file_exists($dbPath)) {
            return null;
        }
        
        try {
            $reader = new \GeoIp2\Database\Reader($dbPath);
            $record = $reader->city($ip);
            
            return [
                'country_code' => $record->country->isoCode,
                'country_name' => $record->country->name,
                'city' => $record->city->name,
                'latitude' => $record->location->latitude,
                'longitude' => $record->location->longitude,
                'timezone' => $record->location->timeZone,
                'source' => 'maxmind'
            ];
        } catch (\Exception $e) {
            $this->logger->error('geo.maxmind.failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * IP-API Lookup (رایگان، محدودیت 45 request/minute)
     */
    private function lookupIPAPI(string $ip): ?array
    {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,lat,lon,timezone";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if ($data['status'] !== 'success') {
                return null;
            }
            
            return [
                'country_code' => $data['countryCode'],
                'country_name' => $data['country'],
                'city' => $data['city'],
                'latitude' => $data['lat'],
                'longitude' => $data['lon'],
                'timezone' => $data['timezone'],
                'source' => 'ip-api'
            ];
        } catch (\Exception $e) {
            $this->logger->error('geo.ipapi.failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * دریافت از کش
     */
    private function getFromCache(string $ip): ?array
    {
        $result = $this->db->fetch(
            "SELECT * FROM geo_ip_cache 
             WHERE ip_address = ? 
             AND expires_at > NOW()",
            [$ip]
        );
        
        if (!$result) {
            return null;
        }
        
        return [
            'country_code' => $result->country_code,
            'country_name' => $result->country_name,
            'city' => $result->city,
            'latitude' => (float)$result->latitude,
            'longitude' => (float)$result->longitude,
            'timezone' => $result->timezone,
            'source' => 'cache'
        ];
    }

    /**
     * ذخیره در کش
     */
    private function saveToCache(string $ip, array $location): void
    {
        $sql = "INSERT INTO geo_ip_cache 
                (ip_address, country_code, country_name, city, latitude, longitude, timezone, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
                ON DUPLICATE KEY UPDATE
                country_code = VALUES(country_code),
                country_name = VALUES(country_name),
                city = VALUES(city),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                timezone = VALUES(timezone),
                expires_at = VALUES(expires_at)";
        
        $this->db->query($sql, [
            $ip,
            $location['country_code'],
            $location['country_name'],
            $location['city'],
            $location['latitude'],
            $location['longitude'],
            $location['timezone']
        ]);
    }

    /**
     * لاگ سفر غیرممکن
     */
    private function logImpossibleTravel(int $userId, array $details): void
    {
        $sql = "INSERT INTO fraud_logs 
                (user_id, fraud_type, risk_score, details, created_at)
                VALUES (?, 'impossible_travel', 90, ?, NOW())";
        
        $this->db->query($sql, [$userId, json_encode($details, JSON_UNESCAPED_UNICODE)]);
        
        $this->logger->critical('fraud.impossible_travel.detected', [
            'user_id' => $userId,
            'details' => $details
        ]);
    }

    /**
     * تحلیل جامع جغرافیایی
     */
    public function analyze(int $userId, string $ip, array $browserData = []): array
    {
        $location = $this->lookup($ip);
        
        if (!$location) {
            return [
                'success' => false,
                'error' => 'عدم موفقیت در تشخیص موقعیت جغرافیایی'
            ];
        }
        
        $analysis = [
            'location' => $location,
            'country_risk_score' => $this->getCountryRiskScore($location['country_code']),
            'impossible_travel' => $this->detectImpossibleTravel($userId, $ip, $location),
            'velocity_analysis' => $this->analyzeGeoVelocity($userId, 24),
        ];
        
        // بررسی timezone anomaly
        if (isset($browserData['timezone'])) {
            $analysis['timezone_anomaly'] = $this->detectTimezoneAnomaly(
                $location['timezone'],
                $browserData['timezone']
            );
        }
        
        // محاسبه امتیاز کلی
        $totalRisk = $analysis['country_risk_score']
                   + $analysis['impossible_travel']['risk_score']
                   + $analysis['velocity_analysis']['risk_score']
                   + ($analysis['timezone_anomaly']['risk_score'] ?? 0);
        
        $analysis['total_risk_score'] = min(100, $totalRisk);
        $analysis['is_high_risk'] = $totalRisk >= 70;
        
        return $analysis;
    }

    /**
     * پاکسازی کش قدیمی
     */
    public function cleanupCache(): int
    {
        $result = $this->db->query(
            "DELETE FROM geo_ip_cache WHERE expires_at < NOW()"
        );
        
        return $result ? 1 : 0;
    }
}
