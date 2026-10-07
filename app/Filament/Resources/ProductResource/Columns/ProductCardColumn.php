<?php

namespace App\Filament\Resources\ProductResource\Columns;

use Filament\Tables\Columns\Column;
use Illuminate\Contracts\View\View;

class ProductCardColumn extends Column
{
    protected string $view = 'filament.resources.product-resource.columns.product-card';

    public function render(): View
    {
        $this->viewData([
            'product' => $this->getRecord(),
        ]);

        return parent::render();
    }
}
