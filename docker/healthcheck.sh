#!/usr/bin/env sh
set -eu

# Command overrides run queue workers, schedulers, bootstrap, and other CLI
# processes from the same image. Only the default HTTP process owns this probe.
if [ ! -f /tmp/dw-server-http-process ]; then
    exit 0
fi

response_file="$(mktemp /tmp/dw-server-readiness.XXXXXX)"
trap 'rm -f "$response_file"' EXIT

if ! http_status="$(curl --silent --show-error --max-time 4 \
    --output "$response_file" --write-out '%{http_code}' \
    http://127.0.0.1:8080/api/ready)"; then
    exit 1
fi

if [ "$http_status" = "200" ]; then
    exit 0
fi

php -r '
$payload = json_decode((string) file_get_contents($argv[1]), true);
$blockers = [];
foreach (is_array($payload["checks"] ?? null) ? $payload["checks"] : [] as $name => $check) {
    if (! is_array($check) || in_array($check["status"] ?? null, ["ok", "warning"], true)) {
        continue;
    }
    $blockers[$name] = array_filter([
        "status" => $check["status"] ?? "unknown",
        "remediation" => $check["remediation"] ?? null,
    ], static fn (mixed $value): bool => $value !== null);
}
echo json_encode([
    "status" => $payload["status"] ?? "not_ready",
    "blockers" => $blockers,
], JSON_UNESCAPED_SLASHES), PHP_EOL;
' "$response_file" || true

exit 1
