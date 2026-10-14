<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$container = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($container);
$container->instance('config', new Illuminate\Config\Repository([
    'server' => ['external_payload_transport' => ['request_timeout_seconds' => 2]],
]));
$checks = [];
foreach ([0, 1, 8192, 8193, 2097169] as $size) {
    $bytes = str_repeat('x', $size);
    $source = tmpfile();
    fwrite($source, $bytes);
    rewind($source);
    $snapshot = App\Support\ExternalPayloadStream::capture($source, max(1, $size));
    if ($snapshot->sizeBytes !== $size || $snapshot->sha256 !== hash('sha256', $bytes)
        || stream_get_contents($snapshot->rewind()) !== $bytes) {
        throw new RuntimeException('Published exact snapshot disagrees at size '.$size);
    }
    rewind($source);
    ftruncate($source, 0);
    fwrite($source, 'changed after verification');
    if (stream_get_contents($snapshot->rewind()) !== $bytes) {
        throw new RuntimeException('Published snapshot changed with its backing source');
    }
    $snapshot->close();
    fclose($source);
    $checks[] = ['size' => $size, 'outcome' => 'exact_and_independent'];
}
foreach ([1, 8192, 8193] as $limit) {
    $source = tmpfile();
    fwrite($source, str_repeat('x', $limit + 2));
    rewind($source);
    try {
        App\Support\ExternalPayloadStream::capture($source, $limit);
        throw new RuntimeException('Published stream accepted an oversized source');
    } catch (App\Support\ExternalPayloadObjectOversized) {
        if (ftell($source) !== $limit + 1) {
            throw new RuntimeException('Published stream consumed past its one-byte probe');
        }
        $checks[] = ['limit' => $limit, 'outcome' => 'one_byte_oversize_refusal'];
    } finally {
        fclose($source);
    }
}
echo json_encode(['outcome' => 'pass', 'scope' => 'snapshot_bytes_only', 'checks' => $checks,
    'snapshot_source_sha256' => hash_file('sha256', (new ReflectionClass(App\Support\ExternalPayloadStream::class))->getFileName())], JSON_THROW_ON_ERROR)."\n";
