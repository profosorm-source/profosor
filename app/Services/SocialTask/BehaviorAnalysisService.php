<?php

namespace App\Services\SocialTask;

/**
 * BehaviorAnalysisService
 *
 * تحلیل عمیق سیگنال‌های رفتاری موبایل و وب.
 * خروجی این سرویس مستقیماً به SocialTaskScoringService می‌رود.
 *
 * الگوهای تشخیص:
 *   - Bot Automation (حرکات مستقیم، زمان‌های ثابت)
 *   - Farm Accounts (IP/Device مشترک، الگوی زمانی یکسان)
 *   - Scripted Behavior (tap_count ثابت، بدون scroll)
 */
class BehaviorAnalysisService
{
    // ─────────────────────────────────────────────────────────────
    // تحلیل کامل یک execution
    // ─────────────────────────────────────────────────────────────

    /**
     * تحلیل کامل رفتار و برگرداندن گزارش + امتیاز
     *
     * @param  array $signals  سیگنال‌های behavior از موبایل/وب
     * @return array [
     *   'behavior_score'  => int (0–100),
     *   'is_bot'          => bool,
     *   'is_farm'         => bool,
     *   'patterns'        => array,   // الگوهای شناسایی‌شده
     *   'signals_summary' => array,   // خلاصه سیگنال‌ها
     * ]
     */
    public function analyze(array $signals): array
    {
        $scoring  = new SocialTaskScoringService();
        $score    = $scoring->calculateBehaviorScore($signals);
        $patterns = $this->detectPatterns($signals);
        $isBot    = $this->isBotLike($signals, $patterns);
        $isFarm   = $this->isFarmLike($signals);

        // جریمه فوری برای bot
        if ($isBot)  $score = max(0, $score - 30);
        if ($isFarm) $score = max(0, $score - 20);

        return [
            'behavior_score'  => $score,
            'is_bot'          => $isBot,
            'is_farm'         => $isFarm,
            'patterns'        => $patterns,
            'signals_summary' => $this->summarize($signals),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // تشخیص الگوی Bot Automation
    // ─────────────────────────────────────────────────────────────

    /**
     * Pattern 1: Bot Automation
     * - Mouse/touch movement غیرطبیعی (خطوط مستقیم)
     * - عدم scroll
     * - زمان‌های ثابت ریاضی (variance خیلی کم)
     */
    public function isBotLike(array $signals, array $patterns = []): bool
    {
        if (!empty($patterns)) {
            return in_array('bot_fixed_timing', $patterns, true)
                || in_array('bot_no_interaction', $patterns, true)
                || in_array('bot_straight_movement', $patterns, true);
        }

        $variance    = (float)($signals['touch_timing_variance'] ?? 999);
        $tapCount    = (int)($signals['tap_count'] ?? 0);
        $scrollCount = (int)($signals['scroll_count'] ?? 0);
        $avgDelay    = (float)($signals['avg_action_delay_ms'] ?? 9999);

        // زمان‌های دقیقاً یکسان
        if ($variance < 5 && $tapCount > 5) return true;
        // بدون هیچ تعامل
        if ($tapCount === 0 && $scrollCount === 0) return true;
        // سرعت غیرانسانی
        if ($avgDelay < 50 && $tapCount > 3) return true;

        return false;
    }

    /**
     * Pattern 2: Farm-like behavior
     * - الگوهای خیلی منظم و یکسان
     * - زمان session دقیقاً مساوی expected_time
     */
    public function isFarmLike(array $signals): bool
    {
        $sessionDuration = (int)($signals['session_duration'] ?? 0);
        $expectedTime    = (int)($signals['expected_time'] ?? 0);
        $variance        = (float)($signals['scroll_speed_variance'] ?? 999);
        $scrollCount     = (int)($signals['scroll_count'] ?? 0);
        $blurCount       = (int)($signals['app_blur_count'] ?? 0);

        // session دقیقاً مساوی expected (ربات زمان‌سنج)
        if ($expectedTime > 0 && abs($sessionDuration - $expectedTime) <= 1) return true;
        // scroll کاملاً خطی و یکنواخت
        if ($scrollCount > 3 && $variance < 2) return true;
        // هیچ‌وقت از app خارج نشده (غیرطبیعی برای انسان)
        if ($sessionDuration > 60 && $blurCount === 0 && $scrollCount === 0) return true;

        return false;
    }

    // ─────────────────────────────────────────────────────────────
    // تشخیص الگوهای پیشرفته
    // ─────────────────────────────────────────────────────────────

    /**
     * لیست الگوهای شناسایی‌شده
     * @return string[]
     */
    public function detectPatterns(array $signals): array
    {
        $patterns = [];

        $tapCount        = (int)($signals['tap_count'] ?? 0);
        $scrollCount     = (int)($signals['scroll_count'] ?? 0);
        $swipeCount      = (int)($signals['swipe_count'] ?? 0);
        $variance        = (float)($signals['touch_timing_variance'] ?? 999);
        $avgDelay        = (float)($signals['avg_action_delay_ms'] ?? 0);
        $hesitation      = (int)($signals['hesitation_count'] ?? 0);
        $blurCount       = (int)($signals['app_blur_count'] ?? 0);
        $sessionDuration = (int)($signals['session_duration'] ?? 0);
        $activeTime      = (int)($signals['active_time'] ?? 0);
        $expectedTime    = (int)($signals['expected_time'] ?? 60);
        $scrollVariance  = (float)($signals['scroll_speed_variance'] ?? 999);
        $cameraScore     = (int)($signals['camera_score'] ?? -1);

        // Bot: زمان ثابت
        if ($variance < 5 && $tapCount > 5) {
            $patterns[] = 'bot_fixed_timing';
        }

        // Bot: بدون interaction
        if ($tapCount === 0 && $scrollCount === 0 && $swipeCount === 0) {
            $patterns[] = 'bot_no_interaction';
        }

        // Bot: سرعت غیرانسانی
        if ($avgDelay > 0 && $avgDelay < 80 && $tapCount > 3) {
            $patterns[] = 'bot_straight_movement';
        }

        // Farm: session دقیق
        if ($expectedTime > 0 && abs($sessionDuration - $expectedTime) <= 1 && $sessionDuration > 0) {
            $patterns[] = 'farm_exact_timing';
        }

        // Farm: scroll یکنواخت
        if ($scrollCount > 2 && $scrollVariance < 3) {
            $patterns[] = 'farm_linear_scroll';
        }

        // انسانی: hesitation طبیعی
        if ($hesitation > 1 && $avgDelay > 300) {
            $patterns[] = 'human_hesitation';
        }

        // انسانی: چند نوع interaction
        $interactionTypes = ($tapCount > 0 ? 1 : 0) + ($scrollCount > 0 ? 1 : 0) + ($swipeCount > 0 ? 1 : 0);
        if ($interactionTypes >= 2) {
            $patterns[] = 'human_mixed_interaction';
        }

        // خروج‌های کوتاه (طبیعی)
        if ($blurCount > 0 && $blurCount <= 3) {
            $patterns[] = 'natural_app_switch';
        }

        // زمان فعال پایین
        if ($sessionDuration > 0 && $activeTime > 0 && ($activeTime / $sessionDuration) < 0.3) {
            $patterns[] = 'low_active_time';
        }

        // Camera verification موفق
        if ($cameraScore >= 70) {
            $patterns[] = 'camera_verified';
        } elseif ($cameraScore >= 0 && $cameraScore < 50) {
            $patterns[] = 'camera_failed';
        }

        return $patterns;
    }

    // ─────────────────────────────────────────────────────────────
    // تصمیم Camera Verification — آیا نیاز هست؟
    // ─────────────────────────────────────────────────────────────

    /**
     * آیا باید Camera Verification درخواست شود؟
     * فقط وقتی امتیاز کافی نیست و مشکوک است.
     *
     * @param float $currentTaskScore امتیاز فعلی قبل از تصمیم نهایی
     * @param array $patterns         الگوهای شناسایی‌شده
     */
    public function needsCameraVerification(float $currentTaskScore, array $patterns): bool
    {
        // اگر score خیلی پایین باشد → نیاز به Camera
        if ($currentTaskScore < 50 && $currentTaskScore >= 25) {
            // ولی اگر الگوی human داشت نیازی نیست
            if (in_array('human_hesitation', $patterns, true)
                || in_array('human_mixed_interaction', $patterns, true)) {
                return false;
            }
            return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────
    // خلاصه سیگنال‌ها برای audit
    // ─────────────────────────────────────────────────────────────

    public function summarize(array $signals): array
    {
        return [
            'tap_count'          => (int)($signals['tap_count'] ?? 0),
            'scroll_count'       => (int)($signals['scroll_count'] ?? 0),
            'swipe_count'        => (int)($signals['swipe_count'] ?? 0),
            'session_duration'   => (int)($signals['session_duration'] ?? 0),
            'active_time'        => (int)($signals['active_time'] ?? 0),
            'app_blur_count'     => (int)($signals['app_blur_count'] ?? 0),
            'hesitation_count'   => (int)($signals['hesitation_count'] ?? 0),
            'avg_action_delay'   => round((float)($signals['avg_action_delay_ms'] ?? 0)),
            'touch_variance'     => round((float)($signals['touch_timing_variance'] ?? 0), 2),
            'scroll_variance'    => round((float)($signals['scroll_speed_variance'] ?? 0), 2),
            'camera_score'       => (int)($signals['camera_score'] ?? -1),
        ];
    }
}
