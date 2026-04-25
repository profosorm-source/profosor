<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Logger;

/**
 * EmailPhoneIntelligenceService
 * 
 * تحلیل هوشمند ایمیل و شماره تلفن
 * 
 * Features:
 * - Disposable email detection
 * - Email domain reputation
 * - SMTP/MX validation
 * - VOIP phone detection
 * - Phone number validation
 * - Free email provider detection
 */
class EmailPhoneIntelligenceService
{
    private Database $db;
    private Logger $logger;
    
    // لیست دامین‌های disposable شناخته شده (نمونه)
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com', 'guerrillamail.com', '10minutemail.com', 'mailinator.com',
        'throwaway.email', 'temp-mail.org', 'maildrop.cc', 'getnada.com',
        'mohmal.com', 'dispostable.com', 'yopmail.com', 'fakeinbox.com'
    ];
    
    // لیست سرویس‌دهنده‌های رایگان
    private const FREE_EMAIL_PROVIDERS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'icloud.com', 'mail.com', 'protonmail.com', 'gmx.com', 'zoho.com'
    ];

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * تحلیل کامل ایمیل
     */
    public function analyzeEmail(string $email): array
    {
        $email = strtolower(trim($email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'is_valid' => false,
                'error' => 'فرمت ایمیل نامعتبر است'
            ];
        }
        
        [$localPart, $domain] = explode('@', $email);
        
        // بررسی کش
        $cached = $this->getEmailFromCache($email);
        if ($cached) {
            return $cached;
        }
        
        $analysis = [
            'email' => $email,
            'domain' => $domain,
            'local_part' => $localPart,
            'is_valid' => true
        ];
        
        // 1. بررسی Disposable
        $analysis['is_disposable'] = $this->isDisposableEmail($domain);
        
        // 2. بررسی Free Provider
        $analysis['is_free_provider'] = $this->isFreeEmailProvider($domain);
        
        // 3. بررسی MX Records
        $mxCheck = $this->checkMXRecords($domain);
        $analysis['mx_records_valid'] = $mxCheck['valid'];
        $analysis['mx_records'] = $mxCheck['records'];
        
        // 4. بررسی SMTP (اختیاری - کند است)
        // $analysis['smtp_valid'] = $this->checkSMTP($email);
        
        // 5. محاسبه Risk Score
        $analysis['risk_score'] = $this->calculateEmailRiskScore($analysis);
        $analysis['is_suspicious'] = $analysis['risk_score'] >= 60;
        
        // ذخیره در کش
        $this->saveEmailToCache($email, $domain, $analysis);
        
        return $analysis;
    }

    /**
     * بررسی Disposable Email
     */
    private function isDisposableEmail(string $domain): bool
    {
        // بررسی لیست داخلی
        if (in_array($domain, self::DISPOSABLE_DOMAINS)) {
            return true;
        }
        
        // بررسی دیتابیس
        $result = $this->db->fetch(
            "SELECT is_disposable FROM email_intelligence WHERE domain = ?",
            [$domain]
        );
        
        if ($result && $result->is_disposable) {
            return true;
        }
        
        // استفاده از API خارجی (اختیاری)
        // $apiResult = $this->checkDisposableAPI($domain);
        
        return false;
    }

    /**
     * بررسی Free Email Provider
     */
    private function isFreeEmailProvider(string $domain): bool
    {
        return in_array($domain, self::FREE_EMAIL_PROVIDERS);
    }

    /**
     * بررسی MX Records
     */
    private function checkMXRecords(string $domain): array
    {
        $mxRecords = [];
        $valid = @getmxrr($domain, $mxRecords);
        
        return [
            'valid' => $valid && !empty($mxRecords),
            'records' => $mxRecords
        ];
    }

    /**
     * بررسی SMTP (اتصال واقعی - استفاده با احتیاط)
     */
    private function checkSMTP(string $email): bool
    {
        // این متد کند است و ممکن است توسط برخی سرورها بلاک شود
        // فقط برای موارد بحرانی استفاده شود
        
        [$localPart, $domain] = explode('@', $email);
        
        $mxRecords = [];
        if (!getmxrr($domain, $mxRecords)) {
            return false;
        }
        
        $mxHost = $mxRecords[0];
        
        try {
            $socket = @fsockopen($mxHost, 25, $errno, $errstr, 5);
            if (!$socket) {
                return false;
            }
            
            // SMTP conversation
            fgets($socket, 1024); // Banner
            fputs($socket, "HELO example.com\r\n");
            fgets($socket, 1024);
            fputs($socket, "MAIL FROM: <test@example.com>\r\n");
            fgets($socket, 1024);
            fputs($socket, "RCPT TO: <{$email}>\r\n");
            $response = fgets($socket, 1024);
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            // اگر 250 برگشت = معتبر
            return strpos($response, '250') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * محاسبه Email Risk Score
     */
    private function calculateEmailRiskScore(array $analysis): int
    {
        $score = 0;
        
        if ($analysis['is_disposable']) {
            $score += 80; // Disposable = خیلی پرخطر
        }
        
        if ($analysis['is_free_provider']) {
            $score += 15; // Free provider = کمی مشکوک
        }
        
        if (!$analysis['mx_records_valid']) {
            $score += 70; // MX نامعتبر = خیلی مشکوک
        }
        
        // بررسی local part مشکوک
        $localPart = $analysis['local_part'];
        if (strlen($localPart) < 3 || strlen($localPart) > 50) {
            $score += 20;
        }
        
        // حاوی اعداد زیاد = مشکوک
        if (preg_match_all('/\d/', $localPart) > 5) {
            $score += 15;
        }
        
        return min(100, $score);
    }

    /**
     * تحلیل شماره تلفن
     */
    public function analyzePhone(string $phone): array
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (empty($phone)) {
            return [
                'is_valid' => false,
                'error' => 'فرمت شماره تلفن نامعتبر است'
            ];
        }
        
        // بررسی کش
        $cached = $this->getPhoneFromCache($phone);
        if ($cached) {
            return $cached;
        }
        
        $analysis = [
            'phone' => $phone,
            'is_valid' => true
        ];
        
        // استخراج کد کشور
        $analysis['country_code'] = $this->extractCountryCode($phone);
        
        // بررسی VOIP (نیاز به API خارجی)
        // $analysis['is_voip'] = $this->checkVOIP($phone);
        $analysis['is_voip'] = false; // موقتاً
        
        // بررسی Line Type (نیاز به API)
        // $analysis['line_type'] = $this->getLineType($phone);
        $analysis['line_type'] = 'unknown';
        
        // محاسبه Risk Score
        $analysis['risk_score'] = $this->calculatePhoneRiskScore($analysis);
        $analysis['is_suspicious'] = $analysis['risk_score'] >= 60;
        
        // ذخیره در کش
        $this->savePhoneToCache($phone, $analysis);
        
        return $analysis;
    }

    /**
     * استخراج کد کشور
     */
    private function extractCountryCode(string $phone): ?string
    {
        // ساده‌سازی شده - در واقعیت نیاز به کتابخانه libphonenumber دارد
        
        if (strpos($phone, '+98') === 0) {
            return 'IR';
        } elseif (strpos($phone, '+1') === 0) {
            return 'US';
        } elseif (strpos($phone, '+44') === 0) {
            return 'GB';
        }
        
        return null;
    }

    /**
     * بررسی VOIP Number (نیاز به API خارجی)
     */
    private function checkVOIP(string $phone): bool
    {
        // استفاده از API هایی مثل Twilio Lookup یا NumVerify
        // این فقط نمونه است
        
        return false;
    }

    /**
     * محاسبه Phone Risk Score
     */
    private function calculatePhoneRiskScore(array $analysis): int
    {
        $score = 0;
        
        if ($analysis['is_voip']) {
            $score += 60; // VOIP = مشکوک
        }
        
        if ($analysis['line_type'] === 'voip') {
            $score += 50;
        }
        
        // شماره خیلی کوتاه یا خیلی بلند
        $length = strlen($analysis['phone']);
        if ($length < 8 || $length > 15) {
            $score += 30;
        }
        
        return min(100, $score);
    }

    /**
     * دریافت ایمیل از کش
     */
    private function getEmailFromCache(string $email): ?array
    {
        $result = $this->db->fetch(
            "SELECT * FROM email_intelligence 
             WHERE email = ? 
             AND last_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$email]
        );
        
        if (!$result) {
            return null;
        }
        
        return [
            'email' => $result->email,
            'domain' => $result->domain,
            'is_disposable' => (bool)$result->is_disposable,
            'is_free_provider' => (bool)$result->is_free_provider,
            'mx_records_valid' => (bool)$result->mx_records_valid,
            'domain_reputation_score' => (int)$result->domain_reputation_score,
            'risk_score' => $this->calculateEmailRiskScore([
                'is_disposable' => (bool)$result->is_disposable,
                'is_free_provider' => (bool)$result->is_free_provider,
                'mx_records_valid' => (bool)$result->mx_records_valid,
                'local_part' => explode('@', $email)[0]
            ]),
            'from_cache' => true
        ];
    }

    /**
     * ذخیره ایمیل در کش
     */
    private function saveEmailToCache(string $email, string $domain, array $analysis): void
    {
        $sql = "INSERT INTO email_intelligence 
                (email, domain, is_disposable, is_free_provider, mx_records_valid, last_checked_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                is_disposable = VALUES(is_disposable),
                is_free_provider = VALUES(is_free_provider),
                mx_records_valid = VALUES(mx_records_valid),
                last_checked_at = NOW()";
        
        $this->db->query($sql, [
            $email,
            $domain,
            $analysis['is_disposable'],
            $analysis['is_free_provider'],
            $analysis['mx_records_valid']
        ]);
    }

    /**
     * دریافت شماره از کش
     */
    private function getPhoneFromCache(string $phone): ?array
    {
        $result = $this->db->fetch(
            "SELECT * FROM phone_intelligence 
             WHERE phone = ? 
             AND last_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$phone]
        );
        
        if (!$result) {
            return null;
        }
        
        return [
            'phone' => $result->phone,
            'country_code' => $result->country_code,
            'carrier' => $result->carrier,
            'line_type' => $result->line_type,
            'is_voip' => (bool)$result->is_voip,
            'is_valid' => (bool)$result->is_valid,
            'from_cache' => true
        ];
    }

    /**
     * ذخیره شماره در کش
     */
    private function savePhoneToCache(string $phone, array $analysis): void
    {
        $sql = "INSERT INTO phone_intelligence 
                (phone, country_code, line_type, is_voip, is_valid, last_checked_at)
                VALUES (?, ?, ?, ?, TRUE, NOW())
                ON DUPLICATE KEY UPDATE
                country_code = VALUES(country_code),
                line_type = VALUES(line_type),
                is_voip = VALUES(is_voip),
                last_checked_at = NOW()";
        
        $this->db->query($sql, [
            $phone,
            $analysis['country_code'],
            $analysis['line_type'],
            $analysis['is_voip']
        ]);
    }

    /**
     * به‌روزرسانی Disposable List از منبع خارجی
     */
    public function updateDisposableList(): int
    {
        // دانلود لیست از منابعی مثل:
        // https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt
        
        try {
            $url = 'https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt';
            $content = @file_get_contents($url);
            
            if (!$content) {
                return 0;
            }
            
            $domains = array_filter(array_map('trim', explode("\n", $content)));
            $inserted = 0;
            
            foreach ($domains as $domain) {
                $sql = "INSERT INTO email_intelligence (email, domain, is_disposable, last_checked_at)
                        VALUES (CONCAT('unknown@', ?), ?, TRUE, NOW())
                        ON DUPLICATE KEY UPDATE is_disposable = TRUE";
                
                if ($this->db->query($sql, [$domain, $domain])) {
                    $inserted++;
                }
            }
            
            $this->logger->info('email.disposable_list.updated', [
                'count' => $inserted
            ]);
            
            return $inserted;
        } catch (\Exception $e) {
            $this->logger->error('email.disposable_list.update_failed', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * پاکسازی کش قدیمی
     */
    public function cleanupCache(): int
    {
        $deleted = 0;
        
        $result = $this->db->query(
            "DELETE FROM email_intelligence 
             WHERE last_checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $deleted += $result ? 1 : 0;
        
        $result = $this->db->query(
            "DELETE FROM phone_intelligence 
             WHERE last_checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $deleted += $result ? 1 : 0;
        
        return $deleted;
    }
}
