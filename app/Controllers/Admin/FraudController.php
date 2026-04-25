<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Request;
use Core\Response;
use App\Services\FraudDetectionService;

/**
 * FraudController - مدیریت سیستم تشخیص تقلب
 */
class FraudController
{
    private FraudDetectionService $fraudService;

    public function __construct(FraudDetectionService $fraudService)
    {
        $this->fraudService = $fraudService;
    }

    /**
     * گرفتن گزارش ریسک کاربر
     */
    public function getRiskReport(Request $request): Response
    {
        $userId = (int) $request->get('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $report = $this->fraudService->getRiskReport($userId);

            return Response::json([
                'success' => true,
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to generate risk report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * محاسبه مجدد امتیاز تقلب کاربر
     */
    public function recalculateScore(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $score = $this->fraudService->calculateFraudScore($userId);

            return Response::json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'fraud_score' => $score
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to recalculate fraud score: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * اجرای اقدامات خودکار بر اساس امتیاز
     */
    public function executeActions(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $actions = $this->fraudService->executeAutomatedActions($userId);

            return Response::json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'executed_actions' => $actions
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to execute automated actions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * گرفتن لیست کاربران پر ریسک
     */
    public function getHighRiskUsers(Request $request): Response
    {
        $minScore = (int) ($request->get('min_score') ?? 50);
        $limit = (int) ($request->get('limit') ?? 50);

        try {
            // Query users with high fraud scores
            $db = app()->db;
            $users = $db->query(
                "SELECT id, username, email, fraud_score, requires_review, requires_kyc, 
                        requires_manual_review, is_blacklisted, created_at
                 FROM users 
                 WHERE fraud_score >= ? 
                 ORDER BY fraud_score DESC 
                 LIMIT ?",
                [$minScore, $limit]
            )->fetchAll();

            return Response::json([
                'success' => true,
                'data' => [
                    'users' => $users,
                    'count' => count($users),
                    'min_score' => $minScore
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to fetch high risk users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * گرفتن لاگ‌های تقلب
     */
    public function getFraudLogs(Request $request): Response
    {
        $userId = $request->get('user_id') ? (int) $request->get('user_id') : null;
        $fraudType = $request->get('fraud_type');
        $limit = (int) ($request->get('limit') ?? 100);

        try {
            $db = app()->db;
            
            $query = "SELECT fl.*, u.username 
                     FROM fraud_logs fl 
                     LEFT JOIN users u ON fl.user_id = u.id";
            $params = [];
            $conditions = [];

            if ($userId) {
                $conditions[] = "fl.user_id = ?";
                $params[] = $userId;
            }

            if ($fraudType) {
                $conditions[] = "fl.fraud_type = ?";
                $params[] = $fraudType;
            }

            if (!empty($conditions)) {
                $query .= " WHERE " . implode(" AND ", $conditions);
            }

            $query .= " ORDER BY fl.created_at DESC LIMIT ?";
            $params[] = $limit;

            $logs = $db->query($query, $params)->fetchAll();

            return Response::json([
                'success' => true,
                'data' => [
                    'logs' => $logs,
                    'count' => count($logs)
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to fetch fraud logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * پاک کردن پرچم‌های بررسی
     */
    public function clearFlags(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $db = app()->db;
            $db->query(
                "UPDATE users SET 
                 requires_review = 0, 
                 requires_kyc = 0, 
                 requires_manual_review = 0,
                 updated_at = NOW()
                 WHERE id = ?",
                [$userId]
            );

            // Log the action
            $db->query(
                "INSERT INTO fraud_logs (user_id, fraud_type, action_taken, details, created_at) 
                 VALUES (?, 'admin_action', 'flags_cleared', 'Admin cleared all fraud flags', NOW())",
                [$userId]
            );

            return Response::json([
                'success' => true,
                'message' => 'Fraud flags cleared successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to clear flags: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تعلیق دستی حساب
     */
    public function suspendUser(Request $request): Response
    {
        $userId = (int) $request->post('user_id');
        $reason = trim($request->post('reason') ?? '');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        if (!$reason) {
            return Response::json([
                'success' => false,
                'message' => 'Suspension reason is required'
            ], 400);
        }

        try {
            $db = app()->db;
            $db->query(
                "UPDATE users SET 
                 is_blacklisted = 1, 
                 blacklist_reason = ?, 
                 blacklisted_at = NOW(),
                 updated_at = NOW()
                 WHERE id = ?",
                [$reason, $userId]
            );

            // Log the action
            $db->query(
                "INSERT INTO fraud_logs (user_id, fraud_type, action_taken, details, created_at) 
                 VALUES (?, 'admin_action', 'manual_suspension', ?, NOW())",
                [$userId, "Manual suspension: " . $reason]
            );

            return Response::json([
                'success' => true,
                'message' => 'User suspended successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to suspend user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * رفع تعلیق حساب
     */
    public function unsuspendUser(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $db = app()->db;
            $db->query(
                "UPDATE users SET 
                 is_blacklisted = 0, 
                 blacklist_reason = NULL, 
                 blacklisted_at = NULL,
                 updated_at = NOW()
                 WHERE id = ?",
                [$userId]
            );

            // Log the action
            $db->query(
                "INSERT INTO fraud_logs (user_id, fraud_type, action_taken, details, created_at) 
                 VALUES (?, 'admin_action', 'unsuspension', 'Admin removed suspension', NOW())",
                [$userId]
            );

            return Response::json([
                'success' => true,
                'message' => 'User unsuspended successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to unsuspend user: ' . $e->getMessage()
            ], 500);
        }
    }
}