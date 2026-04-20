<?php

namespace App\Support;

/**
 * Scans the process environment against the DW_* contract from
 * config/dw-contract.php and produces a structured report.
 *
 * The audit is pure (no logging, no I/O side effects). Callers — the
 * `env:audit` artisan command, `EnvContractTest`, the docker entrypoint
 * — are responsible for presenting the findings.
 */
class EnvAuditor
{
    /**
     * @param  array<string, string>  $env  Environment snapshot (name => value)
     * @param  array<string, mixed>  $contract  Contract array as returned by config('dw-contract')
     * @return array{
     *     prefix: string,
     *     known: list<string>,
     *     unknown: list<string>,
     *     legacy: list<array{legacy: string, replacement: string}>,
     *     framework: list<string>,
     * }
     */
    public static function audit(array $env, array $contract): array
    {
        $prefix = (string) ($contract['prefix'] ?? 'DW_');
        $vars = (array) ($contract['vars'] ?? []);
        $framework = array_flip((array) ($contract['framework'] ?? []));

        $legacyMap = [];
        foreach ($vars as $name => $meta) {
            $legacy = $meta['legacy'] ?? null;
            if (is_string($legacy) && $legacy !== '') {
                $legacyMap[$legacy] = $name;
            }
        }

        $known = [];
        $unknown = [];
        $legacyHits = [];
        $frameworkHits = [];

        foreach ($env as $name => $value) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (isset($vars[$name])) {
                $known[] = $name;

                continue;
            }

            if (isset($legacyMap[$name])) {
                $legacyHits[] = [
                    'legacy' => $name,
                    'replacement' => $legacyMap[$name],
                ];

                continue;
            }

            if (str_starts_with($name, $prefix)) {
                $unknown[] = $name;

                continue;
            }

            if (isset($framework[$name])) {
                $frameworkHits[] = $name;

                continue;
            }
        }

        sort($known);
        sort($unknown);
        sort($frameworkHits);
        usort($legacyHits, static fn (array $a, array $b): int => strcmp($a['legacy'], $b['legacy']));

        return [
            'prefix' => $prefix,
            'known' => $known,
            'unknown' => $unknown,
            'legacy' => $legacyHits,
            'framework' => $frameworkHits,
        ];
    }

    /**
     * Capture the current process environment. Merges $_ENV, $_SERVER, and
     * getenv() so PHP-FPM / CLI / Docker-set variables are all seen.
     *
     * @return array<string, string>
     */
    public static function currentEnvironment(): array
    {
        $sources = [];

        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $sources[$k] = (string) $v;
            }
        }

        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_scalar($v) && ! isset($sources[$k])) {
                $sources[$k] = (string) $v;
            }
        }

        $getenv = getenv();
        if (is_array($getenv)) {
            foreach ($getenv as $k => $v) {
                if (is_string($k) && ! isset($sources[$k])) {
                    $sources[$k] = (string) $v;
                }
            }
        }

        return $sources;
    }

    /**
     * Resolve a DW_* env var, falling back to its documented legacy
     * counterpart if the DW_* name is not set. Returns the default when
     * neither is present.
     *
     * Used by config/server.php so one rename happens in one place.
     */
    public static function env(string $name, string $legacy, mixed $default = null): mixed
    {
        $primary = env($name, null);
        if ($primary !== null) {
            return $primary;
        }

        return env($legacy, $default);
    }
}
