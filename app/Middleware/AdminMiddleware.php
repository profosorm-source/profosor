<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;

use App\Services\RolePolicy;

class AdminMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        $session = app()->session;

        if (!$session->has('user_id')) {
            if (is_ajax()) {
                $response->json(['success' => false, 'message' => 'لطفاً ابتدا وارد شوید.'], 401);
                exit;
            }
            $session->setFlash('error', 'لطفاً ابتدا وارد حساب کاربری خود شوید.');
            $response->redirect(url('login'));
            exit;
        }

        $role = $session->get('user_role') ?? '';

        if (!RolePolicy::isAdmin($role)) {
            if (is_ajax()) {
                $response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
                exit;
            }
            http_response_code(403);
            view('errors/403');
            exit;
        }

        return true;
    }
}
