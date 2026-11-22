<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Release-Nummer, Commit und Commit-Datum des laufenden Builds.
 * Im Image aus den Build-Argumenten (APP_VERSION, APP_COMMIT, APP_COMMIT_DATE),
 * in der Entwicklung aus dem lokalen Git-Repository.
 */
class BuildInfo
{
    private static ?array $cached = null;

    /** @return array{version:?string, commit:?string, date:?CarbonImmutable} */
    public static function get(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $version = config('app.version') ?: null;
        $commit = config('app.commit') ?: null;
        $date = config('app.commit_date') ?: null;

        if ($commit === null && is_dir(base_path('.git'))) {
            $version ??= self::git('describe --tags --abbrev=0');
            $commit = self::git('rev-parse --short=7 HEAD');
            $date = self::git('log -1 --format=%cI');
        }

        try {
            $parsedDate = $date ? CarbonImmutable::parse($date)->timezone(config('app.timezone')) : null;
        } catch (Throwable) {
            $parsedDate = null;
        }

        return self::$cached = ['version' => $version, 'commit' => $commit, 'date' => $parsedDate];
    }

    public static function flush(): void
    {
        self::$cached = null;
    }

    private static function git(string $args): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }
        $out = [];
        $code = 1;
        @exec('git -C '.escapeshellarg(base_path()).' '.$args.' 2>/dev/null', $out, $code);

        return $code === 0 && isset($out[0]) && $out[0] !== '' ? trim($out[0]) : null;
    }
}
