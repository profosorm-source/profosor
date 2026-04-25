<?php

use Core\Session;

if (!function_exists('auth')) {
    function auth(): ?object
    {
        static $cached = null;
        static $cachedUserId = null;

        $session = \Core\Session::getInstance();
        $currentUserId = $session->has('user_id') ? (int)$session->get('user_id') : null;
        
        // ✅ اگر user تغییر کرده یا logout کرده، cache reset کن
        if ($currentUserId !== $cachedUserId) {
            $cached = null;
            $cachedUserId = $currentUserId;
        }

        if ($cached !== null) {
            return $cached;
        }

        if ($currentUserId === null) {
            return null;
        }

        $cached = (new \App\Models\User())->findById($currentUserId) ?: null;

        return $cached;
    }
}

if (!function_exists('auth_user')) {
    function auth_user()
    {
        static $user = null;
        static $cachedUserId = null;

        $session = \Core\Session::getInstance();
        $currentUserId = $session->has('user_id') ? (int)$session->get('user_id') : null;

        if (!$currentUserId) {
            $user = null;
            $cachedUserId = null;
            return null;
        }
        
        // ✅ اگر user تغییر کرده، cache reset کن
        if ($currentUserId !== $cachedUserId) {
            $user = null;
            $cachedUserId = $currentUserId;
        }

        if ($user === null) {
            $user = (new \App\Models\User())->findById($currentUserId);
        }

        return $user;
    }
}

// ✅ تابع جدید برای logout - cache را invalid کن
if (!function_exists('logout_user')) {
    function logout_user()
    {
        // تمام static caches را reset کن
        // (یا Session destroy کن)
        $session = \Core\Session::getInstance();
        $session->remove('user_id');
        $session->remove('user_role');
        
        // Remove static caches (توسط طریقی دیگر یا session invalidation)
        // این باید توسط Session class یا event system handle شود
    }
}

function user_id(): ?int
{
    $session = Session::getInstance();
    $id = $session->get('user_id');
    return $id ? (int)$id : null;
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        $session = Session::getInstance();
        return ($session->get('user_role') === 'admin');
    }
}

function is_kyc_verified(?int $userId = null): bool
{
    $userId = $userId ?? user_id();
    if (!$userId) return false;

    $user = db()->query("SELECT kyc_status FROM users WHERE id = ?", [$userId])->fetch();
    return $user && $user->kyc_status === 'verified';
}
