<?php

namespace App\Http\Controllers;

use App\Services\MercadoLivreAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MercadoLivreAuthController extends Controller
{
    public function __construct(protected MercadoLivreAuthService $authService) {}

    public function authorizeApp(): RedirectResponse
    {
        return redirect($this->authService->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->input('code');

        if (empty($code)) {
            return redirect('/admin')->with('error', 'Mercado Livre authorization code is missing.');
        }

        try {
            $this->authService->exchangeCode($code);
        } catch (RuntimeException $e) {
            Log::error('Mercado Livre authorization callback failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect('/admin')->with('error', 'Mercado Livre authorization failed: '.$e->getMessage());
        }

        return redirect('/admin')->with('success', 'Mercado Livre authorized successfully.');
    }
}
