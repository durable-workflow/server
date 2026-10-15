#!/usr/bin/env bash
# Run unchanged published clients behind a bounded, byte-preserving observer.
set -euo pipefail
test "$#" = 5 || exit 2
network=$1
url=$2
python_sdk=$(realpath "$3")
output=$(realpath -m "$4")
target=$5
case "$target" in php|rust) ;; *) exit 2 ;; esac
test "$network" = "${RESOURCE_SCOPE:?Set the isolated owning scope}"
source_root=$(git rev-parse --show-toplevel)
profile=tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json
image=$(jq -r '.server.image' "$profile")
test ! -e "$output"
test -f "$python_sdk/durable_workflow/__init__.py"
client_binary=$(realpath "${DW_PARITY_QUERY_CLIENT_BIN:-rust-target/debug/server-parity-query-authority-client}")
test -f "$client_binary"
mkdir -p "$output"
jq -n --arg revision "$(git rev-parse HEAD)" --arg binary "$(sha256sum "$client_binary" | cut -d ' ' -f1)" \
  --arg observer "$(sha256sum scripts/conformance/server-parity/query-authority/observer.mjs | cut -d ' ' -f1)" \
  --arg profile "$(sha256sum "$profile" | cut -d ' ' -f1)" \
  '{runner_revision:$revision,consumer_binary_sha256:$binary,observer_sha256:$observer,profile_sha256:$profile}' >"$output/identity.json"
case_output=''
name=''
language=''
retire() {
  local result=0
  if test -f "$case_output/client-container.id" && docker inspect "$RESOURCE_SCOPE-query-client-$target-$language-$name" >/dev/null 2>&1; then
    test "$(docker inspect --format '{{.Id}}' "$RESOURCE_SCOPE-query-client-$target-$language-$name")" = "$(cat "$case_output/client-container.id")" || return 1
    docker stop --signal=SIGTERM --timeout=5 "$RESOURCE_SCOPE-query-client-$target-$language-$name" >/dev/null || result=1
  fi
  if test -f "$case_output/observer-container.id" && docker inspect "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" >/dev/null 2>&1; then
    test "$(docker inspect --format '{{.Id}}' "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name")" = "$(cat "$case_output/observer-container.id")" || return 1
    docker stop --signal=SIGTERM --timeout=10 "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" >"$case_output/observer-stop.txt" 2>&1 || true
    docker logs "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" >"$case_output/observer.log" 2>&1
    docker inspect --format '{{json .State}}' "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" >"$case_output/observer-state.json"
    jq -e '.ExitCode==0 and .OOMKilled==false and .Running==false' "$case_output/observer-state.json" >/dev/null || result=1
  fi
  return "$result"
}
trap retire EXIT
for language in python rust; do
  while IFS=$'\t' read -r mode protocol; do
    name="$mode-${protocol//./}"
    case_output="$output/$language-$name"
    mkdir "$case_output"
    if docker inspect "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" >/dev/null 2>&1; then exit 1; fi
    docker run -d --name "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" --network "$network" \
      --label "durable-workflow.query-authority-client=$RESOURCE_SCOPE" \
      --network-alias=parity-query-client-observer --user="$(id -u):$(id -g)" \
      --cpus=1 --memory=256m --memory-swap=256m --entrypoint node \
      -v "$source_root:/source:ro" -v "$case_output:/evidence" "$image" \
      /source/scripts/conformance/server-parity/query-authority/observer.mjs "$url" "$mode" /evidence/http.jsonl \
      >"$case_output/observer-container.id"
    ready=0
    for attempt in $(seq 1 50); do
      if docker logs "$RESOURCE_SCOPE-query-client-observer-$target-$language-$name" 2>/dev/null | jq -e '.ready==true' >/dev/null; then ready=1; break; fi
      sleep .05
    done
    test "$ready" = 1
    client_image=$(jq -r --arg language "$language" '.client_images[$language]' "$profile")
    if test "$language" = python; then
      actor=(--entrypoint python -e PYTHONPATH=/sdk -e PYTHONDONTWRITEBYTECODE=1 \
        -v "$python_sdk:/sdk:ro" "$client_image" /source/scripts/conformance/server-parity/query-authority/python.py)
    else
      actor=(--entrypoint /client -v "$client_binary:/client:ro" "$client_image")
    fi
    status=0
    timeout --signal=TERM --kill-after=5s 40s docker run --rm --cidfile "$case_output/client-container.id" \
      --name "$RESOURCE_SCOPE-query-client-$target-$language-$name" --network "$network" --user="$(id -u):$(id -g)" \
      --label "durable-workflow.query-authority-client=$RESOURCE_SCOPE" \
      --cpus=1 --memory=512m --memory-swap=512m -e DW_PARITY_TOKEN \
      -e DW_PARITY_QUERY_PROFILE=/source/tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json \
      -v "$source_root:/source:ro" -v "$case_output:/evidence:ro" "${actor[@]}" \
      http://parity-query-client-observer:8080 "$mode" "$protocol" /evidence/http.jsonl \
      >"$case_output/result.json" 2>"$case_output/client.stderr" || status=$?
    retire || status=1
    if test "$status" != 0; then
      printf 'Query client failed: target=%s language=%s mode=%s protocol=%s exit=%s\n' \
        "$target" "$language" "$mode" "$protocol" "$status" >&2
      tail -c 8192 "$case_output/client.stderr" >&2
      if test -f "$case_output/observer.log"; then tail -c 8192 "$case_output/observer.log" >&2; fi
      exit "$status"
    fi
    jq -e --arg language "$language" --arg mode "$mode" --arg protocol "$protocol" \
      '.outcome=="pass" and .language==$language and .mode==$mode and .protocol==$protocol
        and .worker_exit==0 and .original_budget_seconds==30 and .elapsed_seconds<30' "$case_output/result.json" >/dev/null
    jq '{outcome,language,mode,protocol,run_id,elapsed_seconds,worker_exit}' "$case_output/result.json"
  done < <(jq -r '.client_cases[]|[.mode,.protocol]|@tsv' "$profile")
done
jq -s --arg target "$target" '{outcome:"pass",target:$target,originals:length,cases:map({language,mode,protocol,
  namespace,run_id,elapsed_seconds,worker_exit,history_sha256})}' "$output/"*/result.json >"$output/comparison.json"
test "$(jq -r .originals "$output/comparison.json")" = "$(jq '.client_cases|length*2' "$profile")"
