<?php

namespace App\Services;

use App\Checks\AddressCheck;
use App\Checks\BimiCheck;
use App\Checks\BlacklistCheck;
use App\Checks\CaaCheck;
use App\Checks\Check;
use App\Checks\DaneCheck;
use App\Checks\DkimCheck;
use App\Checks\DmarcCheck;
use App\Checks\DnssecCheck;
use App\Checks\DomainExpiryCheck;
use App\Checks\HttpCheck;
use App\Checks\MtaStsCheck;
use App\Checks\MxCheck;
use App\Checks\NsCheck;
use App\Checks\PtrCheck;
use App\Checks\SpfCheck;
use App\Checks\TlsMxCheck;
use App\Checks\TlsRptCheck;
use App\Checks\TlsWebCheck;

class CheckRegistry
{
    /** Reihenfolge = Anzeige-Reihenfolge. */
    public const CHECKS = [
        MxCheck::class,
        AddressCheck::class,
        PtrCheck::class,
        SpfCheck::class,
        DkimCheck::class,
        DmarcCheck::class,
        MtaStsCheck::class,
        TlsRptCheck::class,
        BimiCheck::class,
        DnssecCheck::class,
        NsCheck::class,
        CaaCheck::class,
        TlsWebCheck::class,
        HttpCheck::class,
        TlsMxCheck::class,
        DaneCheck::class,
        DomainExpiryCheck::class,
        BlacklistCheck::class,
    ];

    /** @return list<Check> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::CHECKS);
    }
}
