<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;
use Core\Redis;

/**
 * DirectMessageService - سیستم پیام‌رسانی مستقیم
 *
 * قابلیت‌ها:
 * - ارسال/دریافت پیام‌های مستقیم
 * - رمزنگاری پیام‌های حساس
 * - نمایش وضعیت (typing indicator)
 * - وضعیت خواندن پیام
 * - پیوست‌های فایل
 * - واکنش‌های emoji
 * - Conversation management
 */
class DirectMessageService
{
    private Database $db;
    private Logger $logger;
    private Redis $redis;

    // محدودیت‌های سرویس
    private const MAX_MESSAGE_LENGTH = 5000;
    private const MAX_ATTACHMENT_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_ATTACHMENTS_PER_MESSAGE = 5;
    private const MESSAGE_RETENTION_DAYS = 90;
    private const TYPING_INDICATOR_TIMEOUT = 3; // ثانیه

    // کلیدهای Redis
    private const CONVERSATION_PREFIX = 'conversation:';
    private const TYPING_PREFIX = 'typing:';
    private const UNREAD_PREFIX = 'unread:';

    public function __construct(Database $db, Logger $logger, Redis $redis)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    /**
     * ارسال پیام جدید
     */
    public function sendMessage(
        int $senderId,
        int $recipientId,
        string $message,
        ?array $attachments = null,
        ?bool $isEncrypted = false
    ): array {
        try {
            // اعتبارسنجی
            if (empty(trim($message))) {
                return ['error' => 'پیام نمی‌تواند خالی باشد'];
            }

            if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
                return ['error' => sprintf('پیام نباید بیش از %d کاراکتر باشد', self::MAX_MESSAGE_LENGTH)];
            }

            if ($senderId === $recipientId) {
                return ['error' => 'نمی‌توانید برای خودتان پیام بفرستید'];
            }

            // بررسی مسدودی
            if ($this->isBlocked($senderId, $recipientId)) {
                return ['error' => 'این کاربر شما را مسدود کرده است'];
            }

            // بررسی محدودیت سرعت (rate limiting)
            if (!$this->checkRateLimit($senderId)) {
                return ['error' => 'خیلی سریع پیام فرستادید. لطفاً یکی دو ثانیه صبر کنید'];
            }

            $this->db->beginTransaction();

            // ثبت پیام
            $messageId = $this->db->query(
                "INSERT INTO direct_messages 
                 (sender_id, recipient_id, message, is_encrypted, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [
                    $senderId,
                    $recipientId,
                    $isEncrypted ? $this->encryptMessage($message) : $message,
                    $isEncrypted ? 1 : 0
                ]
            )->lastInsertId();

            // پیوست‌ها
            if (!empty($attachments)) {
                $this->addAttachments($messageId, $attachments);
            }

            // بروزرسانی conversation
            $this->updateConversation($senderId, $recipientId, $messageId);

            // شمارشگر پیام‌های خوانده نشده
            $this->redis->incr(self::UNREAD_PREFIX . $recipientId . ':' . $senderId);

            $this->db->commit();

            $this->logger->info('message.sent', [
                'message_id' => $messageId,
                'sender_id' => $senderId,
                'recipient_id' => $recipientId
            ]);

            return [
                'success' => true,
                'message_id' => $messageId,
                'created_at' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('message.send.failed', ['error' => $e->getMessage()]);
            return ['error' => 'خطا در ارسال پیام'];
        }
    }

    /**
     * دریافت پیام‌های conversation
     */
    public function getConversation(
        int $userId,
        int $otherUserId,
        int $limit = 50,
        int $offset = 0
    ): array {
        $messages = $this->db->query(
            "SELECT 
                dm.*,
                u.full_name as sender_name,
                COUNT(da.id) as attachment_count
             FROM direct_messages dm
             JOIN users u ON dm.sender_id = u.id
             LEFT JOIN message_attachments da ON dm.id = da.message_id
             WHERE (
                (dm.sender_id = ? AND dm.recipient_id = ?) OR
                (dm.sender_id = ? AND dm.recipient_id = ?)
             )
             GROUP BY dm.id
             ORDER BY dm.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $otherUserId, $otherUserId, $userId, $limit, $offset]
        )->fetchAll();

        // mark as read
        $this->markAsRead($userId, $otherUserId);

        return array_map(function($msg) {
            return [
                'id' => $msg->id,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender_name,
                'message' => $msg->is_encrypted ? $this->decryptMessage($msg->message) : $msg->message,
                'is_encrypted' => (bool)$msg->is_encrypted,
                'attachment_count' => $msg->attachment_count,
                'created_at' => $msg->created_at,
                'read_at' => $msg->read_at
            ];
        }, array_reverse($messages));
    }

