<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class BuildIdIntentOpenApiContractTest extends TestCase
{
    // Server #521: the build-id intent enum lists every action the Server
    // routes, including promote.
    public function test_build_id_intents_match_the_served_routes(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = Yaml::parseFile($root.'/resources/platform-protocol-specs/control-plane-api.openapi.yaml');
        $parameters = $spec['paths']['/task-queues/{taskQueue}/build-ids/{intent}']['post']['parameters'];
        $intent = array_values(array_filter($parameters, static fn ($parameter): bool => ($parameter['name'] ?? null) === 'intent'))[0];

        preg_match_all('#build-ids/(\w+)\'#', (string) file_get_contents($root.'/routes/api.php'), $php);
        preg_match_all('#build-ids/(\w+)"#', (string) file_get_contents($root.'/rust/src/runtime/http.rs'), $native);

        $served = array_values(array_unique($php[1]));
        sort($served);
        $nativeServed = array_values(array_unique($native[1]));
        sort($nativeServed);
        $enum = $intent['schema']['enum'];
        sort($enum);

        $this->assertSame(['drain', 'promote', 'resume'], $served);
        $this->assertSame($served, $nativeServed);
        $this->assertSame($served, $enum);
    }
}
