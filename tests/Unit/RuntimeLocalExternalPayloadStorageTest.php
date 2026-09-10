<?php

namespace Tests\Unit;

use App\Support\RuntimeLocalExternalPayloadStorage;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RuntimeLocalExternalPayloadStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/local-payload-commit');
        File::deleteDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('retryCases')]
    public function test_string_retry_commits_exact_bytes(string $payload, string $previous): void
    {
        $driver = new RuntimeLocalExternalPayloadStorage($this->directory);
        $hash = hash('sha256', $payload);
        $uri = $driver->uriFor($hash, 'avro');
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH));
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $previous);
        $inode = fileinode($path);

        $this->assertSame($uri, $driver->put($payload, $hash, 'avro'));
        $this->assertSame($payload, file_get_contents($path));
        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertSame($inode, fileinode($path));
        $this->assertSame($uri, $driver->put($payload, $hash, 'avro'));
        $this->assertSame($payload, file_get_contents($path));
    }

    public static function retryCases(): array
    {
        return [
            'partial' => ['complete-payload', 'partial'],
            'equal-length corruption' => ['correct', 'corrupt'],
            'binary' => ["value\xff\x00", "value\x00\x00"],
            'empty' => ['', 'stale'],
            'already committed' => ['correct', 'correct'],
        ];
    }

    public function test_string_and_stream_share_identity_without_a_second_large_copy(): void
    {
        $driver = new RuntimeLocalExternalPayloadStorage($this->directory);
        $payload = str_repeat('x', 16 * 1024 * 1024);
        $hash = hash('sha256', $payload);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $uri = $driver->put($payload, $hash, 'avro');
        $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH));
        $source = fopen($path, 'rb');

        try {
            $this->assertSame($uri, $driver->putStream($source, $hash, 'avro'));
            $this->assertSame($hash, hash_file('sha256', $path));
        } finally {
            fclose($source);
        }
    }
}
