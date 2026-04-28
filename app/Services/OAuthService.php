<?php

namespace App\Services;

use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Models\SocialAccount;

/**
 * OAuthService — Social Login سہولت (Google, Facebook)
 * 
 * فعالیت:
 * - Google OAuth provider integration
 * - Facebook OAuth provider integration
 * - Social account linking/unlinking
 * - Multi-login support (ایک صارف کے متعدد social accounts)
 */
class OAuthService
{
    private string $googleClientId;
    private string $googleClientSecret;
    private string $facebookAppId;
    private string $facebookAppSecret;
    private string $appUrl;

    public function __construct(
        private Database $db,
        private Logger $logger,
        private User $userModel,
        private SocialAccount $socialAccountModel,
        private AuthService $authService,
        private NotificationService $notificationService,
        private AuditTrail $auditTrail
    ) {
        $this->googleClientId = config('oauth.google.client_id', '');
        $this->googleClientSecret = config('oauth.google.client_secret', '');
        $this->facebookAppId = config('oauth.facebook.app_id', '');
        $this->facebookAppSecret = config('oauth.facebook.app_secret', '');
        $this->appUrl = config('app.url', 'http://localhost');
    }

    /**
     * Google OAuth میں redirect کریں
     */
    public function getGoogleAuthUrl(): string
    {
        $scope = urlencode('openid email profile');
        $redirectUri = urlencode("{$this->appUrl}/auth/callback/google");
        $state = bin2hex(random_bytes(16));
        
        // State کو session میں محفوظ کریں
        $_SESSION['oauth_state'] = $state;

        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]);
    }

    /**
     * Facebook OAuth میں redirect کریں
     */
    public function getFacebookAuthUrl(): string
    {
        $redirectUri = urlencode("{$this->appUrl}/auth/callback/facebook");
        $state = bin2hex(random_bytes(16));
        
        $_SESSION['oauth_state'] = $state;

        return "https://www.facebook.com/v12.0/dialog/oauth?" . http_build_query([
            'client_id' => $this->facebookAppId,
            'redirect_uri' => $redirectUri,
            'scope' => 'email,public_profile',
            'state' => $state,
        ]);
    }

    /**
     * Google callback سے صارف handle کریں
     */
    public function handleGoogleCallback(string $code, string $state): array
    {
        // State کو verify کریں (CSRF protection)
        if (!isset($_SESSION['oauth_state']) || $_SESSION['oauth_state'] !== $state) {
            return ['success' => false, 'message' => 'Invalid state. Security check failed.'];
        }

        try {
            // Token حاصل کریں
            $tokenResponse = $this->getGoogleToken($code);
            if (!$tokenResponse['success']) {
                return $tokenResponse;
            }

            // User info حاصل کریں
            $userInfo = $this->getGoogleUserInfo($tokenResponse['access_token']);
            if (!$userInfo['success']) {
                return $userInfo;
            }

            // صارف کو link یا create کریں
            return $this->linkOrCreateUser('google', $userInfo['data']);
        } catch (\Exception $e) {
            $this->logger->error('google_oauth_error', $e->getMessage());
            return ['success' => false, 'message' => 'Google OAuth میں خرابی'];
        }
    }

    /**
     * Facebook callback سے صارف handle کریں
     */
    public function handleFacebookCallback(string $code, string $state): array
    {
        // State verify کریں
        if (!isset($_SESSION['oauth_state']) || $_SESSION['oauth_state'] !== $state) {
            return ['success' => false, 'message' => 'Invalid state. Security check failed.'];
        }

        try {
            $tokenResponse = $this->getFacebookToken($code);
            if (!$tokenResponse['success']) {
                return $tokenResponse;
            }

            $userInfo = $this->getFacebookUserInfo($tokenResponse['access_token']);
            if (!$userInfo['success']) {
                return $userInfo;
            }

            return $this->linkOrCreateUser('facebook', $userInfo['data']);
        } catch (\Exception $e) {
            $this->logger->error('facebook_oauth_error', $e->getMessage());
            return ['success' => false, 'message' => 'Facebook OAuth میں خرابی'];
        }
    }

    /**
     * Social account کو موجودہ صارف سے link کریں
     */
    public function linkSocialAccount(int $userId, string $provider, array $userData): array
    {
        try {
            // چیک کریں کہ یہ social account پہلے سے linked نہیں ہے
            $existing = $this->db->query(
                "SELECT * FROM social_accounts WHERE provider = ? AND provider_id = ? LIMIT 1",
                [$provider, $userData['id']]
            )->fetch();

            if ($existing && $existing->user_id !== $userId) {
                return ['success' => false, 'message' => 'یہ social account کسی دوسرے صارف سے linked ہے'];
            }

            // Link کریں
            $this->socialAccountModel->create([
                'user_id' => $userId,
                'provider' => $provider,
                'provider_id' => $userData['id'],
                'provider_email' => $userData['email'],
                'provider_name' => $userData['name'],
                'data' => json_encode($userData),
            ]);

            $this->auditTrail->log('social_account_linked', "User $userId linked $provider account", [
                'user_id' => $userId,
                'provider' => $provider,
                'provider_email' => $userData['email'],
            ]);

            return ['success' => true, 'message' => 'Social account کامیابی سے link ہوا'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Social account کو unlink کریں
     */
    public function unlinkSocialAccount(int $userId, string $provider): array
    {
        try {
            $this->db->query(
                "DELETE FROM social_accounts WHERE user_id = ? AND provider = ?",
                [$userId, $provider]
            );

            $this->auditTrail->log('social_account_unlinked', "User $userId unlinked $provider account", [
                'user_id' => $userId,
                'provider' => $provider,
            ]);

            return ['success' => true, 'message' => 'Social account کامیابی سے unlink ہوا'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * صارف کے linked social accounts
     */
    public function getLinkedAccounts(int $userId): array
    {
        return $this->db->query(
            "SELECT provider, provider_email, provider_name, linked_at FROM social_accounts WHERE user_id = ? ORDER BY linked_at DESC",
            [$userId]
        )->fetchAll() ?? [];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Private Helper Methods
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Social account کو link یا create کریں
     */
    private function linkOrCreateUser(string $provider, array $userData): array
    {
        try {
            $this->db->beginTransaction();

            // چیک کریں کہ یہ social account پہلے سے موجود ہے
            $socialAccount = $this->db->query(
                "SELECT * FROM social_accounts WHERE provider = ? AND provider_id = ? LIMIT 1",
                [$provider, $userData['id']]
            )->fetch();

            if ($socialAccount) {
                // موجودہ صارف کو login کریں
                $user = $this->userModel->find($socialAccount->user_id);
                if ($user) {
                    $this->db->commit();
                    return [
                        'success' => true,
                        'user_id' => $user->id,
                        'message' => 'خوش آمدید!',
                        'is_new' => false,
                    ];
                }
            }

            // چیک کریں کہ email والا صارف موجود ہے
            $existingUser = $this->db->query(
                "SELECT * FROM users WHERE email = ? LIMIT 1",
                [$userData['email']]
            )->fetch();

            if ($existingUser) {
                // Link کریں
                $this->linkSocialAccount($existingUser->id, $provider, $userData);
                
                $this->db->commit();
                return [
                    'success' => true,
                    'user_id' => $existingUser->id,
                    'message' => 'Social account linked!',
                    'is_new' => false,
                ];
            }

            // نیا صارف بنائیں
            $newUser = $this->userModel->create([
                'email' => $userData['email'],
                'username' => $this->generateUniqueUsername($userData['email']),
                'name' => $userData['name'] ?? '',
                'password' => '', // Social logins میں خالی password
                'email_verified' => 1, // Social logins سے email verify شمار ہوتی ہے
                'phone_verified' => 0,
                'is_active' => 1,
            ]);

            if (!$newUser) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'صارف بنانے میں ناکام'];
            }

            // Social account link کریں
            $this->linkSocialAccount($newUser->id, $provider, $userData);

            $this->notificationService->send($newUser->id, "خوش آمدید! آپ کا صارف $provider سے بنایا گیا ہے");

            $this->auditTrail->log('social_user_created', "New user created via $provider", [
                'user_id' => $newUser->id,
                'provider' => $provider,
                'email' => $userData['email'],
            ]);

            $this->db->commit();
            return [
                'success' => true,
                'user_id' => $newUser->id,
                'message' => 'نیا صارف بنایا گیا!',
                'is_new' => true,
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('link_or_create_user_error', $e->getMessage());
            return ['success' => false, 'message' => 'صارف کو process کرنے میں ناکام'];
        }
    }

    /**
     * Unique username بنائیں
     */
    private function generateUniqueUsername(string $email): string
    {
        $baseUsername = explode('@', $email)[0];
        $username = substr($baseUsername, 0, 20); // 20 حروف تک

        $count = 1;
        while ($this->db->query("SELECT 1 FROM users WHERE username = ? LIMIT 1", [$username])->fetch()) {
            $username = substr($baseUsername, 0, 15) . rand(1000, 9999);
            $count++;
            if ($count > 10) break; // Loop سے بچیں
        }

        return $username;
    }

    /**
     * Google token حاصل کریں
     */
    private function getGoogleToken(string $code): array
    {
        $redirectUri = "{$this->appUrl}/auth/callback/google";

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $code,
            'client_id' => $this->googleClientId,
            'client_secret' => $this->googleClientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['access_token'])) {
            return ['success' => true, 'access_token' => $response['access_token']];
        }

        return ['success' => false, 'message' => 'Google token حاصل نہیں کر سکے'];
    }

    /**
     * Google user info حاصل کریں
     */
    private function getGoogleUserInfo(string $accessToken): array
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['id'])) {
            return [
                'success' => true,
                'data' => [
                    'id' => $response['id'],
                    'email' => $response['email'],
                    'name' => $response['name'],
                    'picture' => $response['picture'] ?? null,
                ]
            ];
        }

        return ['success' => false, 'message' => 'Google user info حاصل نہیں کر سکے'];
    }

    /**
     * Facebook token حاصل کریں
     */
    private function getFacebookToken(string $code): array
    {
        $redirectUri = "{$this->appUrl}/auth/callback/facebook";

        $ch = curl_init('https://graph.facebook.com/v12.0/oauth/access_token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $this->facebookAppId,
            'client_secret' => $this->facebookAppSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['access_token'])) {
            return ['success' => true, 'access_token' => $response['access_token']];
        }

        return ['success' => false, 'message' => 'Facebook token حاصل نہیں کر سکے'];
    }

    /**
     * Facebook user info حاصل کریں
     */
    private function getFacebookUserInfo(string $accessToken): array
    {
        $ch = curl_init('https://graph.facebook.com/me?fields=id,email,name,picture&access_token=' . urlencode($accessToken));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['id'])) {
            return [
                'success' => true,
                'data' => [
                    'id' => $response['id'],
                    'email' => $response['email'] ?? null,
                    'name' => $response['name'],
                    'picture' => $response['picture']['data']['url'] ?? null,
                ]
            ];
        }

        return ['success' => false, 'message' => 'Facebook user info حاصل نہیں کر سکے'];
    }
}
