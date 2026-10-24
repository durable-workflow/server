<?php

namespace Tests\Feature;

use App\Support\WorkflowCommandContextFactory;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommandRequestContextTest extends TestCase
{
    public static function callerKeys(): iterable
    {
        foreach (['signal', 'update'] as $command) {
            yield $command.' body key' => [$command, 'caller-key', 'proxy-correlation', 'caller-key'];
            yield $command.' zero key' => [$command, '0', 'proxy-correlation', '0'];
            yield $command.' omitted key' => [$command, null, 'proxy-correlation', null];
            yield $command.' without header' => [$command, 'caller-key', null, 'caller-key'];
        }
    }

    #[DataProvider('callerKeys')]
    public function test_proxy_correlation_never_becomes_a_signal_or_update_command_key(
        string $command, ?string $key, ?string $header, ?string $expected,
    ): void {
        $request = Request::create('/api/workflows/original/'.$command.'/change', 'POST', [
            'request_id' => $key,
            'input' => [17, null, 'Київ'],
        ]);
        if ($header !== null) {
            $request->headers->set('X-Request-Id', $header);
        }
        $context = app(WorkflowCommandContextFactory::class)->make(
            $request, 'original', $command, ['request_id' => $key],
        )->attributes()['context'];

        $this->assertSame($expected, $context['request']['request_id'] ?? null);
        $this->assertSame($header, $context['request']['headers']['x_request_id'] ?? null);
        $this->assertSame($key, $context['server']['metadata']['request_id'] ?? null);
    }
}
