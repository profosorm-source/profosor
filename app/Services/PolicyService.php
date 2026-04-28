<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use Core\Database;
use Core\Logger;

/**
 * PolicyService — Centralized Authorization & Role-Based Access Control
 * 
 * یہ service تمام authorization logic کو centralize کرتا ہے
 * تاکہ role/permission checks کے لیے ایک single source of truth ہو
 */
class PolicyService
{
    private array $permissionCache = [];

    public function __construct(
        private Database $db,
        private Logger $logger,
        private User $userModel,
        private Role $roleModel,
        private AuditTrail $auditTrail
    ) {}

    /**
     * چیک کریں کہ صارف کوئی action کر سکتا ہے یا نہیں
     * 
     * @param string $action Action (admin.create_user, user.edit_profile, etc)
     * @param User $user صارف
     * @param mixed $resource متعلقہ resource
     * @return bool
     */
    public function can(string $action, User $user, $resource = null): bool
    {
        // Super admin کو ہمیشہ اجازت
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Specific resource checks
        if ($resource) {
            return $this->canOnResource($action, $user, $resource);
        }

        // General permission check
        return $this->hasPermission($user, $action);
    }

    /**
     * Authorize کریں (اگر fail ہو تو exception دیں)
     */
    public function authorize(string $action, User $user, $resource = null): void
    {
        if (!$this->can($action, $user, $resource)) {
            $this->auditTrail->log('authorization_denied', "User {$user->id} denied for action: $action", [
                'action' => $action,
                'user_id' => $user->id,
                'resource' => $resource,
            ]);

            throw new \Exception("آپ کو اس کام کی اجازت نہیں ہے ($action)");
        }
    }

    /**
     * Specific resource پر permission check کریں
     */
    private function canOnResource(string $action, User $user, $resource): bool
    {
        // Owner کو اپنے resource پر ہمیشہ action کی اجازت
        if (isset($resource->user_id) && $resource->user_id === $user->id) {
            return true;
        }

        // Role-based resource actions
        $parts = explode('.', $action);
        if (count($parts) >= 2) {
            [$resource_type, $action_name] = $parts;

            // مثال: admin.delete_user → اگر user admin ہے
            if ($resource_type === 'admin' && $this->isAdmin($user)) {
                return true;
            }
        }

        return $this->hasPermission($user, $action);
    }

    /**
     * صارف کے لیے permission check کریں
     */
    private function hasPermission(User $user, string $action): bool
    {
        // Cache میں چیک کریں
        $cacheKey = "user_{$user->id}_action_{$action}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        // Database میں چیک کریں
        $result = $this->db->query(
            "SELECT 1 FROM user_permissions up
             INNER JOIN roles r ON up.role_id = r.id
             INNER JOIN permissions p ON up.permission_id = p.id
             WHERE r.user_id = ? AND p.slug = ? AND up.is_active = 1
             LIMIT 1",
            [$user->id, $action]
        )->fetch();

        $hasPermission = (bool) $result;
        $this->permissionCache[$cacheKey] = $hasPermission;

        return $hasPermission;
    }

    /**
     * چیک کریں کہ صارف admin ہے یا نہیں
     */
    public function isAdmin(User $user): bool
    {
        return $user->role === 'admin' || $user->role === 'super_admin';
    }

