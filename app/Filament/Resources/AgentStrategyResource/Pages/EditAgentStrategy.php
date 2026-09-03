<?php

namespace App\Filament\Resources\AgentStrategyResource\Pages;

use App\Filament\Resources\AgentStrategyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentStrategy extends EditRecord
{
    protected static string $resource = AgentStrategyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
