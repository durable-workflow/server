<?php

namespace Tests\Feature;

use App\Support\WorkflowCommandContextFactory;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowObservabilityFingerprintTest extends TestCase
{
    public static function admittedCommands(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'start', 'signal_with_start', 'signal', 'update', 'cancel',
        ]);
    }

    #[DataProvider('admittedCommands')]
    public function test_optional_caller_carrier_does_not_change_business_request_identity(string $command): void
    {
        $business = ['input' => ['observability_context' => ['customer' => 'retained']], 'request_id' => 'original'];
        $original = $this->fingerprint($business, $command);

        foreach ([null, 'invalid telemetry', ['schema' => 'unknown', 'carrier' => ['baggage' => 'private=value']]] as $carrier) {
            $this->assertSame($original, $this->fingerprint($business + ['observability_context' => $carrier], $command));
        }
        $changed = $business;
        $changed['input']['observability_context']['customer'] = 'different business value';
        $this->assertNotSame($original, $this->fingerprint($changed, $command));
        $this->assertNotSame($original, $this->fingerprint([...$business, 'request_id' => 'another request'], $command));
    }

    public function test_exclusion_does_not_expand_to_other_fields_or_unadopted_operations(): void
    {
        $business = ['input' => ['observability_context' => 'business value']];
        $original = $this->fingerprint($business, 'start');
        foreach (['dispatch_observability_context', 'observability_links'] as $field) {
            $this->assertNotSame($original, $this->fingerprint($business + [$field => 'different'], 'start'));
        }
        $this->assertNotSame($this->fingerprint($business, 'query'),
            $this->fingerprint($business + ['observability_context' => 'not an adopted field'], 'query'));
    }

    private function fingerprint(array $body, string $command): string
    {
        $request = Request::create('/api/workflows/real-workflow/'.$command, 'POST', $body);
        $request->headers->set('X-Request-Id', 'original-header');
        $request->attributes->set('namespace', 'default');

        return app(WorkflowCommandContextFactory::class)->make($request, 'real-workflow', $command)
            ->attributes()['context']['request']['fingerprint'];
    }
}
