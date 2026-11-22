<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\NotifyLevel;
use App\Models\AppSetting;
use App\Models\CheckResult;
use App\Models\CheckRun;
use App\Models\Customer;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function domainWith(NotifyLevel $level): Domain
    {
        AppSetting::current()->update(['notification_emails' => ['ops@example.com']]);
        $customer = Customer::create(['name' => 'Customer', 'notification_emails' => ['customer@example.com'], 'notify_level' => $level]);

        return Domain::create(['name' => 'example.com', 'customer_id' => $customer->id, 'notification_emails' => ['domain@example.com']]);
    }

    private function makeRun(Domain $domain, CheckStatus $status): CheckRun
    {
        $run = CheckRun::create(['domain_id' => $domain->id, 'status' => $status, 'started_at' => now(), 'finished_at' => now()]);
        CheckResult::create(['check_run_id' => $run->id, 'domain_id' => $domain->id, 'check' => 'spf', 'status' => $status, 'summary' => 'x', 'fingerprint' => 'x']);

        return $run->load('results');
    }

    #[Test]
    public function customer_addresses_follow_the_notify_level(): void
    {
        $domain = $this->domainWith(NotifyLevel::Error);
        $warning = [['check' => 'spf', 'kind' => 'worse', 'from' => 'ok', 'to' => 'warning']];
        $error = [['check' => 'spf', 'kind' => 'worse', 'from' => 'warning', 'to' => 'error']];

        $this->assertSame(['ops@example.com', 'domain@example.com'], $domain->recipientsFor($warning, $this->makeRun($domain, CheckStatus::Warning), false));
        $this->assertSame(['ops@example.com', 'customer@example.com', 'domain@example.com'], $domain->recipientsFor($error, $this->makeRun($domain, CheckStatus::Error), false));
    }

    #[Test]
    public function none_keeps_customer_addresses_out(): void
    {
        $domain = $this->domainWith(NotifyLevel::None);
        $error = [['check' => 'spf', 'kind' => 'worse', 'from' => 'ok', 'to' => 'error']];

        $this->assertNotContains('customer@example.com', $domain->recipientsFor($error, $this->makeRun($domain, CheckStatus::Error), false));
    }

    #[Test]
    public function existing_customers_default_to_all_changes(): void
    {
        $customer = Customer::create(['name' => 'Old']);

        $this->assertSame(NotifyLevel::All, $customer->fresh()->notify_level);
    }
}
