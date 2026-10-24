<?php

namespace App\Support\Outbox;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishOutboxCommand extends Command
{
    protected $signature = 'outbox:publish {--limit=100} {--max-attempts=10}';

    protected $description = 'ส่ง outbox events ที่ยังไม่ publish (retry ได้, ใช้ SKIP LOCKED รองรับหลาย worker)';

    public function handle(): int
    {
        $published = 0;
        $failed = 0;

        $ids = DB::table('outbox_events')
            ->whereNull('published_at')
            ->where('attempts', '<', (int) $this->option('max-attempts'))
            ->orderBy('occurred_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$published, &$failed) {
                $row = DB::table('outbox_events')->where('id', $id)->whereNull('published_at')
                    ->lock('for update skip locked')->first();
                if (! $row) {
                    return;
                }
                try {
                    event(new OutboxMessage(
                        $row->id, $row->tenant_id, $row->aggregate_type, $row->aggregate_id,
                        $row->event_type, json_decode($row->payload, true) ?? [],
                    ));
                    DB::table('outbox_events')->where('id', $id)->update([
                        'published_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null,
                    ]);
                    $published++;
                } catch (Throwable $e) {
                    DB::table('outbox_events')->where('id', $id)->update([
                        'attempts' => $row->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                    $failed++;
                }
            });
        }

        $this->info("published={$published} failed={$failed}");

        return self::SUCCESS;
    }
}
