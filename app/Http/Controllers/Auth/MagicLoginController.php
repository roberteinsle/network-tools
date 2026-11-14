<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\MagicLink;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * GET zeigt nur eine Bestätigungsseite; erst der POST löst das Token ein.
 * So verbrauchen Link-Scanner von Mailprogrammen (z. B. Safe Links) das Token nicht.
 */
class MagicLoginController
{
    public function show(Request $request, string $token, MagicLink $magic): View
    {
        return view('auth.magic-login', [
            'valid' => $magic->isValid($token),
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, string $token, MagicLink $magic): RedirectResponse
    {
        $user = $magic->consume($token);
        if (! $user) {
            return redirect()->to(Filament::getLoginUrl())->with('magic_error', true);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put('suggest_passkey', true);

        return redirect()->intended(Filament::getUrl());
    }
}
