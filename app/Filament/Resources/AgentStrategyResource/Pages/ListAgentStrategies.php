<?php

namespace App\Filament\Resources\AgentStrategyResource\Pages;

use App\Filament\Resources\AgentStrategyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentStrategies extends ListRecords
{
    protected static string $resource = AgentStrategyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
