<?php

namespace App\Support;

use Illuminate\Support\Env;
use InvalidArgumentException;
use JsonException;

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
    public static function env(string $name, ?string $legacy, mixed $default = null): mixed
    {
        $primary = env($name, null);
        if ($primary !== null) {
            return $primary;
        }

        return $legacy === null ? $default : env($legacy, $default);
    }

    /**
     * Resolve a policy object without converting malformed JSON into defaults.
     * Read the raw repository value so Laravel's special null/false/empty env
     * conversions cannot hide an explicitly supplied invalid JSON value.
     *
     * @return array<array-key, mixed>
     */
    public static function jsonObject(string $name, ?string $legacy): array
    {
        $repository = Env::getRepository();
        $raw = $repository->get($name);
        if ($raw === null && $legacy !== null) {
            $raw = $repository->get($legacy);
            if ($raw !== null) {
                $name = $legacy;
            }
        }
        if ($raw === null) {
            return [];
        }

        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException($name.' must contain a valid JSON object.');
        }

        if (! is_array($value) || ! str_starts_with(ltrim($raw), '{')) {
            throw new InvalidArgumentException($name.' must contain a valid JSON object.');
        }

        return $value;
    }
}