    /**
     * لیست conversations کاربر
     */
    public function getConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        $conversations = $this->db->query(
            "SELECT DISTINCT
                CASE 
                    WHEN sender_id = ? THEN recipient_id
                    ELSE sender_id
                END as user_id,
                u.full_name,
                u.avatar,
                (SELECT message FROM direct_messages 
                 WHERE (sender_id = ? AND recipient_id = u.id) OR (sender_id = u.id AND recipient_id = ?)
                 ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM direct_messages 
                 WHERE (sender_id = ? AND recipient_id = u.id) OR (sender_id = u.id AND recipient_id = ?)
                 ORDER BY created_at DESC LIMIT 1) as last_message_at,
                (SELECT COUNT(*) FROM direct_messages 
                 WHERE sender_id = u.id AND recipient_id = ? AND read_at IS NULL) as unread_count
             FROM direct_messages dm
             JOIN users u ON (
                (dm.sender_id = ? AND dm.recipient_id = u.id) OR
                (dm.sender_id = u.id AND dm.recipient_id = ?)
             )
             WHERE dm.sender_id = ? OR dm.recipient_id = ?
             GROUP BY user_id
             ORDER BY last_message_at DESC
             LIMIT ? OFFSET ?",
            [
                $userId, $userId, $userId, $userId, $userId,
                $userId, $userId, $userId, $userId, $userId,
                $limit, $offset
            ]
        )->fetchAll();

