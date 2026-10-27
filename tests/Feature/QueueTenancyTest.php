<?php

namespace Tests\Feature;

use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PosWorld;
use Tests\Support\RecordTenantContextJob;
use Tests\TestCase;

/** QueueTenancy: tenant context ตามไปถึง queued job และไม่รั่วข้าม job/กลับมาทับผู้ dispatch */
class QueueTenancyTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        RecordTenantContextJob::$seen = [];
    }

    private function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function work(int $jobs): void
    {
        for ($i = 0; $i < $jobs; $i++) {
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        }
    }

    public function test_worker_runs_each_job_in_the_tenant_it_was_dispatched_from(): void
    {
        $this->ctx()->runAs($this->tenantA, $this->storeA1, function (): void {
            RecordTenantContextJob::dispatch($this->noodleA)->onConnection('database');
        });
        $this->ctx()->runAs($this->tenantB, null, function (): void {
            RecordTenantContextJob::dispatch($this->noodleB)->onConnection('database');
        });
        RecordTenantContextJob::dispatch()->onConnection('database');

        $this->work(3);

        $this->assertSame([
            ['tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA1->id, 'user_id' => null, 'product_name' => $this->noodleA->name],
            ['tenant_id' => $this->tenantB->id, 'store_id' => null, 'user_id' => null, 'product_name' => $this->noodleB->name],
            // job ระดับ central ไม่ได้ tenant ของ job ก่อนหน้า
            ['tenant_id' => null, 'store_id' => null, 'user_id' => null, 'product_name' => null],
        ], RecordTenantContextJob::$seen);
        $this->assertFalse($this->ctx()->hasTenant());
    }

    public function test_sync_job_restores_the_dispatching_context(): void
    {
        $membership = $this->ctx()->withoutTenancy(fn () => TenantMembership::query()
            ->where('tenant_id', $this->tenantA->id)->where('user_id', $this->ownerA->id)->firstOrFail());
        $this->ctx()->setTenant($this->tenantA, $membership, $this->ownerA);
        $this->ctx()->setStore($this->storeA1);

        RecordTenantContextJob::dispatchSync($this->noodleA);

        // job รันในนามระบบ ไม่ได้สิทธิ์ของผู้ใช้ที่ dispatch
        $this->assertNull(RecordTenantContextJob::$seen[0]['user_id']);
        $this->assertSame($this->tenantA->id, RecordTenantContextJob::$seen[0]['tenant_id']);
        $this->assertSame($this->ownerA->id, $this->ctx()->user()?->id);
        $this->assertSame($this->storeA1->id, $this->ctx()->storeId());
    }

    public function test_job_fails_instead_of_running_unscoped_when_its_tenant_is_unknown(): void
    {
        $this->ctx()->runAs($this->tenantA, null, function (): void {
            RecordTenantContextJob::dispatch()->onConnection('database');
        });
        $job = DB::table('jobs')->first();
        $payload = json_decode($job->payload, true);
        $payload['tenant_id'] = (string) Str::uuid();
        DB::table('jobs')->where('id', $job->id)->update(['payload' => json_encode($payload)]);

        $this->work(1);

        $this->assertSame([], RecordTenantContextJob::$seen);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertFalse($this->ctx()->hasTenant());
    }
}
