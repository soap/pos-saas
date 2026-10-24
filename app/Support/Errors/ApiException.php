<?php

namespace App\Support\Errors;

use RuntimeException;

/**
 * Domain/API error ที่ render เป็น error response กลาง:
 * { "error": { "code": "...", "message": "...", "details": {...} }, "request_id": "..." }
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $code, string $message, array $details = []): self
    {
        return new self(400, $code, $message, $details);
    }

    public static function forbidden(string $code, string $message, array $details = []): self
    {
        return new self(403, $code, $message, $details);
    }

    public static function notFound(string $code = 'not_found', string $message = 'ไม่พบข้อมูล'): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(409, $code, $message, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self(422, $code, $message, $details);
    }
}