        return array_map(function($conv) {
            return [
                'user_id' => $conv->user_id,
                'user_name' => $conv->full_name,
                'user_avatar' => $conv->avatar,
                'last_message' => $conv->last_message,
                'last_message_at' => $conv->last_message_at,
                'unread_count' => (int)($conv->unread_count ?? 0)
            ];
        }, $conversations);
    }

    /**
     * دریافت اطلاعات کاربر
     */
    public function getUserInfo(int $userId): ?array
    {
        $user = $this->db->query(
            "SELECT id, username, full_name, avatar, is_online FROM users WHERE id = ? LIMIT 1",
            [$userId]
        )->fetch();

        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'avatar' => $user->avatar,
            'is_online' => (bool) $user->is_online
        ];
    }

    /**
     * Typing indicator - نمایش "در حال نوشتن"
     */
    public function setTyping(int $userId, int $recipientId, bool $isTyping = true): void
    {
        $key = self::TYPING_PREFIX . $recipientId . ':' . $userId;

        if ($isTyping) {
            $this->redis->setex($key, self::TYPING_INDICATOR_TIMEOUT, '1');
        } else {
            $this->redis->del($key);
        }
    }

    /**
     * چک کردن کسانی که در حال نوشتن هستند
     */
    public function getTypingUsers(int $userId): array
    {
        $pattern = self::TYPING_PREFIX . $userId . ':*';
        $keys = $this->redis->keys($pattern) ?? [];

        $typingUsers = [];
        foreach ($keys as $key) {
            $userId = explode(':', $key)[2];
            $typingUsers[] = (int)$userId;
        }

        return $typingUsers;
    }

    /**
     * پاک کردن پیام
     */
    public function deleteMessage(int $messageId, int $userId): bool
    {
        try {
            $message = $this->db->query(
                "SELECT * FROM direct_messages WHERE id = ?",
                [$messageId]
            )->fetch();

            if (!$message || ($message->sender_id !== $userId && $message->recipient_id !== $userId)) {
                return false;
            }

            // Soft delete (نشانه زدن به‌عنوان حذف شده)
            $this->db->query(
                "UPDATE direct_messages SET deleted_by = ?, deleted_at = NOW() WHERE id = ?",
                [$userId, $messageId]
            );

            $this->logger->info('message.deleted', ['message_id' => $messageId, 'user_id' => $userId]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('message.delete.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * واکنش emoji
     */
    public function addReaction(int $messageId, int $userId, string $emoji): bool
    {
        try {
            $this->db->query(
                "INSERT INTO message_reactions (message_id, user_id, emoji, created_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE emoji = ?",
                [$messageId, $userId, $emoji, $emoji]
            );

            return true;

        } catch (\Exception $e) {
            $this->logger->error('reaction.add.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * رمزنگاری پیام
     */
    private function encryptMessage(string $message): string
    {
        // استفاده از encryption ساده برای نمونه
        // در تولید، باید از یک روش قوی استفاده شود
        return base64_encode($message);
    }

    /**
     * رفع رمزنگاری پیام
     */
    private function decryptMessage(string $encrypted): string
    {
        try {
            return base64_decode($encrypted);
        } catch (\Exception $e) {
            return '[رمزنگاری شده - نمی‌توان رمزگشایی کرد]';
        }
    }

    /**
     * اضافه کردن پیوست‌ها
     */
    private function addAttachments(int $messageId, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $this->db->query(
                "INSERT INTO message_attachments 
                 (message_id, filename, file_path, file_size, mime_type, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $messageId,
                    $attachment['filename'],
                    $attachment['file_path'],
                    $attachment['file_size'],
                    $attachment['mime_type']
                ]
            );
        }
    }

    /**
     * بروزرسانی conversation
     */
    private function updateConversation(int $senderId, int $recipientId, int $messageId): void
    {
        $this->db->query(
            "INSERT INTO user_conversations (user1_id, user2_id, last_message_id, updated_at)
             VALUES (LEAST(?, ?), GREATEST(?, ?), ?, NOW())
             ON DUPLICATE KEY UPDATE last_message_id = ?, updated_at = NOW()",
            [$senderId, $recipientId, $senderId, $recipientId, $messageId, $messageId]
        );
    }

    /**
     * علامت‌گذاری پیام‌ها به‌عنوان خوانده شده
     */
    private function markAsRead(int $userId, int $otherUserId): void
    {
        $this->db->query(
            "UPDATE direct_messages 
             SET read_at = NOW()
             WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL",
            [$userId, $otherUserId]
        );

        // پاک کردن unread counter
        $this->redis->del(self::UNREAD_PREFIX . $userId . ':' . $otherUserId);
    }

    /**
     * بررسی مسدودی
     */
    private function isBlocked(int $userId, int $blockedUserId): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM user_blocks 
             WHERE blocker_id = ? AND blocked_id = ?",
            [$blockedUserId, $userId]
        )->fetch();

        return ($result->count ?? 0) > 0;
    }

    /**
     * چک rate limiting
     */
    private function checkRateLimit(int $userId): bool
    {
        $key = 'rate_limit:messages:' . $userId;
        $currentCount = (int)($this->redis->get($key) ?? 0);

        if ($currentCount >= 10) { // 10 پیام در دقیقه
            return false;
        }

        $this->redis->incr($key);
        $this->redis->expire($key, 60);

        return true;
    }

    /**
     * تعداد پیام‌های خوانده نشده
     */
    public function getUnreadCount(int $userId, ?int $fromUserId = null): int
    {
        if ($fromUserId) {
            $key = self::UNREAD_PREFIX . $userId . ':' . $fromUserId;
            return (int)($this->redis->get($key) ?? 0);
        }

        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM direct_messages 
             WHERE recipient_id = ? AND read_at IS NULL",
            [$userId]
        )->fetch();

        return $result->count ?? 0;
    }
}