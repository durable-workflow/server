#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 2 || "$1" != --result-dir ]]; then
  printf '%s\n' 'Usage: worker-versioning-rust-host-published-artifacts.sh --result-dir DIR' >&2
  exit 2
fi
for variable in DW_SERVER_VERSION DW_RUST_SDK_VERSION; do
  [[ "${!variable:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
done
[[ "${DW_SERVER_IMAGE:-}" =~ ^(docker.io/)?durableworkflow/server@sha256:[0-9a-f]{64}$ ]]
mkdir -p "$2"
result_dir="$(cd "$2" && pwd)"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
run_id="${DW_JOB_RESOURCE_PREFIX:-${GITHUB_RUN_ID:-$(date -u +%s)}-${GITHUB_RUN_ATTEMPT:-$$}-${GITHUB_JOB:-rust-versioning}}"
resource="dw-wv-rust-${run_id}"
rust_image="${DW_WV_RUST_IMAGE:-rust@sha256:300ec56abce8cc9448ddea2172747d048ed902a3090e6b57babb2bf19f754081}"

cleanup() {
  local status=$?
  for role in server mysql redis; do
    docker logs "$resource-$role" > "$result_dir/$role.log" 2>&1 || true
  done
  docker rm -f -v "$resource-runner" "$resource-server" "$resource-mysql" "$resource-redis" "$resource-node" >/dev/null 2>&1 || true
  docker network rm "$resource" >/dev/null 2>&1 || true
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker pull "$DW_SERVER_IMAGE"
docker inspect "$DW_SERVER_IMAGE" > "$result_dir/server-image.json"
docker network create "$resource" >/dev/null
docker run -d --name "$resource-mysql" --network "$resource" \
  -e MYSQL_DATABASE=durable_workflow -e MYSQL_USER=workflow \
  -e MYSQL_PASSWORD=workflow -e MYSQL_ROOT_PASSWORD=root \
  mysql@sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6 >/dev/null
docker run -d --name "$resource-redis" --network "$resource" \
  redis@sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c >/dev/null
for attempt in $(seq 1 60); do
  if docker exec "$resource-mysql" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; then break; fi
  if [[ "$attempt" == 60 ]]; then printf '%s\n' 'MySQL readiness deadline expired' >&2; exit 1; fi
  sleep 1
done
docker run -d --init --name "$resource-server" --network "$resource" \
  -e APP_ENV=production -e APP_DEBUG=false -e LOG_CHANNEL=stderr \
  -e APP_KEY=base64:ZHVyYWJsZS13b3JrZmxvdy1saWZlY3ljbGUta2V5ISE= \
  -e DB_CONNECTION=mysql -e DB_HOST="$resource-mysql" \
  -e DB_DATABASE=durable_workflow -e DB_USERNAME=workflow -e DB_PASSWORD=workflow \
  -e REDIS_HOST="$resource-redis" -e QUEUE_CONNECTION=redis -e CACHE_STORE=redis \
  -e DW_AUTH_DRIVER=token -e DW_AUTH_TOKEN=dev-token \
  -e DW_TASK_DISPATCH_MODE=poll -e DW_V2_TASK_DISPATCH_MODE=poll "$DW_SERVER_IMAGE" >/dev/null
docker cp "$resource-server:/app/resources/release/source-release.json" "$result_dir/server-source-release.json"
docker exec --user 1000:1000 "$resource-server" server-bootstrap > "$result_dir/bootstrap.log" 2>&1
docker exec "$resource-server" curl -fsS http://127.0.0.1:8080/api/ready > "$result_dir/readiness.json"

docker create --name "$resource-node" node:22-slim >/dev/null
docker cp "$resource-node:/usr/local/bin/node" "$result_dir/node"
docker run --rm --init --user "$(id -u):$(id -g)" \
  --name "$resource-runner" --network "$resource" --cpus=2 --memory=2g \
  --volume "$repo_root:/repo:ro" --volume "$result_dir:/result" \
  -e CARGO_HOME=/result/cargo -e CARGO_TARGET_DIR=/result/target -e CARGO_BUILD_JOBS=2 \
  -e DW_SERVER_VERSION -e DW_SERVER_IMAGE -e DW_RUST_SDK_VERSION -e DW_WV_RUNNER_COMMIT \
  -e DW_WV_NAMESPACE="${DW_WV_NAMESPACE:-rust-worker-versioning}" \
  -e DW_WV_SERVER_URL="http://$resource-server:8080" \
  -e DW_WV_RESULT_DIR=/result -e DW_WV_RUN_ROOT=/result/run \
  "$rust_image" /result/node /repo/scripts/conformance/worker-versioning-rust-published-workers.mjs
