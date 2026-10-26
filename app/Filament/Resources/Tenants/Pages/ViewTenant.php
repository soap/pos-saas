<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingAdmin;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @return array{tenant_id:string,subscription:?Subscription,stores:int,members:int,orders_30d:int,limits:array{max_stores:?int,max_users:?int}}
     */
    #[Computed]
    public function usage(): array
    {
        return app(BillingAdmin::class)->usage($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeSubscription')
                ->label('เปลี่ยนแพ็กเกจ')
                ->icon('heroicon-o-arrows-right-left')
                ->fillForm(fn (): array => [
                    'plan_code' => $this->usage['subscription']?->plan?->code,
                    'status' => $this->usage['subscription']?->status ?? 'active',
                ])
                ->schema([
                    Select::make('plan_code')
                        ->label('แพ็กเกจ')
                        ->options(fn (): array => Plan::query()->where('is_active', true)->orderBy('price_satang')->pluck('name', 'code')->all())
                        ->required(),
                    Select::make('status')
                        ->label('สถานะ')
                        ->options(['trialing' => 'trialing', 'active' => 'active', 'past_due' => 'past_due'])
                        ->required(),
                    DateTimePicker::make('trial_ends_at')
                        ->label('สิ้นสุดทดลองใช้'),
                    DateTimePicker::make('ends_at')
                        ->label('สิ้นสุดแพ็กเกจ'),
                ])
                ->requiresConfirmation()
                ->modalDescription('แพ็กเกจปัจจุบันจะถูกยกเลิกและสร้างใหม่ทันที')
                ->action(function (array $data, BillingAdmin $billing): void {
                    $billing->changeSubscription(['tenant_id' => $this->getRecord()->getKey(), ...$data]);
                    unset($this->usage);

                    Notification::make()->title('เปลี่ยนแพ็กเกจแล้ว')->success()->send();
                }),
            EditAction::make(),
        ];
    }
}
