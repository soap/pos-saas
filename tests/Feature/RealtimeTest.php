<?php

namespace Tests\Feature;

use App\Support\Realtime\StoreActivity;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** ภาคผนวก D3 Phase 2 — สัญญาณ realtime ผ่าน Reverb (private channel ต่อสาขา) */
class RealtimeTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_order_change_signals_its_store_with_ids_only(): void
    {
        $order = $this->createOrder($this->cashierA1);
        Event::fake([StoreActivity::class]);

        $this->api($this->cashierA1, 'POST', "orders/{$order['id']}/send-to-kitchen")->assertCreated();

        Event::assertDispatched(StoreActivity::class, function (StoreActivity $activity) use ($order): bool {
            return $activity->broadcastOn()->name === "private-store.{$this->storeA1->id}"
                && $activity->broadcastWith() === ['order_id' => $order['id'], 'event_type' => 'kitchen.ticket_sent', 'payment_id' => null];
        });
    }

    public function test_member_is_authorized_for_own_store_channel(): void
    {
        $this->useReverbBroadcaster();

        $this->api($this->kitchenA1, 'POST', 'broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$this->storeA1->id}",
        ])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_channel_of_another_store_is_refused_with_403(): void
    {
        $this->useReverbBroadcaster();

        $this->api($this->cashierA1, 'POST', 'broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$this->storeA2->id}",
        ])->assertForbidden();
    }

    public function test_user_of_another_tenant_cannot_authorize_store_channel(): void
    {
        $this->useReverbBroadcaster();

        $this->api($this->ownerB, 'POST', 'broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$this->storeA1->id}",
        ])->assertForbidden();
    }

    /** phpunit.xml ใช้ null broadcaster (ไม่ตรวจสิทธิ์ channel) — สลับเป็น reverb แล้วลงทะเบียน channel ใหม่ */
    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }
}
