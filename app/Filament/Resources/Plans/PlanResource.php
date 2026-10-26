<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\ManagePlans;
use App\Modules\Billing\Models\Plan;
use App\Support\Money\Satang;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\StateCasts\KeyValueStateCast;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * แพ็กเกจ — ไม่มีปุ่มลบเพราะ subscription อ้างถึง ให้ปิดใช้งานด้วย is_active แทน
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'แพ็กเกจ';

    protected static ?string $pluralModelLabel = 'แพ็กเกจ';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('รหัส')
                    ->required()
                    ->maxLength(32)
                    ->alphaDash()
                    ->unique()
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->label('ชื่อ')
                    ->required()
                    ->maxLength(255),
                // กรอกเป็นบาท เก็บเป็นสตางค์ (BIGINT) — round() กันเศษ float เช่น 19.99 * 100 = 1998.999…
                TextInput::make('price_satang')
                    ->label('ราคา')
                    ->prefix('฿')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->rule('decimal:0,2')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
                    ->dehydrateStateUsing(fn (string|int|float $state): int => (int) round(((float) $state) * 100)),
                Select::make('billing_interval')
                    ->label('รอบบิล')
                    ->options(['month' => 'รายเดือน', 'year' => 'รายปี'])
                    ->required(),
                KeyValue::make('limits')
                    ->label('ขีดจำกัด')
                    ->keyLabel('key เช่น max_stores')
                    ->valueLabel('จำนวน')
                    // validation ได้ state ดิบของ KeyValue (แถว {key, value}) ก่อน cast → แปลงเป็น key => value ก่อนตรวจ
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        foreach (app(KeyValueStateCast::class)->get($value) as $key => $limit) {
                            if (filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                                $fail("ค่าของ {$key} ต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป");
                            }
                        }
                    })
                    ->dehydrateStateUsing(fn (?array $state): array => array_map('intval', $state ?? []))
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('เปิดใช้งาน')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('price_satang')
            ->columns([
                TextColumn::make('code')
                    ->label('รหัส')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('ชื่อ')
                    ->searchable(),
                TextColumn::make('price_satang')
                    ->label('ราคา')
                    ->formatStateUsing(fn (int $state): string => Satang::money($state)->format())
                    ->sortable(),
                TextColumn::make('billing_interval')
                    ->label('รอบบิล'),
                IconColumn::make('is_active')
                    ->label('เปิดใช้งาน')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlans::route('/'),
        ];
    }
}
