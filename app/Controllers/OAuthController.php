<?php

namespace App\Controllers;

use App\Services\OAuthService;
use Core\Request;
use Core\Response;

/**
 * OAuthController — Social Login (Google + Facebook)
 * 
 * Routes:
 * - GET /login/google → Google OAuth
 * - GET /login/facebook → Facebook OAuth
 * - GET /auth/callback/google → Google callback
 * - GET /auth/callback/facebook → Facebook callback
 */
class OAuthController extends BaseController
{
    public function __construct(
        private OAuthService $oauthService
    ) {}

    /**
     * Google login سے redirect کریں
     */
    public function loginGoogle(Request $request): Response
    {
        $url = $this->oauthService->getGoogleAuthUrl();
        return $this->redirect($url);
    }

    /**
     * Facebook login سے redirect کریں
     */
    public function loginFacebook(Request $request): Response
    {
        $url = $this->oauthService->getFacebookAuthUrl();
        return $this->redirect($url);
    }

    /**
     * Google callback handler
     */
    public function callbackGoogle(Request $request): Response
    {
        $code = $request->get('code');
        $state = $request->get('state');

        if (!$code || !$state) {
            return $this->json(['success' => false, 'message' => 'Invalid callback parameters']);
        }

        $result = $this->oauthService->handleGoogleCallback($code, $state);

        if ($result['success']) {
            // صارف کو login کریں
            $this->auth->loginUserId($result['user_id']);
            
            $message = $result['is_new'] 
                ? 'خوش آمدید! آپ کا نیا اکاؤنٹ بنایا گیا ہے'
                : 'خوش آمدید!';

            return $this->json([
                'success' => true,
                'message' => $message,
                'user_id' => $result['user_id'],
                'redirect' => '/dashboard',
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => $result['message'] ?? 'Google login میں خرابی',
        ]);
    }

    /**
     * Facebook callback handler
     */
    public function callbackFacebook(Request $request): Response
    {
        $code = $request->get('code');
        $state = $request->get('state');

        if (!$code || !$state) {
            return $this->json(['success' => false, 'message' => 'Invalid callback parameters']);
        }

        $result = $this->oauthService->handleFacebookCallback($code, $state);

        if ($result['success']) {
            $this->auth->loginUserId($result['user_id']);
            
            $message = $result['is_new'] 
                ? 'خوش آمدید! آپ کا نیا اکاؤنٹ بنایا گیا ہے'
                : 'خوش آمدید!';

            return $this->json([
                'success' => true,
                'message' => $message,
                'user_id' => $result['user_id'],
                'redirect' => '/dashboard',
            ]);
        }

        return $this->json([
            'success' => false,
            'message' => $result['message'] ?? 'Facebook login میں خرابی',
        ]);
    }

    /**
     * موجودہ صارف کے social accounts دیکھیں
     */
    public function listAccounts(Request $request): Response
    {
        $this->authorize('user.manage_social_accounts', $this->user);

        $accounts = $this->oauthService->getLinkedAccounts($this->user->id);
        
        return $this->json([
            'success' => true,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Social account کو link کریں (موجودہ صارف سے)
     */
    public function linkAccount(Request $request): Response
    {
        $this->authorize('user.manage_social_accounts', $this->user);

        $provider = $request->post('provider');
        $userData = $request->post('user_data');

        if (!$provider || !$userData) {
            return $this->json(['success' => false, 'message' => 'Invalid parameters']);
        }

        $result = $this->oauthService->linkSocialAccount($this->user->id, $provider, $userData);

        return $this->json($result);
    }

    /**
     * Social account کو unlink کریں
     */
    public function unlinkAccount(Request $request): Response
    {
        $this->authorize('user.manage_social_accounts', $this->user);

        $provider = $request->post('provider');

        if (!$provider) {
            return $this->json(['success' => false, 'message' => 'Invalid provider']);
        }

        // یقینی بنائیں کہ صارف کے پاس کم از کم ایک دوسرا login method ہے
        if ($this->user->password === null || $this->user->password === '') {
            // صرف social logins ہیں
            $accounts = $this->oauthService->getLinkedAccounts($this->user->id);
            if (count($accounts) <= 1) {
                return $this->json([
                    'success' => false,
                    'message' => 'آپ کو کم از کم ایک login method رکھنی ہوگی',
                ]);
            }
        }

        $result = $this->oauthService->unlinkSocialAccount($this->user->id, $provider);

        return $this->json($result);
    }
}
