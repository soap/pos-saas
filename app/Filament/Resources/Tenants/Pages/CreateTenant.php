<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\Tenancy\Services\TenantProvisioner;
use App\Support\Errors\ApiException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** สร้าง tenant + owner + สาขาแรก + subscription ผ่าน provisioner ตัวเดียวกับ API */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(TenantProvisioner::class)->provision($data);
        } catch (ApiException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
