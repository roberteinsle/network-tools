<?php

namespace App\Support\Tls;

use Carbon\CarbonImmutable;

final class CertificateInfo
{
    /** @param list<string> $names */
    public function __construct(
        public readonly string $subject,
        public readonly string $issuer,
        public readonly array $names,
        public readonly CarbonImmutable $validFrom,
        public readonly CarbonImmutable $validTo,
        public readonly string $fingerprint,
        public readonly bool $trusted,
        public readonly ?string $verifyError = null,
        public readonly ?string $protocol = null,
    ) {}

    public function daysLeft(): int
    {
        return (int) floor(now()->diffInDays($this->validTo, false));
    }

    public function coversHost(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->names as $name) {
            $name = strtolower($name);
            if ($name === $host) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                $label = substr($host, 0, -strlen($suffix));
                if (str_ends_with($host, $suffix) && $label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
