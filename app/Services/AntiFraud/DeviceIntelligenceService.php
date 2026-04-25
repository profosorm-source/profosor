<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Logger;

/**
 * DeviceIntelligenceService
 * 
 * تحلیل هوشمند دستگاه برای تشخیص Emulator، VM، و دستگاه‌های مشکوک
 * 
 * Features:
 * - Emulator Detection (Android/iOS)
 * - Virtual Machine Detection
 * - Rooted/Jailbroken Device Detection
 * - Screen Resolution Fraud Detection
 * - Browser Automation Detection (Puppeteer, Selenium, etc.)
 * - Advanced Device Fingerprinting
 */
class DeviceIntelligenceService
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * تشخیص Emulator (شبیه‌ساز موبایل)
     */
    public function detectEmulator(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        // بررسی User Agent
        $ua = $deviceInfo['user_agent'] ?? '';
        
        // نشانه‌های شناخته شده emulator
        $emulatorSigns = [
            'Android SDK built for x86',
            'Genymotion',
            'Andy',
            'Bluestacks',
            'NoxPlayer',
            'MEmu',
            'LDPlayer',
            'Emulator',
            'generic_x86',
            'google_sdk'
        ];
        
        foreach ($emulatorSigns as $sign) {
            if (stripos($ua, $sign) !== false) {
                $suspiciousReasons[] = "User Agent حاوی نشانه Emulator: {$sign}";
                $riskScore += 80;
                break;
            }
        }
        
        // بررسی Hardware Concurrency (تعداد هسته CPU)
        // Emulator ها معمولاً تعداد هسته‌های غیرواقعی دارند
        $cores = $deviceInfo['hardware_concurrency'] ?? null;
        if ($cores !== null) {
            if ($cores > 16) {
                $suspiciousReasons[] = 'تعداد هسته CPU غیرمعمول بالا';
                $riskScore += 30;
            } elseif ($cores === 1) {
                $suspiciousReasons[] = 'تعداد هسته CPU خیلی کم (احتمال Emulator)';
                $riskScore += 25;
            }
        }
        
        // بررسی Device Memory
        $memory = $deviceInfo['device_memory'] ?? null;
        if ($memory !== null) {
            // Emulator ها معمولاً حافظه استاندارد دارند (2, 4, 8 GB)
            if ($memory < 1) {
                $suspiciousReasons[] = 'حافظه دستگاه خیلی کم';
                $riskScore += 20;
            }
        }
        
        // بررسی Platform
        $platform = $deviceInfo['platform'] ?? '';
        if ($platform === 'Linux x86_64' && stripos($ua, 'Android') !== false) {
            $suspiciousReasons[] = 'Platform مشکوک برای Android';
            $riskScore += 40;
        }
        
        // بررسی Touch Support
        // اگر Android ولی Touch ندارد = Emulator
        $touchSupport = $deviceInfo['touch_support'] ?? false;
        if (stripos($ua, 'Android') !== false && !$touchSupport) {
            $suspiciousReasons[] = 'دستگاه Android بدون قابلیت Touch';
            $riskScore += 50;
        }
        
        // بررسی WebGL Vendor
        $webglVendor = $deviceInfo['webgl_vendor'] ?? '';
        $vmVendors = ['VMware', 'VirtualBox', 'Parallels'];
        foreach ($vmVendors as $vendor) {
            if (stripos($webglVendor, $vendor) !== false) {
                $suspiciousReasons[] = "WebGL Vendor نشان‌دهنده VM: {$vendor}";
                $riskScore += 60;
                break;
            }
        }
        
        return [
            'is_emulator' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'confidence' => $this->calculateConfidence($riskScore)
        ];
    }

    /**
     * تشخیص Virtual Machine (ماشین مجازی)
     */
    public function detectVirtualMachine(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $ua = $deviceInfo['user_agent'] ?? '';
        $webglVendor = $deviceInfo['webgl_vendor'] ?? '';
        $webglRenderer = $deviceInfo['webgl_renderer'] ?? '';
        
        // نشانه‌های VM
        $vmSigns = [
            'VMware' => 70,
            'VirtualBox' => 70,
            'Parallels' => 60,
            'QEMU' => 65,
            'Xen' => 60,
            'KVM' => 55,
            'Hyper-V' => 50
        ];
        
        foreach ($vmSigns as $sign => $score) {
            if (stripos($webglVendor . ' ' . $webglRenderer, $sign) !== false) {
                $suspiciousReasons[] = "نشانه VM در WebGL: {$sign}";
                $riskScore += $score;
                break;
            }
        }
        
        // بررسی Screen Resolution غیرمعمول
        $screen = $deviceInfo['screen'] ?? '';
        if (preg_match('/(\d+)x(\d+)/', $screen, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];
            
            // VM ها معمولاً resolution های خاص دارند
            $vmResolutions = [
                '800x600', '1024x768', '1280x720', '1280x800'
            ];
            
            if (in_array("{$width}x{$height}", $vmResolutions)) {
                $suspiciousReasons[] = 'Resolution مشکوک (معمول در VM)';
                $riskScore += 25;
            }
        }
        
        return [
            'is_vm' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons
        ];
    }

    /**
     * تشخیص Rooted/Jailbroken Device
     * 
     * توجه: این فقط با JavaScript محدود است و نیاز به Native SDK دارد
     * اینجا فقط نشانه‌های مشکوک بررسی می‌شود
     */
    public function detectRootedDevice(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        // بررسی JavaScript API های غیرمعمول
        $suspiciousAPIs = $deviceInfo['suspicious_apis'] ?? [];
        
        // برخی API های که در دستگاه‌های Root شده وجود دارند
        $rootIndicators = [
            'su', 'busybox', 'Superuser', 'Magisk', 'Xposed'
        ];
        
        foreach ($rootIndicators as $indicator) {
            if (in_array($indicator, $suspiciousAPIs)) {
                $suspiciousReasons[] = "نشانه Root: {$indicator}";
                $riskScore += 40;
            }
        }
        
        // بررسی تعداد زیاد Plugins (نشانه تغییرات)
        $pluginsCount = $deviceInfo['plugins_count'] ?? 0;
        if ($pluginsCount > 20) {
            $suspiciousReasons[] = 'تعداد زیاد Plugin های مرورگر';
            $riskScore += 20;
        }
        
        return [
            'is_rooted' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'note' => 'تشخیص دقیق Root نیاز به Native SDK دارد'
        ];
    }

    /**
     * تشخیص Browser Automation (Puppeteer, Selenium, etc.)
     */
    public function detectAutomation(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        // 1. بررسی navigator.webdriver
        if ($deviceInfo['webdriver'] ?? false) {
            $suspiciousReasons[] = 'navigator.webdriver = true (Selenium/Puppeteer)';
            $riskScore += 90;
        }
        
        // 2. بررسی window.chrome غیرموجود در Chrome
        $ua = $deviceInfo['user_agent'] ?? '';
        $hasChrome = stripos($ua, 'Chrome') !== false;
        $hasChromeObject = $deviceInfo['has_chrome_object'] ?? true;
        
        if ($hasChrome && !$hasChromeObject) {
            $suspiciousReasons[] = 'Chrome بدون window.chrome (Headless)';
            $riskScore += 70;
        }
        
        // 3. بررسی Plugins = 0 (Headless Chrome)
        $pluginsCount = $deviceInfo['plugins_count'] ?? null;
        if ($pluginsCount === 0 && !($deviceInfo['is_mobile'] ?? false)) {
            $suspiciousReasons[] = 'عدم وجود Plugin (Headless Browser)';
            $riskScore += 60;
        }
        
        // 4. بررسی Languages خالی
        $languages = $deviceInfo['languages'] ?? [];
        if (empty($languages)) {
            $suspiciousReasons[] = 'navigator.languages خالی';
            $riskScore += 50;
        }
        
        // 5. بررسی Permissions API
        if (!($deviceInfo['has_permissions_api'] ?? true)) {
            $suspiciousReasons[] = 'عدم وجود Permissions API (Headless)';
            $riskScore += 40;
        }
        
        // 6. بررسی User-Agent Mismatch
        $platformUA = $this->extractPlatformFromUA($ua);
        $platformNavigator = $deviceInfo['platform'] ?? '';
        
        if ($platformUA && $platformNavigator && $platformUA !== $platformNavigator) {
            $suspiciousReasons[] = 'عدم تطابق Platform در UA و Navigator';
            $riskScore += 45;
        }
        
        // 7. بررسی نشانه‌های خاص
        $automationSigns = [
            'HeadlessChrome',
            'PhantomJS',
            'Nightmare',
            'CasperJS'
        ];
        
        foreach ($automationSigns as $sign) {
            if (stripos($ua, $sign) !== false) {
                $suspiciousReasons[] = "نشانه Automation در UA: {$sign}";
                $riskScore += 85;
                break;
            }
        }
        
        return [
            'is_automation' => $riskScore >= 60,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'confidence' => $this->calculateConfidence($riskScore)
        ];
    }

    /**
     * تشخیص Screen Resolution Fraud
     * 
     * بررسی تطابق Screen, Inner Width/Height و Device Pixel Ratio
     */
    public function detectResolutionFraud(array $deviceInfo): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $screenWidth = $deviceInfo['screen_width'] ?? 0;
        $screenHeight = $deviceInfo['screen_height'] ?? 0;
        $innerWidth = $deviceInfo['inner_width'] ?? 0;
        $innerHeight = $deviceInfo['inner_height'] ?? 0;
        $dpr = $deviceInfo['device_pixel_ratio'] ?? 1;
        
        // بررسی Inner بزرگتر از Screen (غیرممکن!)
        if ($innerWidth > $screenWidth || $innerHeight > $screenHeight) {
            $suspiciousReasons[] = 'Inner dimension بزرگتر از Screen (جعل)';
            $riskScore += 80;
        }
        
        // بررسی DPR غیرمعمول
        if ($dpr < 0.5 || $dpr > 5) {
            $suspiciousReasons[] = 'Device Pixel Ratio غیرمعمول';
            $riskScore += 40;
        }
        
        // بررسی Resolution های خیلی کوچک یا خیلی بزرگ
        if ($screenWidth < 320 || $screenHeight < 240) {
            $suspiciousReasons[] = 'Screen resolution خیلی کوچک';
            $riskScore += 30;
        }
        
        if ($screenWidth > 7680 || $screenHeight > 4320) { // 8K
            $suspiciousReasons[] = 'Screen resolution غیرمعمول بزرگ';
            $riskScore += 25;
        }
        
        return [
            'is_fraudulent' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons
        ];
    }

    /**
     * تحلیل جامع دستگاه
     */
    public function comprehensiveAnalysis(array $deviceInfo): array
    {
        $analyses = [
            'emulator' => $this->detectEmulator($deviceInfo),
            'vm' => $this->detectVirtualMachine($deviceInfo),
            'rooted' => $this->detectRootedDevice($deviceInfo),
            'automation' => $this->detectAutomation($deviceInfo),
            'resolution_fraud' => $this->detectResolutionFraud($deviceInfo)
        ];
        
        // محاسبه امتیاز کلی
        $totalRisk = 0;
        $highestRisk = 0;
        $criticalIssues = [];
        
        foreach ($analyses as $type => $result) {
            $risk = $result['risk_score'] ?? 0;
            $totalRisk += $risk;
            $highestRisk = max($highestRisk, $risk);
            
            if ($risk >= 70) {
                $criticalIssues[] = $type;
            }
        }
        
        $avgRisk = $totalRisk / count($analyses);
        
        return [
            'overall_risk_score' => round($avgRisk, 2),
            'highest_risk_score' => $highestRisk,
            'is_suspicious' => $highestRisk >= 60 || $avgRisk >= 50,
            'critical_issues' => $criticalIssues,
            'detailed_analyses' => $analyses,
            'recommendation' => $this->getRecommendation($highestRisk, $criticalIssues)
        ];
    }

    /**
     * ایجاد Fingerprint پیشرفته
     */
    public function generateAdvancedFingerprint(array $deviceInfo): string
    {
        $components = [
            'ua' => $deviceInfo['user_agent'] ?? '',
            'platform' => $deviceInfo['platform'] ?? '',
            'cores' => $deviceInfo['hardware_concurrency'] ?? 0,
            'memory' => $deviceInfo['device_memory'] ?? 0,
            'screen' => $deviceInfo['screen'] ?? '',
            'dpr' => $deviceInfo['device_pixel_ratio'] ?? 1,
            'timezone' => $deviceInfo['timezone'] ?? '',
            'language' => $deviceInfo['language'] ?? '',
            'languages' => implode(',', $deviceInfo['languages'] ?? []),
            'webgl_vendor' => $deviceInfo['webgl_vendor'] ?? '',
            'webgl_renderer' => $deviceInfo['webgl_renderer'] ?? '',
            'canvas' => $deviceInfo['canvas'] ?? '',
            'audio' => $deviceInfo['audio'] ?? '',
            'fonts' => $deviceInfo['fonts'] ?? '',
            'plugins' => $deviceInfo['plugins'] ?? '',
            'touch' => $deviceInfo['touch_support'] ?? false,
            'max_touch_points' => $deviceInfo['max_touch_points'] ?? 0,
            'color_depth' => $deviceInfo['color_depth'] ?? 0,
            'pixel_depth' => $deviceInfo['pixel_depth'] ?? 0
        ];
        
        // اضافه کردن Battery API (اگر موجود باشد)
        if (isset($deviceInfo['battery'])) {
            $components['battery_level'] = $deviceInfo['battery']['level'] ?? 0;
            $components['battery_charging'] = $deviceInfo['battery']['charging'] ?? false;
        }
        
        $fingerprint = hash('sha256', json_encode($components, JSON_UNESCAPED_UNICODE));
        
        return $fingerprint;
    }

    /**
     * ذخیره Device Intelligence
     */
    public function saveDeviceAnalysis(int $userId, array $deviceInfo, array $analysis): void
    {
        $fingerprint = $this->generateAdvancedFingerprint($deviceInfo);
        
        $sql = "INSERT INTO device_intelligence 
                (user_id, fingerprint, device_info, analysis_result, risk_score, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        
        $this->db->query($sql, [
            $userId,
            $fingerprint,
            json_encode($deviceInfo, JSON_UNESCAPED_UNICODE),
            json_encode($analysis, JSON_UNESCAPED_UNICODE),
            $analysis['overall_risk_score']
        ]);
        
        // لاگ در صورت مشکوک بودن
        if ($analysis['is_suspicious']) {
            $this->logger->warning('device.suspicious_detected', [
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
                'risk_score' => $analysis['overall_risk_score'],
                'critical_issues' => $analysis['critical_issues']
            ]);
        }
    }

    /**
     * بررسی تاریخچه دستگاه
     */
    public function getDeviceHistory(string $fingerprint): array
    {
        $sql = "SELECT COUNT(DISTINCT user_id) as user_count,
                       COUNT(*) as total_uses,
                       MAX(created_at) as last_seen,
                       AVG(risk_score) as avg_risk_score
                FROM device_intelligence 
                WHERE fingerprint = ?";
        
        $result = $this->db->fetch($sql, [$fingerprint]);
        
        if (!$result) {
            return [
                'is_new' => true,
                'user_count' => 0,
                'total_uses' => 0
            ];
        }
        
        return [
            'is_new' => false,
            'user_count' => (int)$result->user_count,
            'total_uses' => (int)$result->total_uses,
            'last_seen' => $result->last_seen,
            'avg_risk_score' => round((float)$result->avg_risk_score, 2),
            'is_shared' => (int)$result->user_count > 1
        ];
    }

    /**
     * استخراج Platform از User Agent
     */
    private function extractPlatformFromUA(string $ua): ?string
    {
        if (stripos($ua, 'Windows') !== false) {
            return 'Win32';
        } elseif (stripos($ua, 'Mac') !== false) {
            return 'MacIntel';
        } elseif (stripos($ua, 'Linux') !== false) {
            return 'Linux x86_64';
        } elseif (stripos($ua, 'Android') !== false) {
            return 'Linux armv7l';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            return 'iPhone';
        }
        
        return null;
    }

    /**
     * محاسبه Confidence
     */
    private function calculateConfidence(int $riskScore): string
    {
        if ($riskScore >= 80) {
            return 'very_high';
        } elseif ($riskScore >= 60) {
            return 'high';
        } elseif ($riskScore >= 40) {
            return 'medium';
        } elseif ($riskScore >= 20) {
            return 'low';
        }
        
        return 'very_low';
    }

    /**
     * توصیه اقدام
     */
    private function getRecommendation(int $highestRisk, array $criticalIssues): string
    {
        if ($highestRisk >= 80 || count($criticalIssues) >= 2) {
            return 'block';
        } elseif ($highestRisk >= 60 || count($criticalIssues) >= 1) {
            return 'challenge';
        } elseif ($highestRisk >= 40) {
            return 'monitor';
        }
        
        return 'allow';
    }
}
