<?php

namespace Tests\Unit;

use App\Support\LongPollWaitPool;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class LongPollWaitPoolProcessTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The published Linux image provides the SIGKILL process control.');
        }

        $this->directory = sys_get_temp_dir().'/dw-poll-process-'.bin2hex(random_bytes(12));
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $process->stop(0.1, 9);
        }

        if (isset($this->directory)) {
            (new Filesystem)->deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_killed_process_releases_only_its_node_and_namespace_capacity(): void
    {
        $dead = $this->hold('node-a', 'tenant-a');
        $sameNodePeer = $this->hold('node-a', 'tenant-b');
        $otherNodePeer = $this->hold('node-b', 'tenant-a');
        $pool = new LongPollWaitPool;
        $this->assertNull($pool->tryAcquire($this->node('node-a'), 2, 'tenant-c', 1, 30));
        $this->assertNull($pool->tryAcquire($this->node('node-b'), 2, 'tenant-a', 1, 30));

        $started = microtime(true);
        $dead->signal(9);

        try {
            $dead->wait();
        } catch (ProcessSignaledException $exception) {
            $this->assertSame(9, $exception->getProcess()->getTermSignal());
        }

        $this->assertFalse($dead->isRunning());
        $replacement = $pool->tryAcquire($this->node('node-a'), 2, 'tenant-a', 1, 30);
        $this->assertNotNull($replacement);
        $this->assertLessThan(5.0, microtime(true) - $started, 'Recovery must not wait for the requested 30-second poll window.');
        $this->assertTrue($sameNodePeer->isRunning());
        $this->assertTrue($otherNodePeer->isRunning());
        $this->assertNull($pool->tryAcquire($this->node('node-a'), 2, 'tenant-b', 1, 30));
        $this->assertNull($pool->tryAcquire($this->node('node-b'), 2, 'tenant-a', 1, 30));
        $replacement->release();
    }

    private function hold(string $node, string $namespace): Process
    {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__).'/Support/LongPollWaitPoolHolder.php', $this->node($node), $namespace,
        ], dirname(__DIR__, 2));
        $process->setInput(new InputStream);
        $this->processes[] = $process;
        $process->start();
        $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "held\n")));
        $this->assertTrue($process->isRunning());

        return $process;
    }

    private function node(string $name): string
    {
        return $this->directory.'/'.hash('sha256', $name).'/worker';
    }
}
