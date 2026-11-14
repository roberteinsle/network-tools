<?php

namespace Tests\Feature;

use App\Checks\Check;
use App\Checks\CheckContext;
use App\Checks\CheckOutcome;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Jobs\CheckDomainJob;
use App\Mail\DomainAlertMail;
use App\Models\AppSetting;
use App\Models\Domain;
use App\Services\CheckRegistry;
use App\Services\DomainChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DomainCheckerTest extends TestCase
{
    use RefreshDatabase;

    /** Steuerbarer Fake-Check: Status und Zustand werden pro Test gesetzt. */
    public static CheckStatus $status = CheckStatus::Ok;

    public static array $state = ['record' => 'a'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        AppSetting::current()->update(['notification_emails' => ['ops@example.com']]);

        $this->app->instance(CheckRegistry::class, new class extends CheckRegistry
        {
            public function all(): array
            {
                return [new class implements Check
                {
                    public function type(): CheckType
                    {
                        return CheckType::Spf;
                    }

                    public function run(CheckContext $ctx): CheckOutcome
                    {
                        return CheckOutcome::make()->add(DomainCheckerTest::$status, 'Befund '.DomainCheckerTest::$status->value)->state(DomainCheckerTest::$state);
                    }
                }];
            }
        });
    }

    #[Test]
    public function first_run_without_problems_sends_no_mail(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        $run = app(DomainChecker::class)->run($domain);

        $this->assertSame(CheckStatus::Ok, $run->status);
        $this->assertSame(0, $run->changes_count);
        Mail::assertNothingSent();
    }

    #[Test]
    public function status_change_and_config_change_trigger_mail(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        app(DomainChecker::class)->run($domain);

        self::$status = CheckStatus::Error;
        $run = app(DomainChecker::class)->run($domain->fresh());
        $this->assertSame('worse', $run->changes[0]['kind']);
        $this->assertNotNull($run->notified_at);
        Mail::assertSent(DomainAlertMail::class, fn ($m) => $m->hasTo('ops@example.com'));

        // gleicher Status, gleicher Zustand: keine Mail
        $run = app(DomainChecker::class)->run($domain->fresh());
        $this->assertSame(0, $run->changes_count);
        Mail::assertSent(DomainAlertMail::class, 1);

        // Record geändert bei gleichem Status: Mail
        self::$state = ['record' => 'b'];
        $run = app(DomainChecker::class)->run($domain->fresh());
        $this->assertSame('changed', $run->changes[0]['kind']);
        Mail::assertSent(DomainAlertMail::class, 2);
    }

    #[Test]
    public function unknown_status_does_not_alert(): void
    {
        self::$status = CheckStatus::Ok;
        self::$state = ['record' => 'a'];
        $domain = Domain::create(['name' => 'example.com']);
        app(DomainChecker::class)->run($domain);

        self::$status = CheckStatus::Unknown;
        $run = app(DomainChecker::class)->run($domain->fresh());

        $this->assertSame(0, $run->changes_count);
        Mail::assertNothingSent();
    }

    #[Test]
    public function due_command_dispatches_only_due_domains(): void
    {
        Queue::fake();
        Domain::create(['name' => 'due.example']);
        Domain::create(['name' => 'later.example'])->forceFill(['next_check_at' => now()->addHour()])->save();
        Domain::create(['name' => 'paused.example', 'active' => false]);

        $this->artisan('domains:check-due')->assertSuccessful();

        Queue::assertPushed(CheckDomainJob::class, 1);
        $this->assertNotNull(Domain::where('name', 'due.example')->first()->next_check_at);
    }

    protected function tearDown(): void
    {
        self::$status = CheckStatus::Ok;
        self::$state = ['record' => 'a'];
        parent::tearDown();
    }
}
