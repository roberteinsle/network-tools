<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Blacklists\Pages\ManageBlacklists;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Jobs\CheckDomainJob;
use App\Models\AppSetting;
use App\Models\CheckResult;
use App\Models\CheckRun;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private CheckRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));

        $customer = Customer::create(['name' => 'Kunde A', 'dkim_providers' => ['m365']]);
        $this->domain = Domain::create(['name' => 'example.com', 'customer_id' => $customer->id]);
        $this->run = CheckRun::create([
            'domain_id' => $this->domain->id, 'status' => CheckStatus::Warning, 'started_at' => now(), 'finished_at' => now(),
            'duration_ms' => 1200, 'changes' => [['check' => 'spf', 'kind' => 'worse', 'from' => 'ok', 'to' => 'warning', 'from_summary' => 'gut', 'to_summary' => 'schlecht']],
            'changes_count' => 1,
        ]);
        CheckResult::create([
            'check_run_id' => $this->run->id, 'domain_id' => $this->domain->id, 'check' => 'spf', 'status' => 'warning',
            'summary' => 'Fast am DNS-Lookup-Limit', 'fingerprint' => 'x', 'state' => [],
            'details' => ['messages' => [['status' => 'warning', 'text' => 'Fast am Limit']], 'data' => ['Record' => 'v=spf1 -all']],
        ]);
        $this->domain->update(['last_status' => CheckStatus::Warning, 'last_checked_at' => now()]);
    }

    #[Test]
    public function all_pages_render(): void
    {
        foreach ([
            '/admin',
            '/admin/domains',
            '/admin/domains/create',
            "/admin/domains/{$this->domain->id}",
            "/admin/domains/{$this->domain->id}/edit",
            '/admin/customers',
            '/admin/customers/create',
            "/admin/customers/{$this->domain->customer_id}/edit",
            '/admin/check-runs',
            "/admin/check-runs/{$this->run->id}",
            '/admin/blacklists',
            '/admin/users',
            '/admin/settings',
            '/admin/passkeys',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    #[Test]
    public function domain_view_shows_results(): void
    {
        $this->get("/admin/domains/{$this->domain->id}")
            ->assertSee('Fast am DNS-Lookup-Limit')
            ->assertSee('v=spf1 -all');
    }

    #[Test]
    public function creating_a_domain_normalizes_name_and_starts_check(): void
    {
        Queue::fake();

        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'Beispiel.DE', 'check_interval_minutes' => 360])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('domains', ['name' => 'beispiel.de']);
        Queue::assertPushed(CheckDomainJob::class);
    }

    #[Test]
    public function invalid_domain_names_are_rejected(): void
    {
        Livewire::test(CreateDomain::class)
            ->fillForm(['name' => 'https://example.org/pfad', 'check_interval_minutes' => 360])
            ->call('create')
            ->assertHasFormErrors(['name' => 'regex']);
    }

    #[Test]
    public function settings_store_smtp_password_encrypted_and_keep_it_when_blank(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_password' => 'geheim', 'mail_from_address' => 'monitor@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $raw = \DB::table('app_settings')->value('smtp_password');
        $this->assertNotSame('geheim', $raw);
        AppSetting::flush();
        $this->assertSame('geheim', AppSetting::current()->smtp_password);

        Livewire::test(Settings::class)
            ->fillForm(['smtp_password' => ''])
            ->call('save');
        AppSetting::flush();
        $this->assertSame('geheim', AppSetting::current()->smtp_password);
    }

    #[Test]
    public function spamhaus_public_zones_cannot_be_added_as_blacklist(): void
    {
        Livewire::test(ManageBlacklists::class)
            ->callAction('create', ['name' => 'Spamhaus', 'zone' => 'zen.spamhaus.org', 'type' => 'ip', 'enabled' => true])
            ->assertHasActionErrors(['zone']);
    }
}
