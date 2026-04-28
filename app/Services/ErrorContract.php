<?php

namespace App\Services;

/**
 * ErrorContract - استاندارد یکنواخت برای پاسخ‌های خطا در سراسر API و Web
 * 
 * این contract تمام انواع خطا (Validation, Authorization, Server, etc.) را normalize می‌کند.
 */
class ErrorContract
{
    // HTTP Status Codes
    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_UNAUTHORIZED = 401;
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_CONFLICT = 409;
    public const STATUS_UNPROCESSABLE = 422;
    public const STATUS_RATE_LIMITED = 429;
    public const STATUS_INTERNAL_ERROR = 500;

    // Error Codes (Business)
    public const CODE_VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const CODE_UNAUTHORIZED = 'UNAUTHORIZED';
    public const CODE_FORBIDDEN = 'FORBIDDEN';
    public const CODE_NOT_FOUND = 'NOT_FOUND';
    public const CODE_CONFLICT = 'CONFLICT';
    public const CODE_RATE_LIMITED = 'RATE_LIMITED';
    public const CODE_INTERNAL_ERROR = 'INTERNAL_ERROR';

    private int $statusCode;
    private string $errorCode;
    private string $message;
    private ?array $details;
    private ?array $fieldErrors;

    /**
     * Static factory for validation errors
     */
    public static function validation(string $message, array $fieldErrors = []): self
    {
        $error = new self(
            self::STATUS_UNPROCESSABLE,
            self::CODE_VALIDATION_ERROR,
            $message ?: 'Validation failed'
        );
        $error->fieldErrors = $fieldErrors;
        return $error;
    }

    /**
     * Static factory for unauthorized
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(
            self::STATUS_UNAUTHORIZED,
            self::CODE_UNAUTHORIZED,
            $message
        );
    }

    /**
     * Static factory for forbidden
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(
            self::STATUS_FORBIDDEN,
            self::CODE_FORBIDDEN,
            $message
        );
    }

    /**
     * Static factory for not found
     */
    public static function notFound(string $message = 'Not found'): self
    {
        return new self(
            self::STATUS_NOT_FOUND,
            self::CODE_NOT_FOUND,
            $message
        );
    }

    /**
     * Static factory for conflict
     */
    public static function conflict(string $message = 'Conflict'): self
    {
        return new self(
            self::STATUS_CONFLICT,
            self::CODE_CONFLICT,
            $message
        );
    }

    /**
     * Static factory for rate limited
     */
    public static function rateLimited(string $message = 'Rate limit exceeded'): self
    {
        return new self(
            self::STATUS_RATE_LIMITED,
            self::CODE_RATE_LIMITED,
            $message
        );
    }

    /**
     * Static factory for internal error
     */
    public static function internalError(string $message = 'Internal server error'): self
    {
        return new self(
            self::STATUS_INTERNAL_ERROR,
            self::CODE_INTERNAL_ERROR,
            $message
        );
    }

    public function __construct(int $statusCode, string $errorCode, string $message)
    {
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->message = $message;
        $this->details = null;
        $this->fieldErrors = null;
    }

    public function withDetails(array $details): self
    {
        $this->details = $details;
        return $this;
    }

    /**
     * تبدیل به array برای JSON response
     */
    public function toArray(): array
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->message,
            ],
        ];

        if ($this->fieldErrors) {
            $response['error']['field_errors'] = $this->fieldErrors;
        }

        if ($this->details) {
            $response['error']['details'] = $this->details;
        }

        return $response;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
