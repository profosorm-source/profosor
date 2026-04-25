<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Request;
use Core\Response;
use App\Services\AppealService;

/**
 * AppealController - کنترلر مدیریت اعتراضات (ادمین)
 */
class AppealController
{
    private AppealService $appealService;

    public function __construct(AppealService $appealService)
    {
        $this->appealService = $appealService;
    }

    /**
     * لیست اعتراضات
     */
    public function index(Request $request): Response
    {
        $status = $request->get('status');
        $priority = $request->get('priority');
        $page = (int) ($request->get('page') ?? 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        try {
            $appeals = $this->appealService->getAppealsForAdmin(
                $status,
                $priority,
                $limit,
                $offset
            );

            $stats = $this->appealService->getAppealStats();

            return Response::view('admin/appeal/index', [
                'appeals' => $appeals,
                'stats' => $stats,
                'currentPage' => $page,
                'filters' => [
                    'status' => $status,
                    'priority' => $priority
                ]
            ]);

        } catch (\Exception $e) {
            return Response::view('admin/error', [
                'message' => 'Failed to load appeals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * نمایش جزئیات اعتراض
     */
    public function show(Request $request, int $appealId): Response
    {
        try {
            $adminId = app()->session->get('user_id');

            // گرفتن جزئیات اعتراض (بدون فیلتر کاربر چون ادمین است)
            $details = app()->db->query(
                "SELECT a.*, u.username, u.email,
                        COUNT(at.id) as attachment_count
                 FROM appeals a
                 JOIN users u ON a.user_id = u.id
                 LEFT JOIN appeal_attachments at ON a.id = at.appeal_id
                 WHERE a.id = ?
                 GROUP BY a.id",
                [$appealId]
            )->fetch();

            if (!$details) {
                return Response::view('admin/error', [
                    'message' => 'Appeal not found'
                ], 404);
            }

            // گرفتن پیوست‌ها
            $attachments = app()->db->query(
                "SELECT * FROM appeal_attachments
                 WHERE appeal_id = ? ORDER BY uploaded_at ASC",
                [$appealId]
            )->fetchAll();

            // گرفتن پاسخ‌ها
            $responses = app()->db->query(
                "SELECT ar.*, u.username as admin_username
                 FROM appeal_responses ar
                 JOIN users u ON ar.admin_id = u.id
                 WHERE ar.appeal_id = ? ORDER BY ar.created_at ASC",
                [$appealId]
            )->fetchAll();

            return Response::view('admin/appeal/show', [
                'appeal' => $details,
                'attachments' => $attachments,
                'responses' => $responses
            ]);

        } catch (\Exception $e) {
            return Response::view('admin/error', [
                'message' => 'Failed to load appeal details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * پاسخ به اعتراض
     */
    public function respond(Request $request, int $appealId): Response
    {
        $adminId = app()->session->get('user_id');

        $response = trim($request->post('response'));
        $newStatus = $request->post('status');
        $internalNotes = trim($request->post('internal_notes') ?? '');

        // اعتبارسنجی
        if (!$response) {
            return Response::json([
                'success' => false,
                'message' => 'Response is required'
            ], 400);
        }

        $validStatuses = ['pending', 'under_review', 'approved', 'rejected', 'escalated'];
        if ($newStatus && !in_array($newStatus, $validStatuses)) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid status'
            ], 400);
        }

        try {
            $this->appealService->respondToAppeal(
                $appealId,
                $adminId,
                $response,
                $newStatus,
                $internalNotes
            );

            return Response::json([
                'success' => true,
                'message' => 'Response submitted successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to submit response: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تغییر وضعیت اعتراض
     */
    public function updateStatus(Request $request, int $appealId): Response
    {
        $adminId = app()->session->get('user_id');
        $newStatus = $request->post('status');
        $notes = trim($request->post('notes') ?? '');

        $validStatuses = ['pending', 'under_review', 'approved', 'rejected', 'escalated'];
        if (!in_array($newStatus, $validStatuses)) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid status'
            ], 400);
        }

        try {
            // بروزرسانی وضعیت
            app()->db->query(
                "UPDATE appeals SET
                 status = ?,
                 admin_id = ?,
                 decision = ?,
                 decision_at = NOW(),
                 updated_at = NOW()
                 WHERE id = ?",
                [$newStatus, $adminId, $notes, $appealId]
            );

            // لاگ کردن تغییر
            app()->db->query(
                "INSERT INTO appeal_responses
                 (appeal_id, admin_id, response, status_change, internal_notes, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $appealId,
                    $adminId,
                    'Status changed to: ' . $newStatus,
                    $newStatus,
                    $notes
                ]
            );

            return Response::json([
                'success' => true,
                'message' => 'Status updated successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * محرومیت کاربر از ارسال اعتراض
     */
    public function banUser(Request $request): Response
    {
        $userId = (int) $request->post('user_id');
        $days = (int) $request->post('days');
        $reason = trim($request->post('reason'));

        if (!$userId || !$days || !$reason) {
            return Response::json([
                'success' => false,
                'message' => 'User ID, days, and reason are required'
            ], 400);
        }

        if ($days < 1 || $days > 365) {
            return Response::json([
                'success' => false,
                'message' => 'Days must be between 1 and 365'
            ], 400);
        }

        try {
            $this->appealService->banUserFromAppeals($userId, $days, $reason);

            return Response::json([
                'success' => true,
                'message' => "User banned from appeals for {$days} days"
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to ban user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * آمار اعتراضات
     */
    public function stats(Request $request): Response
    {
        try {
            $stats = $this->appealService->getAppealStats();

            // آمار اضافی
            $recentAppeals = app()->db->query(
                "SELECT DATE(created_at) as date, COUNT(*) as count
                 FROM appeals
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date DESC"
            )->fetchAll();

            $typeStats = app()->db->query(
                "SELECT appeal_type, COUNT(*) as count, status
                 FROM appeals
                 GROUP BY appeal_type, status
                 ORDER BY appeal_type, status"
            )->fetchAll();

            return Response::json([
                'success' => true,
                'data' => [
                    'overall' => $stats,
                    'recent' => $recentAppeals,
                    'by_type' => $typeStats
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to load stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * گرفتن اعتراضات فوری
     */
    public function urgentAppeals(Request $request): Response
    {
        try {
            $appeals = $this->appealService->getAppealsForAdmin(
                null, // status
                'urgent', // priority
                20, // limit
                0 // offset
            );

            return Response::json([
                'success' => true,
                'data' => $appeals
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to load urgent appeals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * جستجو در اعتراضات
     */
    public function search(Request $request): Response
    {
        $query = trim($request->get('q'));
        $status = $request->get('status');
        $limit = (int) ($request->get('limit') ?? 20);

        if (!$query) {
            return Response::json([
                'success' => false,
                'message' => 'Search query is required'
            ], 400);
        }

        try {
            $conditions = ["(a.title LIKE ? OR a.description LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"];
            $params = ["%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];

            if ($status) {
                $conditions[] = "a.status = ?";
                $params[] = $status;
            }

            $whereClause = "WHERE " . implode(" AND ", $conditions);

            $appeals = app()->db->query(
                "SELECT a.*, u.username, u.email,
                        COUNT(at.id) as attachment_count
                 FROM appeals a
                 JOIN users u ON a.user_id = u.id
                 LEFT JOIN appeal_attachments at ON a.id = at.appeal_id
                 {$whereClause}
                 GROUP BY a.id
                 ORDER BY a.created_at DESC
                 LIMIT ?",
                array_merge($params, [$limit])
            )->fetchAll();

            return Response::json([
                'success' => true,
                'data' => $appeals
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * دانلود پیوست
     */
    public function downloadAttachment(Request $request, int $attachmentId): Response
    {
        try {
            $attachment = app()->db->query(
                "SELECT * FROM appeal_attachments WHERE id = ?",
                [$attachmentId]
            )->fetch();

            if (!$attachment) {
                return Response::view('admin/error', [
                    'message' => 'Attachment not found'
                ], 404);
            }

            // بررسی دسترسی ادمین
            $adminId = app()->session->get('user_id');
            if (!$adminId) {
                return Response::view('admin/error', [
                    'message' => 'Access denied'
                ], 403);
            }

            $filePath = BASE_PATH . '/storage/' . $attachment['file_path'];

            if (!file_exists($filePath)) {
                return Response::view('admin/error', [
                    'message' => 'File not found on disk'
                ], 404);
            }

            // ارسال فایل
            header('Content-Type: ' . $attachment['mime_type']);
            header('Content-Disposition: attachment; filename="' . $attachment['original_name'] . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;

        } catch (\Exception $e) {
            return Response::view('admin/error', [
                'message' => 'Failed to download attachment: ' . $e->getMessage()
            ], 500);
        }
    }
}