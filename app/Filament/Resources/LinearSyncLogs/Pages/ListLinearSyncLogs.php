<?php

namespace App\Filament\Resources\LinearSyncLogs\Pages;

use App\Filament\Resources\LinearSyncLogs\LinearSyncLogResource;
use Filament\Resources\Pages\ListRecords;

class ListLinearSyncLogs extends ListRecords
{
    protected static string $resource = LinearSyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action - logs are read-only
        ];
    }
}
