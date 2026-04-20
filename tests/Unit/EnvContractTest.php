<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Enforces the DW_* operator-facing contract described in
 * config/dw-contract.php. These assertions catch drift between the
 * contract, config/server.php, docker-compose.yml, k8s/secret.yaml, and
 * .env.example.
 */
class EnvContractTest extends TestCase
{
    private static string $repoRoot;

    /** @var array<string, mixed> */
    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot = dirname(__DIR__, 2);
        self::$contract = require self::$repoRoot.'/config/dw-contract.php';
    }

    public function test_every_dw_var_in_config_server_php_appears_in_the_contract(): void
    {
        $source = file_get_contents(self::$repoRoot.'/config/server.php');
        $this->assertNotFalse($source, 'config/server.php must be readable');

        preg_match_all(
            "/EnvAuditor::env\\(\\s*'(DW_[A-Z0-9_]+)'/",
            $source,
            $matches,
        );

        $dwNames = array_unique($matches[1] ?? []);
        sort($dwNames);

        $this->assertNotEmpty(
            $dwNames,
            'config/server.php does not call EnvAuditor::env with any DW_* names',
        );

        foreach ($dwNames as $name) {
            $this->assertArrayHasKey(
                $name,
                self::$contract['vars'],
                "Variable {$name} is read by config/server.php but missing from config/dw-contract.php",
            );
        }
    }

    public function test_every_legacy_alias_in_config_server_php_matches_the_contract(): void
    {
        $source = file_get_contents(self::$repoRoot.'/config/server.php');
        $this->assertNotFalse($source);

        preg_match_all(
            "/EnvAuditor::env\\(\\s*'(DW_[A-Z0-9_]+)'\\s*,\\s*'([A-Z0-9_]+)'/",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'config/server.php must use EnvAuditor::env');

        foreach ($matches as [, $dw, $legacy]) {
            $this->assertArrayHasKey($dw, self::$contract['vars'], "{$dw} missing from contract");
            $contractLegacy = self::$contract['vars'][$dw]['legacy'] ?? null;
            $this->assertSame(
                $legacy,
                $contractLegacy,
                "Legacy alias for {$dw}: config/server.php uses {$legacy}, contract declares "
                .var_export($contractLegacy, true),
            );
        }
    }

    public function test_docker_compose_uses_only_dw_or_framework_names(): void
    {
        $source = file_get_contents(self::$repoRoot.'/docker-compose.yml');
        $this->assertNotFalse($source);

        // Only audit container runtime env vars (under an `environment:`
        // block). Build args under `build.args:` are a separate surface
        // and intentionally keep the `WORKFLOW_PACKAGE_*` names because
        // they reference the bundled workflow package, not the server's
        // runtime configuration contract.
        $envBlocks = self::yamlBlocks($source, 'environment:');

        $violations = [];

        foreach ($envBlocks as $block) {
            preg_match_all('/^\s{6,}([A-Z][A-Z0-9_]*):\s/m', $block, $matches);

            foreach (array_unique($matches[1] ?? []) as $name) {
                if (str_starts_with($name, 'DW_')) {
                    $this->assertArrayHasKey(
                        $name,
                        self::$contract['vars'],
                        "docker-compose.yml references {$name} which is not in the contract",
                    );

                    continue;
                }

                if (str_starts_with($name, 'WORKFLOW_') || str_starts_with($name, 'ACTIVITY_')) {
                    $violations[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'docker-compose.yml still defines legacy runtime env keys; rename to DW_*: '
            .implode(', ', array_unique($violations)),
        );
    }

    /**
     * Extract the text under each `$header:` block using leading-space
     * indentation as the block boundary. Good enough for docker-compose
     * where every `environment:` key lives under a service with a
     * predictable indent.
     *
     * @return list<string>
     */
    private static function yamlBlocks(string $source, string $header): array
    {
        $lines = preg_split("/\r?\n/", $source) ?: [];
        $blocks = [];
        $current = null;
        $headerIndent = null;

        foreach ($lines as $line) {
            if ($current !== null) {
                if (trim($line) === '') {
                    $current .= "\n";

                    continue;
                }

                $indent = strlen($line) - strlen(ltrim($line));
                if ($indent <= $headerIndent) {
                    $blocks[] = $current;
                    $current = null;
                    $headerIndent = null;
                } else {
                    $current .= $line."\n";

                    continue;
                }
            }

            if (preg_match("/^(\s*){$header}\s*$/", $line, $m)) {
                $headerIndent = strlen($m[1]);
                $current = '';
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    public function test_k8s_secret_uses_only_dw_or_framework_names(): void
    {
        $source = file_get_contents(self::$repoRoot.'/k8s/secret.yaml');
        $this->assertNotFalse($source);

        preg_match_all('/^\s{2,}([A-Z][A-Z0-9_]*):\s/m', $source, $matches);

        $seen = array_unique($matches[1] ?? []);
        $violations = [];

        foreach ($seen as $name) {
            if (str_starts_with($name, 'DW_')) {
                $this->assertArrayHasKey(
                    $name,
                    self::$contract['vars'],
                    "k8s/secret.yaml references {$name} which is not in the contract",
                );

                continue;
            }

            if (str_starts_with($name, 'WORKFLOW_') || str_starts_with($name, 'ACTIVITY_')) {
                $violations[] = $name;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'k8s/secret.yaml still defines legacy env keys; rename to DW_*: '
            .implode(', ', $violations),
        );
    }

    public function test_env_example_uses_only_dw_or_framework_names(): void
    {
        $source = file_get_contents(self::$repoRoot.'/.env.example');
        $this->assertNotFalse($source);

        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $source, $matches);

        $seen = array_unique($matches[1] ?? []);
        $violations = [];

        foreach ($seen as $name) {
            if (str_starts_with($name, 'DW_')) {
                $this->assertArrayHasKey(
                    $name,
                    self::$contract['vars'],
                    ".env.example references {$name} which is not in the contract",
                );

                continue;
            }

            if (str_starts_with($name, 'WORKFLOW_') || str_starts_with($name, 'ACTIVITY_')) {
                $violations[] = $name;
            }
        }

        $this->assertSame(
            [],
            $violations,
            '.env.example still lists legacy env keys; rename to DW_*: '
            .implode(', ', $violations),
        );
    }

    public function test_every_contract_entry_has_required_shape(): void
    {
        $this->assertArrayHasKey('prefix', self::$contract);
        $this->assertArrayHasKey('vars', self::$contract);
        $this->assertArrayHasKey('framework', self::$contract);

        foreach (self::$contract['vars'] as $name => $meta) {
            $this->assertIsString($name);
            $this->assertStringStartsWith('DW_', $name);
            $this->assertIsArray($meta);
            $this->assertArrayHasKey('description', $meta, "{$name} must document a description");
            $this->assertArrayHasKey('since', $meta, "{$name} must declare the introducing version");

            if (array_key_exists('legacy', $meta) && $meta['legacy'] !== null) {
                $this->assertIsString($meta['legacy']);
                $this->assertNotSame('', $meta['legacy']);
                $this->assertDoesNotMatchRegularExpression(
                    '/^DW_/',
                    $meta['legacy'],
                    "{$name}'s legacy alias must not itself be a DW_* name",
                );
            }
        }
    }
}
