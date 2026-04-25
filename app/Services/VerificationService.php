<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * VerificationService - Influencer verification without external APIs
 * 
 * Verification Method:
 * 1. User provides Instagram username
 * 2. System generates verification code
 * 3. User posts verification code in specific story/post
 * 4. Admin or system verifies manually
 * 
 * No external API calls - all verification is user-initiated
 */
class VerificationService
{
    private Database $db;
    private Logger $logger;
    private const VERIFICATION_CODE_LENGTH = 8;
    private const VERIFICATION_VALIDITY_HOURS = 24;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Verification Code Generation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate verification code for influencer profile
     * 
     * Code format: Random alphanumeric 8 characters
     * User must post this in a story/bio to prove profile ownership
     */
    public function generateVerificationCode(int $profileId): array
    {
        try {
            // ✅ Generate random code
            $code = $this->generateRandomCode();
            
            // ✅ Check existing pending verification
            $existing = $this->db->query(
                "SELECT * FROM influencer_verifications 
                 WHERE profile_id = ? AND status = 'pending' AND expires_at > NOW()
                 LIMIT 1",
                [$profileId]
            )->fetch();

            if ($existing) {
                return [
                    'ok' => true,
                    'code' => $existing->code,
                    'expires_at' => $existing->expires_at,
                    'message' => 'کد تایید قبلاً برای این پروفایل ایجاد شده است'
                ];
            }

            // ✅ Invalidate old codes
            $this->db->query(
                "UPDATE influencer_verifications 
                 SET status = 'expired' 
                 WHERE profile_id = ? AND status = 'pending'",
                [$profileId]
            );

            // ✅ Create new verification record
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::VERIFICATION_VALIDITY_HOURS . ' hours'));
            
            $this->db->query(
                "INSERT INTO influencer_verifications 
                 (profile_id, code, status, expires_at, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$profileId, $code, 'pending', $expiresAt]
            );

            $this->logger->info('verification.code.generated', [
                'profile_id' => $profileId,
                'code' => substr($code, 0, 2) . '****' . substr($code, -2), // Partial log for security
            ]);

            return [
                'ok' => true,
                'code' => $code,
                'expires_at' => $expiresAt,
                'message' => 'کد تایید تولید شد. این کد را در کاپشن تصویر/استوری خود قرار دهید.',
                'instructions' => [
                    '۱. یک تصویر یا استوری از پروفایل خود انتشار دهید',
                    '۲. کد زیر را در کاپشن یا توضیح قرار دهید: ' . $code,
                    '۳. پس از انتشار، درخواست تایید را ارسال کنید',
                    '۴. در ظرف ۲۴ ساعت تایید کنید یا کد منقضی می‌شود'
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('verification.code.generation.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در تولید کد تایید'];
        }
    }

    /**
     * Generate random alphanumeric code
     */
    private function generateRandomCode(int $length = self::VERIFICATION_CODE_LENGTH): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $code;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Manual Verification by Admin/User
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * User submits proof of verification (screenshot of post with code)
     */
    public function submitVerificationProof(int $profileId, int $userId, string $proofUrl): array
    {
        try {
            // ✅ Get pending verification
            $verification = $this->db->query(
                "SELECT * FROM influencer_verifications 
                 WHERE profile_id = ? AND status = 'pending' AND expires_at > NOW()
                 LIMIT 1",
                [$profileId]
            )->fetch();

            if (!$verification) {
                return ['ok' => false, 'error' => 'کد تایید معتبر یافت نشد'];
            }

            // ✅ Validate proof URL
            if (empty($proofUrl) || !$this->isValidProofUrl($proofUrl)) {
                return ['ok' => false, 'error' => 'URL اثبات نامعتبر است'];
            }

            $profile = $this->db->query(
                "SELECT status FROM influencer_profiles WHERE id = ? LIMIT 1",
                [$profileId]
            )->fetch();

            if (!$profile) {
                return ['ok' => false, 'error' => 'پروفایل اینفلوئنسر یافت نشد'];
            }

            if ($profile->status === 'verified') {
                return ['ok' => false, 'error' => 'این پروفایل قبلاً تایید شده است'];
            }

            // ✅ Update verification with proof and move profile into admin review
            $this->db->query(
                "UPDATE influencer_verifications 
                 SET status = 'submitted', proof_url = ?, submitted_at = NOW()
                 WHERE id = ?",
                [$proofUrl, $verification->id]
            );

            $this->db->query(
                "UPDATE influencer_profiles 
                 SET status = 'pending_admin_review', verification_post_url = ?
                 WHERE id = ?",
                [$proofUrl, $profileId]
            );

            $this->logger->info('verification.proof.submitted', [
                'profile_id' => $profileId,
                'user_id' => $userId,
                'verification_id' => $verification->id
            ]);

            return [
                'ok' => true,
                'message' => 'اثبات ارسال شد. منتظر تایید مدیر باشید.',
                'verification_id' => $verification->id
            ];
        } catch (\Exception $e) {
            $this->logger->error('verification.proof.submission.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در ارسال اثبات'];
        }
    }

