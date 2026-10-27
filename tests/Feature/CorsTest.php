<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** CORS allowlist (config/cors.php) — ค่าตั้งต้นไม่เปิด cross-origin; เปิดเฉพาะ origin ใน POS_CORS_ALLOWED_ORIGINS */
class CorsTest extends TestCase
{
    public function test_api_does_not_allow_any_cross_origin_by_default(): void
    {
        $this->preflight('https://evil.example')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_listed_origin_may_call_api_with_pos_headers(): void
    {
        config(['cors.allowed_origins' => ['https://app.pos.test']]);

        $response = $this->preflight('https://app.pos.test')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.pos.test');

        $allowedHeaders = explode(', ', (string) $response->headers->get('Access-Control-Allow-Headers'));
        foreach (['authorization', 'x-tenant-id', 'x-store-id', 'idempotency-key'] as $header) {
            $this->assertContains($header, $allowedHeaders);
        }
    }

    public function test_unlisted_origin_is_not_granted_while_allowlist_is_set(): void
    {
        config(['cors.allowed_origins' => ['https://app.pos.test', 'https://admin.pos.test']]);

        $this->preflight('https://app.pos.test.evil.example')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function preflight(string $origin): TestResponse
    {
        return $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, x-store-id, idempotency-key',
        ]);
    }
}
