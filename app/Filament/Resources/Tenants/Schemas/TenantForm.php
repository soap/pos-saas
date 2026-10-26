<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * หน้า create ส่งข้อมูลรูปเดียวกับ POST /admin/tenants ให้ TenantProvisioner
 * หน้า edit แก้ได้เฉพาะชื่อ/สถานะ/timezone (slug ผูกกับ subdomain จึงห้ามแก้)
 */
class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('ร้านค้า')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('ชื่อร้าน')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label('Slug (subdomain)')
                            ->rules(fn (string $operation): array => $operation === 'create' ? Tenant::slugRules() : [])
                            ->required()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->default('Asia/Bangkok')
                            ->required(),
                        Select::make('status')
                            ->label('สถานะ')
                            ->options(TenantResource::STATUSES)
                            ->required()
                            ->visibleOn('edit'),
                        Select::make('plan_code')
                            ->label('แพ็กเกจ')
                            ->options(fn (): array => Plan::query()->where('is_active', true)->orderBy('price_satang')->pluck('name', 'code')->all())
                            ->required()
                            ->visibleOn('create'),
                    ]),
                Section::make('เจ้าของร้าน')
                    ->description('ถ้ามีอีเมลนี้ในระบบแล้วจะใช้ผู้ใช้เดิม และไม่ต้องกรอกรหัสผ่าน')
                    ->columns(3)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('owner.name')
                            ->label('ชื่อ')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner.email')
                            ->label('อีเมล')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner.password')
                            ->label('รหัสผ่าน')
                            ->password()
                            ->minLength(8)
                            ->maxLength(255),
                    ]),
                Section::make('สาขาแรก')
                    ->columns(2)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('store.code')
                            ->label('รหัสสาขา')
                            ->required()
                            ->alphaDash()
                            ->maxLength(32),
                        TextInput::make('store.name')
                            ->label('ชื่อสาขา')
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
