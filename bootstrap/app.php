<?php

use App\Modules\Tenancy\Http\Middleware\RequirePermission;
use App\Modules\Tenancy\Http\Middleware\RequirePlatformAdmin;
use App\Modules\Tenancy\Http\Middleware\ResolveStore;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Support\Errors\ApiException;
use App\Support\Http\RequestId;
use App\Support\Idempotency\Idempotent;
use App\Support\Outbox\PublishOutboxCommand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        PublishOutboxCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'store' => ResolveStore::class,
            'perm' => RequirePermission::class,
            'platform.admin' => RequirePlatformAdmin::class,
            'idempotent' => Idempotent::class,
        ]);
        // API ใช้ token เท่านั้น ไม่ redirect ไปหน้า login
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        // รูปแบบ error response กลาง (spec §7.2)
        $error = function (Request $request, int $status, string $code, string $message, array $details = []) {
            return response()->json([
                'error' => array_filter([
                    'code' => $code,
                    'message' => $message,
                    'details' => $details ?: null,
                ], fn ($v) => $v !== null),
                'request_id' => $request->attributes->get('request_id'),
            ], $status);
        };

        $exceptions->render(function (Throwable $e, Request $request) use ($error) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => $error($request, $e->status, $e->errorCode, $e->getMessage(), $e->details),
                $e instanceof ValidationException => $error($request, 422, 'validation_failed', 'ข้อมูลไม่ถูกต้อง', $e->errors()),
                $e instanceof AuthenticationException => $error($request, 401, 'unauthenticated', 'กรุณาเข้าสู่ระบบ'),
                $e instanceof AuthorizationException => $error($request, 403, 'forbidden', 'ไม่มีสิทธิ์'),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => $error($request, 404, 'not_found', 'ไม่พบข้อมูล'),
                $e instanceof ThrottleRequestsException => $error($request, 429, 'too_many_requests', 'ส่งคำขอถี่เกินไป'),
                $e instanceof QueryException && $e->getCode() === '23505' => $error($request, 409, 'conflict', 'ข้อมูลซ้ำหรือขัดแย้งกับข้อมูลที่มีอยู่'),
                $e instanceof QueryException && $e->getCode() === '40P01' => $error($request, 409, 'deadlock_retry', 'กรุณาลองใหม่อีกครั้ง'),
                $e instanceof HttpExceptionInterface => $error($request, $e->getStatusCode(), 'http_error', $e->getMessage() ?: 'เกิดข้อผิดพลาด'),
                default => $error($request, 500, 'server_error', config('app.debug') ? $e->getMessage() : 'เกิดข้อผิดพลาดภายในระบบ'),
            };
        });
    })->create();
