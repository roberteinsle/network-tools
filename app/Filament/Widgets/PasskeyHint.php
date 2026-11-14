<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/** Nach der Anmeldung per Magic Link: Passkey anbieten, solange keiner existiert. */
class PasskeyHint extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.passkey-hint';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->passkeys()->exists();
    }
}
