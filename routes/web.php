<?php

use App\Http\Controllers\Auth\MagicLoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['guest', 'signed', 'throttle:magic-link'])->group(function () {
    Route::get('/login/magic/{token}', [MagicLoginController::class, 'show'])->name('magic-login.show');
    Route::post('/login/magic/{token}', [MagicLoginController::class, 'store'])->name('magic-login.store');
});

// Frühere Adresse des DNS-Lookups (jetzt Werkzeug „IP“)
Route::get('/admin/dns-lookup', fn () => redirect('/admin/tools/ip'.(request()->filled('host') ? '?q='.urlencode((string) request()->query('host')) : '')));
