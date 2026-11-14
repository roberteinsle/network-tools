<?php

namespace App\Checks;

/** Parser für Tag-Listen wie „v=DMARC1; p=reject; rua=mailto:…“. */
final class Tags
{
    /** @return array<string, string> Schlüssel in Kleinbuchstaben */
    public static function parse(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $tags[strtolower(trim($k))] = trim($v);
        }

        return $tags;
    }
}
