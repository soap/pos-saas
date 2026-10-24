<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * ยิง request พร้อมกันจริงจากหลายโปรเซส (spec §10: Order number, Refund, Cashier, Idempotency)
 *
 * request ในโปรเซสอื่นมองไม่เห็นข้อมูลใน transaction ของ RefreshDatabase
 * → class ที่ใช้ trait นี้ต้อง override refreshDatabase() ให้เรียก useCommittedDatabase()
 *   ข้อมูลจะถูก commit จริง แล้ว truncate ทิ้งก่อนและหลังทุก test
 */
trait ConcurrentRequests
{
    protected function useCommittedDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;
        }
        $this->truncateAllTables();
        $this->beforeApplicationDestroyed(fn () => $this->truncateAllTables());
    }

    private function truncateAllTables(): void
    {
        $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename <> 'migrations'"))
            ->map(fn (object $row): string => '"'.$row->tablename.'"')
            ->implode(', ');
        DB::statement("TRUNCATE {$tables} RESTART IDENTITY CASCADE");
    }

    /**
     * @param  list<array{method: string, uri: string, body?: array<string, mixed>, idempotency_key?: string}>  $requests
     * @return list<array{status: int, body: mixed}> เรียงตามลำดับ $requests
     */
    protected function concurrently(User $user, array $requests, ?Store $store = null): array
    {
        $store ??= $this->storeA1;
        $tenant = Tenant::query()->findOrFail($store->tenant_id);
        $token = $user->createToken('concurrency-test')->plainTextToken;
        $startAt = microtime(true) + 3;
        $database = DB::connection()->getDatabaseName();
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_URL' => '',
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => $database,
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ];

        $results = Process::pool(function (Pool $pool) use ($requests, $tenant, $store, $token, $startAt, $database, $env) {
            foreach ($requests as $index => $request) {
                $spec = [
                    'method' => $request['method'],
                    'uri' => "/api/v1/t/{$tenant->slug}/".ltrim($request['uri'], '/'),
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'X-Store-Id' => $store->id,
                        'Idempotency-Key' => $request['idempotency_key'] ?? (string) Str::uuid(),
                    ],
                    'body' => $request['body'] ?? [],
                    'start_at' => $startAt,
                    'database' => $database,
                ];
                $pool->as((string) $index)
                    ->path(base_path())
                    ->env($env)
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Support/concurrent-request.php', base64_encode(json_encode($spec))]);
            }
        })->start()->wait();

        return collect($requests)->keys()->map(function (int $index) use ($results): array {
            $process = $results[(string) $index];
            $this->assertTrue($process->successful(), "request #{$index} crashed: ".$process->errorOutput().$process->output());

            return json_decode($process->output(), true, flags: JSON_THROW_ON_ERROR);
        })->all();
    }

    /**
     * @param  list<array{status: int, body: mixed}>  $responses
     * @return list<int>
     */
    protected function statuses(array $responses): array
    {
        $statuses = array_column($responses, 'status');
        sort($statuses);

        return $statuses;
    }
}
