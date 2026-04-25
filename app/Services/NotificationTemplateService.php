<?php

namespace App\Services;

use Core\Database;
use Core\Cache;
use Core\Logger;

/**
 * NotificationTemplateService — مدیریت قالب‌های نوتیفیکیشن
 *
 * ─── استراتژی ──────────────────────────────────────────────────────────────
 *  • قالب‌های پیش‌فرض (default) در کد تعریف شده‌اند
 *  • ادمین می‌تواند از طریق جدول notification_templates آن‌ها را override کند
 *  • کش Redis/File برای کاهش query
 *  • متغیرهای dynamic با {{variable}} تعریف می‌شوند
 *  • validation متغیرها قبل از ذخیره در DB
 */
class NotificationTemplateService
{
    private Database $db;
    private Cache    $cache;
    private Logger   $logger;

    private const CACHE_TTL    = 30; // دقیقه
    private const CACHE_PREFIX = 'notif_tpl:';

    /**
     * قالب‌های پیش‌فرض — ساختار:
     * 'type' => [
     *   'title'     => string,
     *   'message'   => string,
     *   'variables' => ['var' => 'توضیح فارسی'],
     * ]
     */
    private const DEFAULT_TEMPLATES = [
        'deposit' => [
            'title'     => 'واریز موفق ✅',
            'message'   => 'مبلغ {{amount}} {{currency}} با موفقیت به کیف پول شما واریز شد.',
            'variables' => [
                'amount'   => 'مبلغ واریز (فرمت‌شده)',
                'currency' => 'واحد ارز (مثلاً USDT)',
            ],
        ],
        'withdrawal' => [
            'title'     => 'برداشت تأیید شد 💸',
            'message'   => 'درخواست برداشت {{amount}} {{currency}} تأیید و پردازش شد.',
            'variables' => [
                'amount'   => 'مبلغ برداشت',
                'currency' => 'واحد ارز',
            ],
        ],
        'withdrawal_rejected' => [
            'title'     => 'برداشت رد شد ❌',
            'message'   => 'درخواست برداشت {{amount}} رد شد. دلیل: {{reason}}. مبلغ به کیف پول بازگشت.',
            'variables' => [
                'amount' => 'مبلغ برداشت',
                'reason' => 'دلیل رد',
            ],
        ],
        'task' => [
            'title'     => 'تسک جدید 📋',
            'message'   => 'تسک جدید «{{task_title}}» برای شما در دسترس است.',
            'variables' => [
                'task_title' => 'عنوان تسک',
            ],
        ],
        'kyc_approved' => [
            'title'     => 'احراز هویت تأیید شد ✅',
            'message'   => 'احراز هویت شما تأیید شد. اکنون می‌توانید از تمام امکانات سایت استفاده کنید.',
            'variables' => [],
        ],
        'kyc_rejected' => [
            'title'     => 'احراز هویت رد شد ❌',
            'message'   => 'احراز هویت شما رد شد. دلیل: {{reason}}. لطفاً مدارک را مجدداً ارسال کنید.',
            'variables' => [
                'reason' => 'دلیل رد',
            ],
        ],
        'lottery_winner' => [
            'title'     => '🎉 تبریک! برنده شدید!',
            'message'   => 'شما برنده قرعه‌کشی شدید! مبلغ {{amount}} به کیف پول شما واریز شد.',
            'variables' => [
                'amount' => 'مبلغ جایزه',
            ],
        ],
        'referral' => [
            'title'     => 'کمیسیون معرفی 💰',
            'message'   => 'از فعالیت «{{referred_user}}» مبلغ {{amount}} کمیسیون دریافت کردید.',
            'variables' => [
                'referred_user' => 'نام زیرمجموعه',
                'amount'        => 'مبلغ کمیسیون',
            ],
        ],
        'security' => [
            'title'     => '⚠️ هشدار امنیتی',
            'message'   => '{{message}}',
            'variables' => [
                'message' => 'متن هشدار',
                'ip'      => 'آدرس IP',
            ],
        ],
        'investment_completed' => [
            'title'     => 'سرمایه‌گذاری تکمیل شد 📈',
            'message'   => 'سرمایه‌گذاری شما به پایان رسید. سود: {{profit}} — مجموع: {{total}}.',
            'variables' => [
                'profit' => 'سود دریافتی',
                'total'  => 'مبلغ کل',
            ],
        ],
        'system' => [
            'title'     => '{{title}}',
            'message'   => '{{message}}',
            'variables' => [
                'title'   => 'عنوان پیام',
                'message' => 'متن پیام',
            ],
        ],
    ];

    public function __construct(Database $db, Logger $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;
        $this->cache  = Cache::getInstance();
    }

