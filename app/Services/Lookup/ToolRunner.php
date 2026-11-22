<?php

namespace App\Services\Lookup;

use App\Checks\CheckOutcome;
use App\Enums\CheckType;
use App\Enums\DnsTool;
use App\Models\Domain;
use App\Services\DomainChecker;
use App\Support\Dns\DnsException;

/**
 * Führt ein Werkzeug aus der DnsTool-Registry aus. Mail-Sicherheit und SRV nutzen die registrierten
 * Checks über DomainChecker::evaluate() (nichts wird gespeichert, keine Mail), alle anderen
 * Werkzeuge die Lookup-Services. Ergebnis als Arrays (Livewire-tauglich):
 *  - ['type' => 'checks', 'results' => [...]]   Darstellung wie auf der Domain-Seite
 *  - ['type' => 'tables', 'sections' => [...]]  ToolTable-Arrays
 */
class ToolRunner
{
    public function __construct(
        private readonly DomainChecker $checker,
        private readonly RecordLookup $records,
        private readonly ReverseLookup $reverse,
        private readonly DnsTrace $trace,
        private readonly DnssecLookup $dnssec,
    ) {}

    /** @param  array{name:string, dkim_providers?:array, dkim_selectors?:array, check_mx_tls?:bool, record_type?:string, srv_services?:array}  $input */
    public function run(DnsTool $tool, array $input): array
    {
        $name = $input['name'];

        // SRV: ein vollständiger Name wie _sip._tls.example.com prüft genau diesen Dienst (als erwartet)
        if ($tool === DnsTool::Srv && preg_match('/^(_[^.]+\._[^.]+)\.(.+)$/', $name, $m)) {
            $name = $m[2];
            $input['srv_services'] = [...($input['srv_services'] ?? []), $m[1]];
        }

        if ($type = $tool->checkType()) {
            return ['type' => 'checks', 'results' => $this->check($tool, $type, $name, $input)];
        }

        try {
            $sections = match ($tool) {
                DnsTool::Mx => $this->records->mx($name),
                DnsTool::Ip => $this->records->ip($name),
                DnsTool::Nameserver => $this->records->ns($name),
                DnsTool::Soa => $this->records->soa($name),
                DnsTool::Cname => $this->records->cname($name),
                DnsTool::Txt => $this->records->txt($name),
                DnsTool::Caa => $this->records->caa($name),
                DnsTool::Ptr => $this->reverse->ptr($name),
                DnsTool::Trace => $this->trace->trace($name),
                DnsTool::Dnssec => $this->dnssec->rrsig($name, $input['record_type'] ?? 'A'),
                DnsTool::Ds => $this->dnssec->ds($name),
            };
        } catch (DnsException $e) {
            $sections = [ToolTable::make('Abfrage fehlgeschlagen')
                ->column('Fehler')
                ->row([$e->getMessage()])
                ->toArray()];
        }

        return ['type' => 'tables', 'sections' => $sections];
    }

    private function check(DnsTool $tool, CheckType $type, string $name, array $input): array
    {
        $domain = new Domain([
            'name' => $name,
            'inherit_dkim_providers' => false,
            'dkim_providers' => $input['dkim_providers'] ?? [],
            'dkim_selectors' => $input['dkim_selectors'] ?? [],
            'check_mx_tls' => (bool) ($input['check_mx_tls'] ?? false),
            'srv_services' => $input['srv_services'] ?? [],
        ]);

        $outcomes = $this->checker->evaluate($domain, [$type]);

        return array_map(fn (string $check, CheckOutcome $o) => [
            'check' => $check,
            'status' => $o->status()->value,
            'summary' => mb_strimwidth($o->getSummary(), 0, 500, '…'),
            'details' => $o->details(),
        ], array_keys($outcomes), $outcomes);
    }
}
