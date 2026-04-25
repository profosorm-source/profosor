<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Core\Request;
use Core\Response;
use App\Services\VerificationService;
use Core\Logger;
use App\Models\InfluencerProfile;

/**
 * VerificationController - Influencer verification API endpoints
 * ✅ Screenshot-proof verification without external APIs
 * ✅ Code generation, submission, history tracking
 */
class VerificationController
{
    private VerificationService $verification;
    private InfluencerProfile $profileModel;
    private Logger $logger;
    private Request $request;
    private Response $response;

    public function __construct(
        VerificationService $verification,
        InfluencerProfile $profileModel,
        Logger $logger,
        Request $request,
        Response $response
    ) {
        $this->verification = $verification;
        $this->profileModel = $profileModel;
        $this->logger       = $logger;
        $this->request      = $request;
        $this->response     = $response;
    }

    /**
     * Generate verification code
     * 
     * POST /api/v1/verification/generate-code
     * Body: {
     *   "profile_id": 123
     * }
     * 
     * Response: {
     *   "ok": true,
     *   "code": "ABC12345",
     *   "instructions": "Post this code in your Instagram bio or first comment on a recent post",
     *   "expires_in": 86400
     * }
     */
    public function generateCode(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $profileId = (int)$this->request->post('profile_id');
            if ($profileId <= 0) {
                $this->response->json(['ok' => false, 'error' => 'Invalid profile_id'], 400);
                return;
            }

            // ✅ Verify ownership
            $profile = $this->profileModel->getSafe($profileId);
            if (!$profile || $profile['user_id'] !== $userId) {
                $this->response->json(['ok' => false, 'error' => 'Profile not found or not owned by you'], 403);
                return;
            }

            // ✅ Generate code
            $result = $this->verification->generateVerificationCode($profileId);
            if (!$result['ok']) {
                $this->response->json($result, 400);
                return;
            }

            $this->logger->info('verification.code_generated', [
                'profile_id' => $profileId,
                'user_id'    => $userId
            ]);

            $this->response->json([
                'ok'           => true,
                'code'         => $result['code'],
                'instructions' => 'Post this code in your Instagram/TikTok bio or in the first comment on a recent post',
                'expires_in'   => 86400, // 24 hours
                'platform'     => $profile['platform'] ?? 'instagram'
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('verification.generate_code_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Generation failed'], 500);
        }
    }

    /**
     * Get verification status
     * 
     * GET /api/v1/verification/status
     * Query: ?profile_id=123
     * 
     * Response: {
     *   "ok": true,
     *   "status": "pending|not_started|submitted|approved|rejected|expired",
     *   "message": "Verification code submitted, waiting for admin approval",
     *   "code": "ABC12345",
     *   "proof_url": "https://...",
     *   "submitted_at": "2025-04-21T10:30:00Z",
     *   "rejection_reason": null
     * }
     */
    public function getStatus(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $profileId = (int)$this->request->query('profile_id');
            if ($profileId <= 0) {
                $this->response->json(['ok' => false, 'error' => 'Invalid profile_id'], 400);
                return;
            }

            // ✅ Verify ownership
            $profile = $this->profileModel->getSafe($profileId);
            if (!$profile || $profile['user_id'] !== $userId) {
                $this->response->json(['ok' => false, 'error' => 'Profile not found or not owned by you'], 403);
                return;
            }

            // ✅ Get status
            $status = $this->verification->getVerificationStatus($profileId);

            $this->response->json([
                'ok'     => true,
                'status' => $status['status'] ?? 'not_started',
                'msg'    => $status['message'] ?? '',
                'code'   => $status['code'] ?? null,
                'proof'  => $status['proof_url'] ?? null,
                'submitted_at' => $status['submitted_at'] ?? null,
                'rejection_reason' => $status['rejection_reason'] ?? null
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('verification.get_status_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Get status failed'], 500);
        }
    }

    /**
     * Submit verification proof (screenshot)
     * 
     * POST /api/v1/verification/submit-proof
     * Body: {
     *   "profile_id": 123,
     *   "proof_url": "https://cdn.example.com/uploads/proof_12345.jpg"
     * }
     * 
     * Response: {
     *   "ok": true,
     *   "message": "Proof submitted, waiting for admin review",
     *   "status": "submitted"
     * }
     */
    public function submitProof(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $profileId = (int)$this->request->post('profile_id');
            $proofUrl = trim((string)$this->request->post('proof_url'));

            if ($profileId <= 0) {
                $this->response->json(['ok' => false, 'error' => 'Invalid profile_id'], 400);
                return;
            }

            if (empty($proofUrl)) {
                $this->response->json(['ok' => false, 'error' => 'Proof URL required'], 400);
                return;
            }

            // ✅ Verify ownership
            $profile = $this->profileModel->getSafe($profileId);
            if (!$profile || $profile['user_id'] !== $userId) {
                $this->response->json(['ok' => false, 'error' => 'Profile not found or not owned by you'], 403);
                return;
            }

            // ✅ Submit proof
            $result = $this->verification->submitVerificationProof($profileId, $userId, $proofUrl);
            if (!$result['ok']) {
                $this->response->json($result, 400);
                return;
            }

            $this->logger->info('verification.proof_submitted', [
                'profile_id' => $profileId,
                'user_id'    => $userId,
                'proof_url'  => $proofUrl
            ]);

            $this->response->json([
                'ok'      => true,
                'message' => 'Proof submitted successfully, waiting for admin review',
                'status'  => 'submitted'
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('verification.submit_proof_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Submission failed'], 500);
        }
    }

    /**
     * Get verification history
     * 
     * GET /api/v1/verification/history
     * Query: ?profile_id=123&limit=10
     * 
     * Response: {
     *   "ok": true,
     *   "history": [
     *     {
     *       "id": 1,
     *       "code": "ABC12345",
     *       "status": "approved",
     *       "submitted_at": "2025-04-20T10:30:00Z",
     *       "approved_at": "2025-04-21T09:00:00Z"
     *     },
     *     {
     *       "id": 2,
     *       "code": "XYZ98765",
     *       "status": "rejected",
     *       "submitted_at": "2025-04-19T10:30:00Z",
     *       "rejection_reason": "Code not visible in screenshot"
     *     }
     *   ]
     * }
     */
    public function getHistory(): void
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                $this->response->json(['ok' => false, 'error' => 'Unauthorized'], 401);
                return;
            }

            $profileId = (int)$this->request->query('profile_id');
            $limit = min((int)($this->request->query('limit') ?? 10), 50);

            if ($profileId <= 0) {
                $this->response->json(['ok' => false, 'error' => 'Invalid profile_id'], 400);
                return;
            }

            // ✅ Verify ownership
            $profile = $this->profileModel->getSafe($profileId);
            if (!$profile || $profile['user_id'] !== $userId) {
                $this->response->json(['ok' => false, 'error' => 'Profile not found or not owned by you'], 403);
                return;
            }

            // ✅ Get history
            $history = $this->verification->getVerificationHistory($profileId, $limit);

            $this->response->json([
                'ok'      => true,
                'history' => $history,
                'count'   => count($history)
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('verification.get_history_failed', ['error' => $e->getMessage()]);
            $this->response->json(['ok' => false, 'error' => 'Get history failed'], 500);
        }
    }
}
