<?php

use App\Http\Controllers\Auth\MagicLoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['guest', 'signed', 'throttle:magic-link'])->group(function () {
    Route::get('/login/magic/{token}', [MagicLoginController::class, 'show'])->name('magic-login.show');
    Route::post('/login/magic/{token}', [MagicLoginController::class, 'store'])->name('magic-login.store');
});