    /**
     * رندر یک template با متغیرها
     *
     * @param  string $templateKey  کلید template (مثلاً 'deposit')
     * @param  array  $vars         متغیرهای dynamic ['amount' => '1,000']
     * @return array{title:string, message:string}
     */
    public function render(string $templateKey, array $vars = []): array
    {
        $template = $this->get($templateKey);

        return [
            'title'   => $this->interpolate($template['title'],   $vars),
            'message' => $this->interpolate($template['message'], $vars),
        ];
    }

    /**
     * دریافت template — DB override > default
     */
    public function get(string $templateKey): array
    {
        $cacheKey = self::CACHE_PREFIX . $templateKey;

        // بررسی cache
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // بررسی DB override
        $dbTemplate = $this->getFromDb($templateKey);
        if ($dbTemplate) {
            $result = [
                'title'     => $dbTemplate->title,
                'message'   => $dbTemplate->message,
                'variables' => json_decode($dbTemplate->variables ?? '{}', true) ?: [],
            ];
            $this->cache->put($cacheKey, $result, self::CACHE_TTL);
            return $result;
        }

        // fallback به default
        $default = self::DEFAULT_TEMPLATES[$templateKey] ?? self::DEFAULT_TEMPLATES['system'];
        $this->cache->put($cacheKey, $default, self::CACHE_TTL);
        return $default;
    }

    /**
     * دریافت لیست همه template‌ها با متغیرهای آن‌ها (برای admin UI)
     */
    public function getAllWithVariables(): array
    {
        $result = [];

        foreach (self::DEFAULT_TEMPLATES as $key => $default) {
            $dbOverride = $this->getFromDb($key);

            $result[$key] = [
                'key'            => $key,
                'default_title'  => $default['title'],
                'default_message'=> $default['message'],
                'variables'      => $default['variables'],
                'has_override'   => (bool)$dbOverride,
                'override_title' => $dbOverride?->title,
                'override_message' => $dbOverride?->message,
            ];
        }

        return $result;
    }

    /**
     * ذخیره override در دیتابیس
     * - validation متغیرها قبل از ذخیره
     * - ادمین نمی‌تواند متغیر جدید اختراع کند
     */
    public function saveOverride(string $templateKey, string $title, string $message): array
    {
        // template باید در default وجود داشته باشد
        if (!isset(self::DEFAULT_TEMPLATES[$templateKey])) {
            return ['success' => false, 'error' => 'کلید template نامعتبر است.'];
        }

        $allowedVars = array_keys(self::DEFAULT_TEMPLATES[$templateKey]['variables']);

        // بررسی متغیرهای استفاده‌شده در title و message
        $usedVars = $this->extractVariables($title . ' ' . $message);
        $invalidVars = array_diff($usedVars, $allowedVars);

        if (!empty($invalidVars)) {
            return [
                'success'      => false,
                'error'        => 'متغیرهای نامعتبر: ' . implode(', ', array_map(fn($v) => '{{' . $v . '}}', $invalidVars)),
                'allowed_vars' => $allowedVars,
            ];
        }

        try {
            $now = date('Y-m-d H:i:s');
            $this->db->query(
                "INSERT INTO notification_templates
                    (template_key, title, message, variables, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    title      = VALUES(title),
                    message    = VALUES(message),
                    updated_at = VALUES(updated_at)",
                [
                    $templateKey,
                    $title,
                    $message,
                    json_encode($this->DEFAULT_TEMPLATES[$templateKey]['variables'], JSON_UNESCAPED_UNICODE),
                    $now,
                    $now,
                ]
            );

            // پاک‌کردن cache
            $this->cache->forget(self::CACHE_PREFIX . $templateKey);

            return ['success' => true];

        } catch (\Throwable $e) {
    $this->logger->error('notification_template.save.failed', [
        'channel' => 'notification',
        'key' => $templateKey,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'error' => 'خطا در ذخیره‌سازی.'];
}
    }

    /**
     * حذف override (بازگشت به default)
     */
    public function deleteOverride(string $templateKey): bool
    {
        $this->db->query(
            "DELETE FROM notification_templates WHERE template_key = ?",
            [$templateKey]
        );
        $this->cache->forget(self::CACHE_PREFIX . $templateKey);
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function interpolate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        }
        // متغیرهای جاگذاشته‌نشده را خالی می‌کند
        return preg_replace('/\{\{[a-z_]+\}\}/i', '', $template);
    }

    private function extractVariables(string $text): array
    {
        preg_match_all('/\{\{([a-z_]+)\}\}/i', $text, $matches);
        return $matches[1] ?? [];
    }

    private function getFromDb(string $templateKey): ?object
    {
        try {
            $row = $this->db->query(
                "SELECT * FROM notification_templates WHERE template_key = ? LIMIT 1",
                [$templateKey]
            )->fetch(\PDO::FETCH_OBJ);

            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
