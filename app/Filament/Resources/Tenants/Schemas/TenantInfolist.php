<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ข้อมูลการใช้งานอ่านจาก ViewTenant::usage() (computed — query ครั้งเดียวต่อ request)
 */
class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('ร้านค้า')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('ชื่อร้าน'),
                        TextEntry::make('slug'),
                        TextEntry::make('status')
                            ->label('สถานะ')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => TenantResource::STATUSES[$state] ?? $state)
                            ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                        TextEntry::make('timezone'),
                        TextEntry::make('created_at')
                            ->label('สร้างเมื่อ')
                            ->dateTime(),
                    ]),
                Section::make('แพ็กเกจและการใช้งาน')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('usage_plan')
                            ->label('แพ็กเกจ')
                            ->state(fn (ViewTenant $livewire): ?string => $livewire->usage['subscription']?->plan?->name)
                            ->placeholder('ไม่มีแพ็กเกจ'),
                        TextEntry::make('usage_subscription_status')
                            ->label('สถานะแพ็กเกจ')
                            ->badge()
                            ->state(fn (ViewTenant $livewire): ?string => $livewire->usage['subscription']?->status)
                            ->placeholder('-'),
                        TextEntry::make('usage_trial_ends_at')
                            ->label('สิ้นสุดทดลองใช้')
                            ->dateTime()
                            ->state(fn (ViewTenant $livewire) => $livewire->usage['subscription']?->trial_ends_at)
                            ->placeholder('-'),
                        TextEntry::make('usage_stores')
                            ->label('สาขา')
                            ->state(fn (ViewTenant $livewire): string => self::withLimit($livewire->usage['stores'], $livewire->usage['limits']['max_stores'])),
                        TextEntry::make('usage_members')
                            ->label('สมาชิก')
                            ->state(fn (ViewTenant $livewire): string => self::withLimit($livewire->usage['members'], $livewire->usage['limits']['max_users'])),
                        TextEntry::make('usage_orders_30d')
                            ->label('ออเดอร์ 30 วัน')
                            ->numeric()
                            ->state(fn (ViewTenant $livewire): int => $livewire->usage['orders_30d']),
                    ]),
            ]);
    }

    private static function withLimit(int $count, ?int $limit): string
    {
        return $count.' / '.($limit ?? 'ไม่จำกัด');
    }
}