    /**
     * چیک کریں کہ صارف super admin ہے یا نہیں
     */
    public function isSuperAdmin(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    /**
     * چیک کریں کہ صارف moderator ہے یا نہیں
     */
    public function isModerator(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin', 'moderator']);
    }

    /**
     * صارف کے تمام permissions حاصل کریں
     */
    public function getPermissions(User $user): array
    {
        $permissions = $this->db->query(
            "SELECT p.slug, p.name FROM user_permissions up
             INNER JOIN permissions p ON up.permission_id = p.id
             INNER JOIN roles r ON up.role_id = r.id
             WHERE r.user_id = ? AND up.is_active = 1",
            [$user->id]
        )->fetchAll() ?? [];

        return array_map(fn($p) => $p->slug, $permissions);
    }

    /**
     * صارف کے roles حاصل کریں
     */
    public function getRoles(User $user): array
    {
        return $this->db->query(
            "SELECT slug, name FROM roles WHERE user_id = ?",
            [$user->id]
        )->fetchAll() ?? [];
    }

    /**
     * صارف کو role دیں
     */
    public function grantRole(User $user, string $roleSlug, ?int $grantedBy = null): bool
    {
        try {
            $role = $this->roleModel->findBySlug($roleSlug);
            if (!$role) {
                throw new \Exception("Role '$roleSlug' نہیں ملا");
            }

            $this->db->query(
                "INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, granted_at) 
                 VALUES (?, ?, ?, NOW())",
                [$user->id, $role->id, $grantedBy]
            );

            $this->auditTrail->log('role_granted', "Granted role $roleSlug to user {$user->id}", [
                'user_id' => $user->id,
                'role' => $roleSlug,
                'granted_by' => $grantedBy,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('grant_role_error', $e->getMessage());
            return false;
        }
    }

    /**
     * صارف سے role ہٹائیں
     */
    public function revokeRole(User $user, string $roleSlug, ?int $revokedBy = null): bool
    {
        try {
            $role = $this->roleModel->findBySlug($roleSlug);
            if (!$role) {
                throw new \Exception("Role '$roleSlug' نہیں ملا");
            }

            $this->db->query(
                "DELETE FROM user_roles WHERE user_id = ? AND role_id = ?",
                [$user->id, $role->id]
            );

            $this->auditTrail->log('role_revoked', "Revoked role $roleSlug from user {$user->id}", [
                'user_id' => $user->id,
                'role' => $roleSlug,
                'revoked_by' => $revokedBy,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('revoke_role_error', $e->getMessage());
            return false;
        }
    }

    /**
     * صارف کو permission دیں
     */
    public function grantPermission(User $user, string $permissionSlug, ?int $grantedBy = null): bool
    {
        try {
            $permission = $this->db->query(
                "SELECT id FROM permissions WHERE slug = ? LIMIT 1",
                [$permissionSlug]
            )->fetch();

            if (!$permission) {
                throw new \Exception("Permission '$permissionSlug' نہیں ملا");
            }

            $role = $this->db->query(
                "SELECT id FROM roles WHERE user_id = ? LIMIT 1",
                [$user->id]
            )->fetch();

            if (!$role) {
                throw new \Exception("صارف کے لیے کوئی role نہیں ملا");
            }

            $this->db->query(
                "INSERT IGNORE INTO user_permissions (role_id, permission_id, granted_by, granted_at)
                 VALUES (?, ?, ?, NOW())",
                [$role->id, $permission->id, $grantedBy]
            );

            $this->auditTrail->log('permission_granted', "Granted permission $permissionSlug to user {$user->id}", [
                'user_id' => $user->id,
                'permission' => $permissionSlug,
                'granted_by' => $grantedBy,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('grant_permission_error', $e->getMessage());
            return false;
        }
    }

    /**
     * صارف سے permission ہٹائیں
     */
    public function revokePermission(User $user, string $permissionSlug, ?int $revokedBy = null): bool
    {
        try {
            $permission = $this->db->query(
                "SELECT id FROM permissions WHERE slug = ? LIMIT 1",
                [$permissionSlug]
            )->fetch();

            if (!$permission) {
                throw new \Exception("Permission '$permissionSlug' نہیں ملا");
            }

            $this->db->query(
                "DELETE up FROM user_permissions up
                 INNER JOIN roles r ON up.role_id = r.id
                 WHERE r.user_id = ? AND up.permission_id = ?",
                [$user->id, $permission->id]
            );

            $this->auditTrail->log('permission_revoked', "Revoked permission $permissionSlug from user {$user->id}", [
                'user_id' => $user->id,
                'permission' => $permissionSlug,
                'revoked_by' => $revokedBy,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('revoke_permission_error', $e->getMessage());
            return false;
        }
    }

    /**
     * Cache کو صاف کریں (صارف کے لیے)
     */
    public function clearCache(int $userId): void
    {
        $keysToDelete = array_keys($this->permissionCache, function($key) use ($userId) {
            return strpos($key, "user_{$userId}_") === 0;
        });

        foreach ($keysToDelete as $key) {
            unset($this->permissionCache[$key]);
        }
    }

    /**
     * تمام common admin actions کی permissions
     */
    public function getAdminActionPermissions(): array
    {
        return [
            'admin.view_dashboard' => 'Dashboard دیکھیں',
            'admin.manage_users' => 'صارفین کا انتظام کریں',
            'admin.manage_roles' => 'Roles کا انتظام کریں',
            'admin.manage_permissions' => 'Permissions کا انتظام کریں',
            'admin.view_transactions' => 'Transactions دیکھیں',
            'admin.approve_withdrawals' => 'Withdrawals منظور کریں',
            'admin.manage_content' => 'Content کا انتظام کریں',
            'admin.view_reports' => 'Reports دیکھیں',
            'admin.system_settings' => 'System settings',
            'admin.manage_support_tickets' => 'Support tickets',
        ];
    }
}
