<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Core\Request;
use Core\Response;
use App\Services\WebSocketService;
use Core\Logger;

/**
 * RealTimeController - Real-time messaging API endpoints
 * ✅ Supports Long Polling (25-second timeout) + WebSocket
 * ✅ Room-based subscriptions
 * ✅ Presence tracking
 */
class RealTimeController
{
    private WebSocketService $realTime;
    private Logger $logger;
    private Request $request;
    private Response $response;

    public function __construct(
        WebSocketService $realTime,
        Logger $logger,
        Request $request,
        Response $response
    ) {
        $this->realTime = $realTime;
        $this->logger   = $logger;
        $this->request  = $request;
        $this->response = $response;
    }

    /**
     * Long Polling endpoint (60-second timeout fallback with reduced frequency)
     * 
     * POST /api/v1/real-time/poll
     * Body: {
     *   "user_id": 123,
     *   "last_message_id": 0,
     *   "timeout": 60
     * }
     */
    public function poll(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $lastMessageId = (int)($this->request->post('last_message_id') ?? 0);
            $timeout = min((int)($this->request->post('timeout') ?? 60), 60); // Max 60 seconds (increased)

            // ✅ Long poll with timeout (optimized)
            $messages = $this->realTime->longPoll($userId, $lastMessageId, $timeout);

            $this->response->json([
                'ok'       => true,
                'messages' => $messages,
                'count'    => count($messages)
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.poll_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Poll failed'], 500);
        }
    }

    /**
     * Join a real-time room
     * 
     * POST /api/v1/real-time/rooms/join
     * Body: {
     *   "room": "task:123" or "order:456" or "user:789"
     * }
     */
    public function joinRoom(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $room = trim((string)$this->request->post('room'));
            if (empty($room)) {
                $this->response->json(['ok' => false, 'error' => 'Room name required'], 400);
                return;
            }

            // ✅ Validate room format (security)
            if (!preg_match('/^[a-z_]:[0-9]+$/', $room)) {
                $this->response->json(['ok' => false, 'error' => 'Invalid room format'], 400);
                return;
            }

            $this->realTime->joinRoom($userId, $room);

            $this->logger->info('real_time.room_joined', [
                'user_id' => $userId,
                'room'    => $room
            ]);

            $this->response->json([
                'ok'   => true,
                'room' => $room,
                'msg'  => 'Subscribed to room'
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.join_room_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Join failed'], 500);
        }
    }

    /**
     * Leave a real-time room
     * 
     * POST /api/v1/real-time/rooms/leave
     * Body: {
     *   "room": "task:123"
     * }
     */
    public function leaveRoom(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $room = trim((string)$this->request->post('room'));
            if (empty($room)) {
                $this->response->json(['ok' => false, 'error' => 'Room name required'], 400);
                return;
            }

            $this->realTime->leaveRoom($userId, $room);

            $this->logger->info('real_time.room_left', [
                'user_id' => $userId,
                'room'    => $room
            ]);

            $this->response->json([
                'ok'   => true,
                'room' => $room,
                'msg'  => 'Unsubscribed from room'
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.leave_room_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Leave failed'], 500);
        }
    }

    /**
     * Get members in a room
     * 
     * GET /api/v1/real-time/rooms/{room}/members
     */
    public function getRoomMembers(): void
    {
        try {
            $room = trim((string)$this->request->param('room'));
            if (empty($room)) {
                $this->response->json(['ok' => false, 'error' => 'Room name required'], 400);
                return;
            }

            $members = $this->realTime->getRoomMembers($room);

            $this->response->json([
                'ok'      => true,
                'room'    => $room,
                'members' => $members,
                'count'   => count($members)
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_members_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Get members failed'], 500);
        }
    }

    /**
     * Get all online users
     * 
     * GET /api/v1/real-time/presence/online
     */
    public function getOnlineUsers(): void
    {
        try {
            $onlineCount = $this->realTime->getOnlineCount();

            $this->response->json([
                'ok'    => true,
                'count' => $onlineCount
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_online_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Get online count failed'], 500);
        }
    }

    /**
     * Get online users in a specific room
     * 
     * GET /api/v1/real-time/presence/online/{room}
     */
    public function getOnlineInRoom(): void
    {
        try {
            $room = trim((string)$this->request->param('room'));
            if (empty($room)) {
                $this->response->json(['ok' => false, 'error' => 'Room name required'], 400);
                return;
            }

            $onlineUsers = $this->realTime->getOnlineInRoom($room);

            $this->response->json([
                'ok'    => true,
                'room'  => $room,
                'users' => $onlineUsers,
                'count' => count($onlineUsers)
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_online_in_room_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Get online in room failed'], 500);
        }
    }

    /**
     * Get real-time system stats
     * 
     * GET /api/v1/real-time/stats
     */
    public function getStats(): void
    {
        try {
            $stats = $this->realTime->getStats();

            $this->response->json([
                'ok'    => true,
                'stats' => $stats
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('real_time.get_stats_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Get stats failed'], 500);
        }
    }
}
