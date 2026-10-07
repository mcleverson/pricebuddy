<?php

namespace App\Filament\Resources\ProductResource\Actions;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Puts the selected products in "My Product Selection" on the Intelligence
 * page. The selection lives in pricebuddy-intelligence.
 */
class AddToSelectionBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'add_to_selection';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Add to My Product Selection'));

        $this->color('gray');

        $this->icon('heroicon-o-light-bulb');

        $this->action(function (Collection $records): void {
            try {
                Http::timeout(10)->acceptJson()
                    ->post(config('services.intelligence.url').'/v1/selection', ['product_ids' => $records->modelKeys()])
                    ->throw();
            } catch (Throwable $e) {
                Notification::make()->title(__('Could not add to the selection'))->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title(__(':count product(s) added to My Product Selection', ['count' => $records->count()]))
                ->success()->send();
        });

        $this->deselectRecordsAfterCompletion();
    }
}
