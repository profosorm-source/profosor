<?php

if (!function_exists('upload_file')) {
    function upload_file($file, $directory = 'general')
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('خطا در آپلود فایل');
        }

        // ✅ Allowed MIME types
        $allowedMimeTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf']
        ];

        // ✅ تشخیص MIME type واقعی از فایل (نه از user input)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowedMimeTypes[$actualMimeType])) {
            throw new \Exception('نوع فایل مجاز نیست: ' . $actualMimeType);
        }

        // ✅ بررسی extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = $allowedMimeTypes[$actualMimeType];
        
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \Exception('پسوند فایل مطابقت ندارد با نوع واقعی');
        }

        // ✅ حجم فایل
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new \Exception('حجم فایل بیش از حد مجاز است');
        }

        // ✅ Path Traversal Prevention - directory validation
        // فقط alphanumeric و underscore
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $directory)) {
            throw new \Exception('نام دایرکتوری معتبر نیست');
        }

        // ✅ Prevent accessing parent directories
        if (strpos($directory, '..') !== false || strpos($directory, '/') !== false) {
            throw new \Exception('نام دایرکتوری معتبر نیست');
        }

        $uploadDir = __DIR__ . '/../public/uploads/' . $directory;
        
        // ✅ اطمینان از اینکه uploadDir در public/uploads است
        $realUploadDir = realpath(dirname($uploadDir));
        $expectedBase = realpath(__DIR__ . '/../public/uploads');
        
        if (strpos($realUploadDir, $expectedBase) !== 0) {
            throw new \Exception('دایرکتوری آپلود معتبر نیست');
        }

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new \Exception('خطا در ایجاد دایرکتوری');
            }
        }

        // ✅ Prevent directory traversal via filename
        $filename = uniqid('file_', true) . '.' . $extension;
        
        // اطمینان از اینکه filename حاوی / یا .. نیست
        if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            throw new \Exception('نام فایل معتبر نیست');
        }

        $filepath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new \Exception('خطا در ذخیره فایل');
        }

        // ✅ Set proper permissions
        chmod($filepath, 0644);

        return 'uploads/' . $directory . '/' . $filename;
    }
}
