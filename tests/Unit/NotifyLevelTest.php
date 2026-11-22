<?php

namespace Tests\Unit;

use App\Enums\CheckStatus;
use App\Enums\NotifyLevel;
use App\Models\CheckResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotifyLevelTest extends TestCase
{
    private function change(?string $from, string $to, string $kind = 'worse'): array
    {
        return ['check' => 'spf', 'kind' => $kind, 'from' => $from, 'to' => $to];
    }

    #[Test]
    public function thresholds_filter_changes(): void
    {
        $results = collect();
        $toWarning = [$this->change('ok', 'warning')];
        $toError = [$this->change('warning', 'error')];
        $toInfo = [$this->change('ok', 'info')];
        $recordOnly = [$this->change('ok', 'ok', 'changed')];
        $errorFixed = [$this->change('error', 'ok', 'recovered')];

        $this->assertTrue(NotifyLevel::All->wants($recordOnly, $results, false));
        $this->assertFalse(NotifyLevel::None->wants($toError, $results, false));

        $this->assertTrue(NotifyLevel::Error->wants($toError, $results, false));
        $this->assertTrue(NotifyLevel::Error->wants($errorFixed, $results, false));
        $this->assertFalse(NotifyLevel::Error->wants($toWarning, $results, false));

        $this->assertTrue(NotifyLevel::Warning->wants($toWarning, $results, false));
        $this->assertFalse(NotifyLevel::Warning->wants($toInfo, $results, false));
        $this->assertFalse(NotifyLevel::Warning->wants($recordOnly, $results, false));

        $this->assertTrue(NotifyLevel::Info->wants($toInfo, $results, false));
        $this->assertFalse(NotifyLevel::Info->wants($recordOnly, $results, false));
    }

    #[Test]
    public function initial_run_uses_current_results_and_ignores_unknown(): void
    {
        $results = collect([
            new CheckResult(['check' => 'spf', 'status' => CheckStatus::Warning]),
            new CheckResult(['check' => 'dkim', 'status' => CheckStatus::Unknown]),
        ]);

        $this->assertTrue(NotifyLevel::Warning->wants([], $results, true));
        $this->assertFalse(NotifyLevel::Error->wants([], $results, true));
    }
}
