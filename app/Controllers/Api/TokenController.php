<?php

namespace App\Controllers\Api;

use Core\Database;
use Core\RateLimiter;

/**
 * API\TokenController - مدیریت API Token
 *
 * POST /api/v1/auth/token    → دریافت token با credentials
 * POST /api/v1/auth/revoke   → باطل کردن token
 * GET  /api/v1/auth/tokens   → لیست tokenهای فعال (نیاز به auth)
 */
class TokenController extends BaseApiController
{
    private Database $db;

    public function __construct(Database $db){
        parent::__construct();
        $this->db = $db;
        }

    /**
     * دریافت API Token با email/password
     * این endpoint نیاز به middleware auth ندارد
     */
    public function issue(): never
{
    $data = $this->request->body();

    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');

    if ($email === '' || $password === '') {
        $this->validationError([
            'email'    => $email === '' ? 'ایمیل الزامی است' : null,
            'password' => $password === '' ? 'رمز الزامی است' : null,
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('فرمت ایمیل نامعتبر است', 422, 'INVALID_EMAIL');
    }

    // Rate limit برای endpoint صدور توکن
    if ($this->isIssueRateLimited($email)) {
        $this->error('تعداد تلاش بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید', 429, 'RATE_LIMITED');
    }

    $user = $this->db->fetch(
        "SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1",
        [$email]
    );

    if (!$user || !password_verify($password, $user->password)) {
        $this->hitIssueRateLimit($email);
        $this->error('ایمیل یا رمز عبور اشتباه است', 401, 'INVALID_CREDENTIALS');
    }

    if ((int)$user->status !== 1) {
        $this->error('حساب کاربری غیرفعال است', 403, 'ACCOUNT_INACTIVE');
    }

    $token = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $name = trim((string)($data['token_name'] ?? ''));
    if ($name === '') {
        $name = 'api-token-' . date('Ymd');
    }
    $name = mb_substr($name, 0, 80);

    $rawScopes = trim((string)($data['scopes'] ?? 'read'));
    $scopes = preg_replace('/[^a-z0-9,_-]/i', '', $rawScopes);
    if ($scopes === '') {
        $scopes = 'read';
    }

    $this->db->query(
        "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$user->id, $hashedToken, $name, $scopes, $expiresAt]
    );

    // موفقیت: ریست شمارنده rate limit
    $this->clearIssueRateLimit($email);

    $this->success([
        'token'      => $token,
        'type'       => 'Bearer',
        'expires_at' => $expiresAt,
        'name'       => $name,
        'scopes'     => $scopes,
    ], 'توکن با موفقیت صادر شد', 201);
}

    /**
     * باطل کردن token جاری
     */
    public function revoke(): never
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token      = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : null;

        if (!$token) {
            $this->error('توکن یافت نشد', 400);
        }

        $hashedToken = hash('sha256', $token);
        $affected = $this->db->execute(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE token = ?",
            [$hashedToken]
        );

        if ($affected === 0) {
            $this->error('توکن یافت نشد یا قبلاً باطل شده', 404);
        }

        $this->success(null, 'توکن با موفقیت باطل شد');
    }

    /**
     * لیست tokenهای فعال کاربر
     */
    public function list(): never
    {
        $userId = $this->userId();

        $tokens = $this->db->fetchAll(
            "SELECT id, name, scopes, last_used_at, use_count, expires_at, created_at
             FROM api_tokens
             WHERE user_id = ? AND revoked = 0
             ORDER BY created_at DESC",
            [$userId]
        );

        $this->success($tokens);
    }

    /**
     * باطل کردن یک token خاص
     */
    public function revokeById(): never
    {
        $userId  = $this->userId();
        $tokenId = (int)($this->request->get('id') ?? 0);

        if (!$tokenId) {
            $this->error('ID توکن الزامی است', 400);
        }

        $affected = $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW()
             WHERE id = ? AND user_id = ? AND revoked = 0",
            [$tokenId, $userId]
        );

        if (!$affected) {
            $this->error('توکن یافت نشد', 404);
        }

        $this->success(null, 'توکن باطل شد');
    }
	
	private function issueRateLimitKey(string $email): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return 'api_token_issue_rl_' . sha1($ip . '|' . $email);
}

private function isIssueRateLimited(string $email): bool
{
    $limiter = new RateLimiter();
    $key = $this->issueRateLimitKey($email);

    $maxAttempts = 8;
    return $limiter->hits($key) >= $maxAttempts;
}

private function hitIssueRateLimit(string $email): void
{
    $limiter = new RateLimiter();
    $key = $this->issueRateLimitKey($email);

    // همان پنجره قبلی: 10 دقیقه
    $limiter->incrementAttempts($key, 10);
}

private function clearIssueRateLimit(string $email): void
{
    $limiter = new RateLimiter();
    $limiter->clear($this->issueRateLimitKey($email));
}

}
