<?php

namespace Tests\Feature;

use App\Checks\Check;
use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Enums\CheckType;
use App\Filament\Pages\DnsCheck;
use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Models\CheckRun;
use App\Models\Domain;
use App\Models\User;
use App\Services\CheckRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DnsCheckPageTest extends TestCase
{
    use RefreshDatabase;

    /** Von den Fake-Checks gesehene Domains: Name und ob gespeichert. */
    public static array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$seen = [];
        Mail::fake();
        $this->actingAs(User::create(['name' => 'Test User', 'email' => 'user@example.com']));

        $fake = fn (CheckType $type, string $text) => new class($type, $text) implements Check
        {
            public function __construct(private CheckType $type, private string $text) {}

            public function type(): CheckType
            {
                return $this->type;
            }

            public function run(CheckContext $ctx): CheckOutcome
            {
                DnsCheckPageTest::$seen[] = [$this->type->value, $ctx->name(), $ctx->domain->exists];

                return CheckOutcome::make()->warning($this->text)->data('Record', 'ns1.example.net');
            }
        };

        $this->app->instance(CheckRegistry::class, new class([$fake(CheckType::Ns, 'NS-Befund'), $fake(CheckType::Blacklist, 'RBL-Befund')]) extends CheckRegistry
        {
            public function __construct(private array $checks) {}

            public function all(): array
            {
                return $this->checks;
            }
        });
    }

    #[Test]
    public function page_is_in_the_menu_and_renders(): void
    {
        $this->get('/admin/dns-check')->assertOk()->assertSee('DNS-Check');
        $this->get('/admin')->assertSee('Werkzeuge');
    }

    #[Test]
    public function checks_an_unsaved_domain_with_the_selected_checks_only(): void
    {
        Livewire::test(DnsCheck::class)
            ->fillForm(['name' => 'Beispiel.DE', 'checks' => ['ns']])
            ->call('check')
            ->assertHasNoFormErrors()
            ->assertSee('Ergebnis für beispiel.de')
            ->assertSee('NS-Befund')
            ->assertDontSee('RBL-Befund');

        $this->assertSame([['ns', 'beispiel.de', false]], self::$seen);
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, CheckRun::count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function invalid_names_are_rejected(): void
    {
        Livewire::test(DnsCheck::class)
            ->fillForm(['name' => 'https://example.org/pfad'])
            ->call('check')
            ->assertHasFormErrors(['name' => 'regex']);

        $this->assertSame([], self::$seen);
    }

    #[Test]
    public function monitor_link_prefills_the_create_form(): void
    {
        Livewire::withQueryParams(['name' => 'beispiel.de'])
            ->test(CreateDomain::class)
            ->assertSchemaStateSet(['name' => 'beispiel.de']);
    }
}
