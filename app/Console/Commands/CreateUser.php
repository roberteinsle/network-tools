<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\MagicLink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:user {email} {--name=}')]
#[Description('Legt einen Benutzer an (falls nötig) und gibt einen einmaligen Anmeldelink aus.')]
class CreateUser extends Command
{
    public function handle(MagicLink $magic): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:255'])->fails()) {
            $this->error('Ungültige E-Mail-Adresse.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $this->option('name') ?: strstr($email, '@', true)]);
        $this->info($user->wasRecentlyCreated ? "Benutzer {$email} angelegt." : "Benutzer {$email} existiert bereits.");
        $this->line('Anmeldelink ('.MagicLink::MINUTES.' Minuten gültig, einmalig):');
        $this->line($magic->createUrl($user));

        return self::SUCCESS;
    }
}
