<?php

namespace App\Support\Dns;

use RuntimeException;

/** Technischer DNS-Fehler (Timeout, SERVFAIL, REFUSED) – nicht „Eintrag fehlt“. */
class DnsException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $rcode = null)
    {
        parent::__construct($message);
    }

    public function isServFail(): bool
    {
        return $this->rcode === 2;
    }

    public function isRefused(): bool
    {
        return $this->rcode === 5;
    }
}