    /**
     * Get pending verification requests for admin review
     */
    public function getVerificationRequests(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT iv.*, ip.username, ip.page_url, ip.platform, u.full_name, u.email
             FROM influencer_verifications iv
             JOIN influencer_profiles ip ON ip.id = iv.profile_id
             LEFT JOIN users u ON u.id = ip.user_id
             WHERE iv.status = 'submitted'
             ORDER BY iv.submitted_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function countVerificationRequests(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_verifications WHERE status = 'submitted'");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function getVerificationById(int $verificationId): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM influencer_verifications WHERE id = ? LIMIT 1");
        $stmt->execute([$verificationId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    public function getPendingVerificationByProfile(int $profileId): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM influencer_verifications 
             WHERE profile_id = ? AND status = 'submitted'
             ORDER BY submitted_at DESC
             LIMIT 1"
        );
        $stmt->execute([$profileId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * Admin approves verification
     */
    public function approveVerification(int $verificationId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            $verification = $this->db->query(
                "SELECT * FROM influencer_verifications WHERE id = ? FOR UPDATE",
                [$verificationId]
            )->fetch();

            if (!$verification || $verification->status !== 'submitted') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'تایید معتبر نیست'];
            }

            // ✅ Mark as approved
            $this->db->query(
                "UPDATE influencer_verifications 
                 SET status = 'approved', approved_at = NOW(), approved_by = ?
                 WHERE id = ?",
                [$adminId, $verificationId]
            );

            // ✅ Update profile status
            $this->db->query(
                "UPDATE influencer_profiles 
                 SET status = 'verified', verified_at = NOW(), verified_by = ?
                 WHERE id = ?",
                [$adminId, $verification->profile_id]
            );

            $this->db->commit();

            $this->logger->info('verification.approved', [
                'profile_id' => $verification->profile_id,
                'admin_id' => $adminId,
                'verification_id' => $verificationId
            ]);

            return ['ok' => true, 'message' => 'تایید پذیرفته شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('verification.approval.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در تایید'];
        }
    }

    /**
     * Admin rejects verification
     */
    public function rejectVerification(int $verificationId, int $adminId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $verification = $this->db->query(
                "SELECT * FROM influencer_verifications WHERE id = ? FOR UPDATE",
                [$verificationId]
            )->fetch();

            if (!$verification) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'تایید یافت نشد'];
            }

            // ✅ Mark as rejected
            $this->db->query(
                "UPDATE influencer_verifications 
                 SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ?
                 WHERE id = ?",
                [$adminId, $reason, $verificationId]
            );

            // ✅ Allow re-verification
            $this->db->query(
                "UPDATE influencer_profiles 
                 SET status = 'pending', rejection_reason = ?
                 WHERE id = ?",
                [$reason, $verification->profile_id]
            );

            $this->db->commit();

            $this->logger->info('verification.rejected', [
                'profile_id' => $verification->profile_id,
                'admin_id' => $adminId,
                'reason' => $reason
            ]);

            return ['ok' => true, 'message' => 'تایید رد شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('verification.rejection.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در رد تایید'];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Verification Status & History
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get verification status for profile
     */
    public function getVerificationStatus(int $profileId): array
    {
        $verification = $this->db->query(
            "SELECT * FROM influencer_verifications 
             WHERE profile_id = ?
             ORDER BY created_at DESC
             LIMIT 1",
            [$profileId]
        )->fetch();

        if (!$verification) {
            return [
                'status' => 'not_started',
                'message' => 'تایید هنوز شروع نشده'
            ];
        }

        return [
            'status' => $verification->status,
            'code' => $verification->status === 'pending' ? $verification->code : null,
            'expires_at' => $verification->expires_at,
            'submitted_at' => $verification->submitted_at,
            'approved_at' => $verification->approved_at,
            'rejection_reason' => $verification->rejection_reason,
            'message' => $this->getStatusMessage($verification->status),
        ];
    }

    /**
     * Get human-readable status message
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            'pending' => 'منتظر ارسال اثبات',
            'submitted' => 'منتظر تایید مدیر',
            'approved' => 'تایید شده ✓',
            'rejected' => 'رد شده',
            'expired' => 'کد منقضی شده',
            default => 'نامشخص'
        };
    }

    /**
     * Get verification history for profile
     */
    public function getVerificationHistory(int $profileId, int $limit = 10): array
    {
        $records = $this->db->query(
            "SELECT id, status, code, created_at, submitted_at, approved_at, rejection_reason
             FROM influencer_verifications 
             WHERE profile_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$profileId, $limit]
        )->fetchAll() ?? [];

        return array_map(function ($record) {
            return [
                'id' => $record->id,
                'status' => $record->status,
                'created_at' => $record->created_at,
                'submitted_at' => $record->submitted_at,
                'approved_at' => $record->approved_at,
                'rejection_reason' => $record->rejection_reason,
            ];
        }, $records);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cron: Cleanup expired verifications
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mark expired pending verifications as expired
     * Run hourly via cron
     */
    public function cleanupExpiredVerifications(): int
    {
        $result = $this->db->query(
            "UPDATE influencer_verifications 
             SET status = 'expired'
             WHERE status = 'pending' AND expires_at < NOW()"
        );

        $count = $result->rowCount();
        if ($count > 0) {
            $this->logger->info('verification.cleanup', ['expired_count' => $count]);
        }

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Validation Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate proof URL (screenshot)
     */
    private function isValidProofUrl(string $url): bool
    {
        // ✅ Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (empty($host)) {
            return false;
        }

        $allowedDomains = [
            'localhost',
            parse_url(base_url(''), PHP_URL_HOST) ?? '',
            'instagram.com',
            'www.instagram.com',
            'tiktok.com',
            'www.tiktok.com',
            'twitter.com',
            'www.twitter.com',
            'facebook.com',
            'www.facebook.com',
        ];

        if (in_array($host, $allowedDomains, true)) {
            return true;
        }

        return $host === parse_url(base_url(''), PHP_URL_HOST);
    }
}
