<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Mail\MagicLinkMail;
use App\Models\MagicLoginToken;
use App\Models\User;
use App\Services\Auth\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_page_renders_with_passkey_and_magic_link(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Mit Passkey anmelden')
            ->assertSee('Anmeldelink per E-Mail senden')
            ->assertDontSee('Passwort');
    }

    #[Test]
    public function requesting_a_link_sends_mail_only_for_existing_users_with_same_response(): void
    {
        Mail::fake();
        User::create(['name' => 'Robert', 'email' => 'robert@example.com']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'robert@example.com'])
            ->call('authenticate')
            ->assertNotified('Prüfe dein Postfach');
        Mail::assertSent(MagicLinkMail::class, 1);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'unknown@example.com'])
            ->call('authenticate')
            ->assertNotified('Prüfe dein Postfach');
        Mail::assertSent(MagicLinkMail::class, 1);
    }

    #[Test]
    public function magic_link_logs_in_exactly_once(): void
    {
        $user = User::create(['name' => 'Robert', 'email' => 'robert@example.com']);
        $url = app(MagicLink::class)->createUrl($user);

        // GET verbraucht das Token nicht (Link-Scanner)
        $this->get($url)->assertOk()->assertSee('Jetzt anmelden');
        $this->assertSame(1, MagicLoginToken::count());

        $this->post($url)->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, MagicLoginToken::count());

        auth()->logout();
        $this->post($url)->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    #[Test]
    public function magic_link_expires_after_15_minutes(): void
    {
        $user = User::create(['name' => 'Robert', 'email' => 'robert@example.com']);
        $url = app(MagicLink::class)->createUrl($user);

        $this->travel(16)->minutes();

        $this->post($url)->assertForbidden(); // Signatur abgelaufen
        $this->assertGuest();
    }

    #[Test]
    public function tampered_signature_is_rejected(): void
    {
        $user = User::create(['name' => 'Robert', 'email' => 'robert@example.com']);
        $url = app(MagicLink::class)->createUrl($user);

        $this->post(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url))->assertForbidden();
        $this->assertGuest();
    }

    public static function injectionPayloads(): array
    {
        return [["' OR '1'='1"], ["admin'--"], ['robert@example.com\' OR 1=1 --']];
    }

    #[Test]
    #[DataProvider('injectionPayloads')]
    public function sql_injection_payloads_are_rejected_without_db_error(string $payload): void
    {
        Mail::fake();
        User::create(['name' => 'Robert', 'email' => 'robert@example.com']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $payload])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        Mail::assertNothingSent();
        $this->assertGuest();
    }

    #[Test]
    public function panel_requires_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function create_user_command_prints_login_link(): void
    {
        $this->artisan('app:user', ['email' => 'neu@example.com'])
            ->expectsOutputToContain('/login/magic/')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'neu@example.com']);
    }
}
