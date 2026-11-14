<?php

namespace App\Support\Rdap;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * RDAP-Abfrage über den IANA-Bootstrap. Einige Registries (z. B. DENIC für .de)
 * veröffentlichen kein Ablaufdatum – dann liefert lookup() expiration = null.
 */
class RdapClient
{
    private const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';

    public function serverFor(string $domain): ?string
    {
        $map = Cache::remember('rdap.bootstrap', now()->addDay(), function () {
            $json = Http::timeout(15)->get(self::BOOTSTRAP)->throw()->json();
            $map = [];
            foreach ($json['services'] ?? [] as [$tlds, $urls]) {
                $url = collect($urls)->first(fn ($u) => str_starts_with($u, 'https://')) ?? $urls[0] ?? null;
                foreach ($tlds as $tld) {
                    $map[strtolower($tld)] = $url;
                }
            }

            return $map;
        });

        // längstes passendes Suffix (z. B. „co.uk“ vor „uk“)
        $labels = explode('.', strtolower($domain));
        for ($i = 1; $i < count($labels); $i++) {
            $suffix = implode('.', array_slice($labels, $i));
            if (isset($map[$suffix])) {
                return rtrim($map[$suffix], '/').'/';
            }
        }

        return null;
    }

    /** @return array{expiration:?string, registration:?string, registrar:?string, status:list<string>, server:string}|null */
    public function lookup(string $domain): ?array
    {
        $server = $this->serverFor($domain);
        if ($server === null) {
            return null;
        }

        $response = Http::timeout(15)->accept('application/rdap+json')->get($server.'domain/'.$domain);
        if ($response->status() === 404) {
            throw new RuntimeException('Domain bei der Registry nicht gefunden (RDAP 404).');
        }
        $response->throw();
        $data = $response->json();

        $events = collect($data['events'] ?? [])->mapWithKeys(fn ($e) => [$e['eventAction'] ?? '' => $e['eventDate'] ?? null]);
        $registrar = collect($data['entities'] ?? [])
            ->first(fn ($e) => in_array('registrar', $e['roles'] ?? [], true));
        $registrarName = null;
        foreach ($registrar['vcardArray'][1] ?? [] as $field) {
            if (($field[0] ?? null) === 'fn') {
                $registrarName = $field[3] ?? null;
            }
        }

        return [
            'expiration' => $events['expiration'] ?? null,
            'registration' => $events['registration'] ?? null,
            'registrar' => $registrarName,
            'status' => array_values($data['status'] ?? []),
            'server' => $server,
        ];
    }
}
