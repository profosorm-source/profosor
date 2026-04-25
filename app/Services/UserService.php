<?php
namespace App\Services;

use App\Models\User;


/**
 * User Service
 */
class UserService
{
    private $userModel;
    

    public function __construct(
       User $userModel,
        
    )
    {
        $this->userModel = $userModel;
        
    }

    /**
     * بروزرسانی پروفایل
     */
    public function updateProfile($userId, array $data)
    {
        // فیلدهای مجاز برای بروزرسانی
        $allowedFields = ['full_name', 'birth_date', 'gender'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));
        
        if (empty($updateData)) {
            return [
                'success' => false,
                'message' => 'هیچ داده‌ای برای بروزرسانی ارسال نشده است.'
            ];
        }
        
        $result = $this->userModel->update($userId, $updateData);
        
        if ($result) {
            $this->logger->activity('user.profile.updated', 'بروزرسانی پروفایل', $userId, array_merge(
    ['channel' => 'user_profile'],
    $updateData
));
            return [
                'success' => true,
                'message' => 'پروفایل با موفقیت بروزرسانی شد.'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'خطا در بروزرسانی پروفایل.'
        ];
    }

    /**
     * تغییر رمز عبور
     */
    public function changePassword($userId, $currentPassword, $newPassword)
    {
        // بررسی رمز فعلی
        if (!$this->userModel->verifyPassword($userId, $currentPassword)) {
            return [
                'success' => false,
                'message' => 'رمز عبور فعلی اشتباه است.'
            ];
        }
        
        // تغییر رمز
        $this->userModel->changePassword($userId, $newPassword);
        
        // ثبت لاگ
        $this->logger->activity('user.password.changed', 'تغییر رمز عبور', $userId, [
    'channel' => 'user_profile',
]);
        return [
            'success' => true,
            'message' => 'رمز عبور با موفقیت تغییر کرد.'
        ];
    }

    /**
     * آپلود آواتار
     */
    public function uploadAvatar($userId, $file)
    {
        try {
            // بررسی نوع فایل
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowedTypes)) {
                return [
                    'success' => false,
                    'message' => 'فرمت فایل مجاز نیست. فقط JPG, PNG, GIF مجاز است.'
                ];
            }
            
            // بررسی حجم (2MB)
            if ($file['size'] > 2097152) {
                return [
                    'success' => false,
                    'message' => 'حجم فایل نباید بیشتر از 2 مگابایت باشد.'
                ];
            }
            
            // حذف آواتار قبلی
            $user = $this->userModel->find($userId);
            if ($user && $user['avatar']) {
                delete_file($user['avatar']);
            }
            
            // آپلود فایل
            $path = upload_file($file, 'avatars');
            
            // بروزرسانی دیتابیس
            $this->userModel->update($userId, ['avatar' => $path]);
            
            // ثبت لاگ
            $this->logger->activity('user.avatar.uploaded', 'آپلود تصویر پروفایل', $userId, [
    'channel' => 'user_profile',
]);
            return [
                'success' => true,
                'message' => 'تصویر پروفایل با موفقیت آپلود شد.',
                'path' => $path
            ];
            
        } catch (\Exception $e) {
    $this->logger->error('user.avatar_upload.failed', [
        'channel' => 'user_profile',
        'user_id' => $userId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
            
            return [
                'success' => false,
                'message' => 'خطا در آپلود تصویر.'
            ];
        }
    }

    /**
     * دریافت آمار داشبورد
     */
    public function getDashboardStats($userId)
    {
        return $this->userModel->getUserStats($userId);
    }

    public function findById(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }
}
