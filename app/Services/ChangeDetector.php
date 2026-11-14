<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Models\CheckResult;
use App\Models\CheckRun;

/**
 * Vergleicht zwei Prüfläufe pro Check.
 *  - worse / recovered / status: Statuswechsel
 *  - changed: gleicher Status, aber andere Records (Fingerprint)
 * „Nicht prüfbar“ (Timeouts) erzeugt keine Änderung – sonst gäbe es Fehlalarme bei Netzproblemen.
 */
class ChangeDetector
{
    private const IP_CHECKS = [CheckType::Address, CheckType::Ptr];

    /** @return list<array{check:string, kind:string, from:?string, to:string, from_summary:?string, to_summary:string}> */
    public static function compare(?CheckRun $previous, CheckRun $current): array
    {
        if ($previous === null) {
            return [];
        }

        $before = $previous->results->keyBy(fn (CheckResult $r) => $r->check->value);
        $changes = [];

        foreach ($current->results as $result) {
            /** @var CheckResult|null $old */
            $old = $before[$result->check->value] ?? null;

            if ($result->status === CheckStatus::Unknown || $old?->status === CheckStatus::Unknown) {
                continue;
            }

            if ($old === null) {
                if ($result->status->isProblem()) {
                    $changes[] = self::change($result, null, 'worse');
                }

                continue;
            }

            if ($old->status !== $result->status) {
                $kind = match (true) {
                    $result->status->severity() > $old->status->severity() => 'worse',
                    $old->status->isProblem() && ! $result->status->isProblem() => 'recovered',
                    default => 'status',
                };
                $changes[] = self::change($result, $old, $kind);
            } elseif ($old->fingerprint !== $result->fingerprint) {
                $change = self::change($result, $old, 'changed');
                // IP-Wechsel (CDN, M365) nur protokollieren, außer an der Domain anders gewünscht
                $change['quiet'] = in_array($result->check, self::IP_CHECKS, true) && ! $current->domain?->notify_ip_changes;
                $changes[] = $change;
            }
        }

        return $changes;
    }

    private static function change(CheckResult $new, ?CheckResult $old, string $kind): array
    {
        return [
            'check' => $new->check->value,
            'kind' => $kind,
            'from' => $old?->status->value,
            'to' => $new->status->value,
            'from_summary' => $old?->summary,
            'to_summary' => $new->summary,
            'quiet' => false,
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'worse' => 'Verschlechtert',
            'recovered' => 'Behoben',
            'status' => 'Status geändert',
            'changed' => 'Konfiguration geändert',
            default => $kind,
        };
    }
}
