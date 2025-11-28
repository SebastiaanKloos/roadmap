<?php

namespace App\Filament\Resources\LinearSyncLogs;

use App\Filament\Resources\LinearSyncLogs\Pages\ListLinearSyncLogs;
use App\Models\LinearSyncLog;
use App\Services\LinearService;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class LinearSyncLogResource extends Resource
{
    protected static ?string $model = LinearSyncLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 200;

    public static function getNavigationGroup(): ?string
    {
        return 'Linear';
    }

    public static function getNavigationLabel(): string
    {
        return 'Sync Logs';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (new LinearService)->isEnabled();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Read-only form
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('item.title')
                    ->label('Roadmap Item')
                    ->searchable()
                    ->limit(40)
                    ->url(fn ($record) => $record->item ? route('filament.admin.resources.items.edit', $record->item) : null)
                    ->tooltip(fn ($record) => $record->item?->title),
                TextColumn::make('linear_id')
                    ->label('Linear Issue ID')
                    ->searchable()
                    ->limit(20)
                    ->copyable()
                    ->tooltip('Click to copy'),
                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'info',
                        'delete' => 'danger',
                        'sync' => 'primary',
                        'error' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('direction')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'to_linear' => 'success',
                        'from_linear' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'skipped' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('message')
                    ->label('Message')
                    ->limit(50)
                    ->searchable()
                    ->tooltip(fn ($record) => $record->message),
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLinearSyncLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole(\App\Enums\UserRole::Admin);
    }
}
