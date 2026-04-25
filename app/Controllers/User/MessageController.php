<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\DirectMessageService;
use Core\Request;
use Core\Response;
use Core\Logger;

/**
 * MessageController - مدیریت پیام‌های مستقیم کاربران
 */
class MessageController
{
    private DirectMessageService $messageService;
    private Logger $logger;

    public function __construct(DirectMessageService $messageService, Logger $logger)
    {
        $this->messageService = $messageService;
        $this->logger = $logger;
    }

    /**
     * لیست conversations
     */
    public function index(Request $request, Response $response)
    {
        try {
            $userId = auth_user()->id;
            $page = (int)($request->query('page') ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;

            $conversations = $this->messageService->getConversations($userId, $limit, $offset);
            $unreadTotal = $this->messageService->getUnreadCount($userId);

            return $response->view('user/messages/index', [
                'conversations' => $conversations,
                'unread_total' => $unreadTotal,
                'page' => $page
            ]);

        } catch (\Exception $e) {
            $this->logger->error('messages.index.failed', ['error' => $e->getMessage()]);
            return $response->error('خطا در بارگذاری پیام‌ها');
        }
    }

    /**
     * نمایش conversation
     */
    public function show(Request $request, Response $response)
    {
        try {
            $userId = auth_user()->id;
            $otherUserId = (int)$request->param('id');
            $page = (int)($request->query('page') ?? 1);
            $limit = 50;
            $offset = ($page - 1) * $limit;

            if ($userId === $otherUserId) {
                return $response->error('نمی‌توانید برای خودتان پیام بفرستید');
            }

            $messages = $this->messageService->getConversation(
                $userId,
                $otherUserId,
                $limit,
                $offset
            );

            $otherUser = $this->messageService->getUserInfo($otherUserId);

            if (!$otherUser) {
                return $response->error('کاربر یافت نشد');
            }

            return $response->view('user/messages/show', [
                'messages' => $messages,
                'other_user' => $otherUser,
                'page' => $page
            ]);

        } catch (\Exception $e) {
            $this->logger->error('messages.show.failed', ['error' => $e->getMessage()]);
            return $response->error('خطا در بارگذاری conversation');
        }
    }

    /**
     * ارسال پیام
     */
    public function send(Request $request, Response $response)
    {
        try {
            if (!check_csrf($request)) {
                return $response->json(['error' => 'CSRF token invalid'], 403);
            }

            $userId = auth_user()->id;
            $recipientId = (int)$request->input('recipient_id');
            $message = trim($request->input('message'));
            $isEncrypted = (bool)$request->input('is_encrypted', false);

            // اعتبارسنجی
            if (empty($message)) {
                return $response->json(['error' => 'پیام نمی‌تواند خالی باشد'], 422);
            }

            // Attachments
            $attachments = [];
            if ($request->hasFiles('attachments')) {
                $attachments = $this->handleAttachments($request->files('attachments'));
            }

            // ارسال پیام
            $result = $this->messageService->sendMessage(
                $userId,
                $recipientId,
                $message,
                $attachments,
                $isEncrypted
            );

            if (isset($result['error'])) {
                return $response->json($result, 422);
            }

            $this->logger->info('message.sent_by_user', [
                'user_id' => $userId,
                'recipient_id' => $recipientId,
                'message_id' => $result['message_id']
            ]);

            return $response->json($result);

        } catch (\Exception $e) {
            $this->logger->error('message.send.failed', ['error' => $e->getMessage()]);
            return $response->json(['error' => 'خطا در ارسال پیام'], 500);
        }
    }

    /**
     * typing indicator
     */
    public function setTyping(Request $request, Response $response)
    {
        try {
            $userId = auth_user()->id;
            $recipientId = (int)$request->input('recipient_id');
            $isTyping = (bool)$request->input('is_typing', true);

            $this->messageService->setTyping($userId, $recipientId, $isTyping);

            return $response->json(['ok' => true]);

        } catch (\Exception $e) {
            $this->logger->error('typing.set.failed', ['error' => $e->getMessage()]);
            return $response->json(['error' => 'خطا'], 500);
        }
    }

    /**
     * دریافت typing users
     */
    public function getTypingUsers(Request $request, Response $response)
    {
        try {
            $userId = auth_user()->id;

            $typingUsers = $this->messageService->getTypingUsers($userId);

            return $response->json([
                'typing_users' => $typingUsers,
                'count' => count($typingUsers)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('typing.get.failed', ['error' => $e->getMessage()]);
            return $response->json(['error' => 'خطا'], 500);
        }
    }

    /**
     * حذف پیام
     */
    public function delete(Request $request, Response $response)
    {
        try {
            if (!check_csrf($request)) {
                return $response->json(['error' => 'CSRF token invalid'], 403);
            }

            $userId = auth_user()->id;
            $messageId = (int)$request->param('id');

            $success = $this->messageService->deleteMessage($messageId, $userId);

            if (!$success) {
                return $response->json(['error' => 'پیام یافت نشد'], 404);
            }

            return $response->json(['ok' => true]);

        } catch (\Exception $e) {
            $this->logger->error('message.delete.failed', ['error' => $e->getMessage()]);
            return $response->json(['error' => 'خطا در حذف پیام'], 500);
        }
    }

    /**
     * اضافه کردن reaction
     */
    public function addReaction(Request $request, Response $response)
    {
        try {
            $userId = auth_user()->id;
            $messageId = (int)$request->param('id');
            $emoji = $request->input('emoji');

            $success = $this->messageService->addReaction($messageId, $userId, $emoji);

            if (!$success) {
                return $response->json(['error' => 'خطا در اضافه کردن reaction'], 422);
            }

            return $response->json(['ok' => true]);

        } catch (\Exception $e) {
            $this->logger->error('reaction.add.failed', ['error' => $e->getMessage()]);
            return $response->json(['error' => 'خطا'], 500);
        }
    }

    /**
     * مدیریت پیوست‌ها
     */
    private function handleAttachments(array $files): array
    {
        $attachments = [];
        $uploadDir = storage_path('messages');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($files as $file) {
            if ($file['size'] > 10 * 1024 * 1024) { // 10MB
                continue;
            }

            $filename = uniqid('msg_') . '_' . $file['name'];
            $filepath = $uploadDir . '/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $attachments[] = [
                    'filename' => $filename,
                    'file_path' => '/uploads/messages/' . $filename,
                    'file_size' => $file['size'],
                    'mime_type' => $file['type']
                ];
            }
        }

        return $attachments;
    }

    /**
     * دریافت تعداد پیام‌های خوانده نشده
     */
    public function getUnreadCount(Request $request, Response $response)
    {
        try {
            $userId = auth_user()->id;
            $count = $this->messageService->getUnreadCount($userId);
            
            return $response->json(['count' => $count]);
        } catch (\Exception $e) {
            $this->logger->error('unread.count.failed', ['error' => $e->getMessage()]);
            return $response->json(['error' => 'خطا'], 500);
        }
    }

}