<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\Audit\Services\AuditLogger;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * ไม่มีปุ่มลบ — ลบ tenant กระทบข้อมูลทุกตาราง ให้ระงับ (suspended) แทน
 */
class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /** @var array<string,mixed> */
    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->before = $this->getRecord()->only(['name', 'status', 'timezone']);
    }

    protected function afterSave(): void
    {
        $tenant = $this->getRecord();
        app(AuditLogger::class)->log('admin.tenant_updated', $tenant, [
            'before' => $this->before,
            'after' => $tenant->only(['name', 'status', 'timezone']),
        ], tenantId: $tenant->getKey());
    }
}
