<?php

use App\Http\Controllers\ManifestController;
use App\Http\Controllers\MercadoLivreAuthController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('manifest.json', ManifestController::class);

Route::get('/', function () {
    return redirect(route('filament.admin.pages.home-dashboard', [], false));
});

Route::prefix('admin/products')->name('filament.admin.resources.products.')
    ->group(function () {
        Route::post('/{product}/fetch', [ProductController::class, 'fetch'])
            ->name('fetch');
    });

Route::get('mercado-livre/auth', [MercadoLivreAuthController::class, 'authorizeApp'])
    ->name('mercado-livre.auth');

Route::get('mercado-livre/callback', [MercadoLivreAuthController::class, 'callback'])
    ->name('mercado-livre.callback');
