#!/usr/bin/env bash
set -uo pipefail

job_scope="${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}-${GITHUB_RUN_ATTEMPT:?GITHUB_RUN_ATTEMPT is required}-${GITHUB_JOB:?GITHUB_JOB is required}"
cluster="dw-helm-ct-${job_scope}"
network="dw-helm-kind-${job_scope}"
requested_cluster="${KIND_CLEANUP_CLUSTER:?KIND_CLEANUP_CLUSTER is required}"
requested_network="${KIND_CLEANUP_NETWORK:-}"
kubeconfig="${KIND_CLEANUP_KUBECONFIG:-}"
attempts="${KIND_CLEANUP_ATTEMPTS:-4}"
delay_seconds="${KIND_CLEANUP_DELAY_SECONDS:-3}"
command_timeout_seconds="${KIND_CLEANUP_COMMAND_TIMEOUT_SECONDS:-30}"

kind_bin="${KIND:-kind}"
docker_bin="${DOCKER:-docker}"

if [ "${requested_cluster}" != "${cluster}" ]; then
  echo "KIND_CLEANUP_CLUSTER must match the current job scope (${cluster})" >&2
  exit 2
fi
if [ -n "${requested_network}" ] && [ "${requested_network}" != "${network}" ]; then
  echo "KIND_CLEANUP_NETWORK must match the current job scope (${network})" >&2
  exit 2
fi
remove_network=0
if [ -n "${requested_network}" ]; then
  remove_network=1
fi

validate_bounded_integer() {
  local name="$1"
  local value="$2"
  local minimum="$3"
  local maximum="$4"

  if [[ ! "${value}" =~ ^[0-9]+$ ]] \
    || [ "${value}" -lt "${minimum}" ] \
    || [ "${value}" -gt "${maximum}" ]; then
    echo "${name} must be an integer between ${minimum} and ${maximum}; got ${value}" >&2
    exit 2
  fi
}

validate_bounded_integer KIND_CLEANUP_ATTEMPTS "${attempts}" 1 10
validate_bounded_integer KIND_CLEANUP_DELAY_SECONDS "${delay_seconds}" 0 30
validate_bounded_integer KIND_CLEANUP_COMMAND_TIMEOUT_SECONDS "${command_timeout_seconds}" 1 120

scratch="$(mktemp -d "${TMPDIR:-/tmp}/dw-kind-cleanup.XXXXXX")"
volumes_file="${scratch}/managed-volumes.txt"
: >"${volumes_file}"

cleanup_scratch() {
  rm -rf -- "${scratch}"
}
trap cleanup_scratch EXIT

run_bounded() {
  timeout --foreground --kill-after=5s "${command_timeout_seconds}s" "$@"
}

record_managed_volumes() {
  local containers container discovered

  if ! containers="$(
    run_bounded "${docker_bin}" ps -aq \
      --filter "label=io.x-k8s.kind.cluster=${cluster}"
  )"; then
    return 1
  fi

  while IFS= read -r container; do
    [ -n "${container}" ] || continue
    if ! discovered="$(
      run_bounded "${docker_bin}" inspect \
        --format '{{range .Mounts}}{{if eq .Type "volume"}}{{println .Name}}{{end}}{{end}}' \
        "${container}"
    )"; then
      return 1
    fi
    if [ -n "${discovered}" ]; then
      printf '%s\n' "${discovered}" >>"${volumes_file}"
    fi
  done <<<"${containers}"

  sort -u -o "${volumes_file}" "${volumes_file}"
}

remove_managed_volumes() {
  local volume status=0

  while IFS= read -r volume; do
    [ -n "${volume}" ] || continue
    run_bounded "${docker_bin}" volume rm -f "${volume}" || status=1
  done <"${volumes_file}"

  return "${status}"
}

remaining_containers=""
remaining_volumes=""
remaining_network=""
inventory_error=""

inventory_cleanup_state() {
  local volume listed

  remaining_containers=""
  remaining_volumes=""
  remaining_network=""
  inventory_error=""

  if ! remaining_containers="$(
    run_bounded "${docker_bin}" ps -aq \
      --filter "label=io.x-k8s.kind.cluster=${cluster}"
  )"; then
    inventory_error="unable to list Kind containers"
    return 1
  fi

  while IFS= read -r volume; do
    [ -n "${volume}" ] || continue
    if ! listed="$(
      run_bounded "${docker_bin}" volume ls -q --filter "name=^${volume}$"
    )"; then
      inventory_error="unable to list Kind volumes"
      return 1
    fi
    if printf '%s\n' "${listed}" | grep -Fxq "${volume}"; then
      remaining_volumes+="${volume}"$'\n'
    fi
  done <"${volumes_file}"

  if [ "${remove_network}" -eq 1 ]; then
    if ! remaining_network="$(
      run_bounded "${docker_bin}" network ls -q \
        --filter "name=^${network}$"
    )"; then
      inventory_error="unable to list the Kind network"
      return 1
    fi
  fi

  [ -z "${remaining_containers}" ] \
    && [ -z "${remaining_volumes}" ] \
    && [ -z "${remaining_network}" ]
}

print_attempt_logs() {
  local attempt file

  echo "::group::Kind cleanup attempts" >&2
  for attempt in $(seq 1 "${attempts}"); do
    file="${scratch}/attempt-${attempt}.log"
    [ -f "${file}" ] || continue
    echo "attempt ${attempt}:" >&2
    tail -n 100 "${file}" >&2 || true
  done
  echo "::endgroup::" >&2
}

print_failure_diagnostics() {
  local containers container volume

  echo "::error title=Kind infrastructure cleanup failed::Helm product validation has a separate outcome; Kind resources still remain after ${attempts} bounded cleanup attempts." >&2
  print_attempt_logs

  echo "::group::Kind container diagnostics" >&2
  run_bounded "${docker_bin}" ps -a --no-trunc \
    --filter "label=io.x-k8s.kind.cluster=${cluster}" >&2 || true
  if containers="$(
    run_bounded "${docker_bin}" ps -aq \
      --filter "label=io.x-k8s.kind.cluster=${cluster}"
  )"; then
    while IFS= read -r container; do
      [ -n "${container}" ] || continue
      run_bounded "${docker_bin}" inspect \
        --format 'state={{json .State}} networks={{json .NetworkSettings.Networks}} mounts={{json .Mounts}}' \
        "${container}" >&2 || true
    done <<<"${containers}"
  fi
  echo "::endgroup::" >&2

  echo "::group::Docker daemon diagnostics" >&2
  run_bounded "${docker_bin}" version >&2 || true
  run_bounded "${docker_bin}" info >&2 || true
  echo "::endgroup::" >&2

  echo "::group::Kind network diagnostics" >&2
  run_bounded "${docker_bin}" network ls >&2 || true
  if [ "${remove_network}" -eq 1 ]; then
    run_bounded "${docker_bin}" network inspect "${network}" >&2 || true
  fi
  echo "::endgroup::" >&2

  echo "::group::Kind volume diagnostics" >&2
  run_bounded "${docker_bin}" volume ls >&2 || true
  while IFS= read -r volume; do
    [ -n "${volume}" ] || continue
    run_bounded "${docker_bin}" volume inspect "${volume}" >&2 || true
  done <"${volumes_file}"
  echo "::endgroup::" >&2

  if [ -n "${inventory_error}" ]; then
    echo "cleanup verification error: ${inventory_error}" >&2
  fi
  if [ -n "${remaining_containers}" ]; then
    printf 'remaining Kind containers:\n%s\n' "${remaining_containers}" >&2
  fi
  if [ -n "${remaining_volumes}" ]; then
    printf 'remaining Kind volumes:\n%s' "${remaining_volumes}" >&2
  fi
  if [ -n "${remaining_network}" ]; then
    printf 'remaining Kind network id: %s\n' "${remaining_network}" >&2
  fi
}

record_managed_volumes || true

recovered_transient=0
for attempt in $(seq 1 "${attempts}"); do
  attempt_log="${scratch}/attempt-${attempt}.log"
  kind_failed=0

  record_managed_volumes >>"${attempt_log}" 2>&1 || true
  run_bounded "${kind_bin}" delete cluster --name "${cluster}" \
    >>"${attempt_log}" 2>&1 || kind_failed=1

  remove_managed_volumes >>"${attempt_log}" 2>&1 || true
  if [ "${remove_network}" -eq 1 ]; then
    run_bounded "${docker_bin}" network rm "${network}" \
      >>"${attempt_log}" 2>&1 || true
  fi
  if [ -n "${kubeconfig}" ]; then
    rm -f -- "${kubeconfig}"
  fi

  if inventory_cleanup_state >>"${attempt_log}" 2>&1; then
    if [ "${kind_failed}" -ne 0 ] || [ "${recovered_transient}" -ne 0 ]; then
      echo "::notice title=Kind infrastructure cleanup recovered::Cleanup for ${cluster} recovered from a transient teardown failure on attempt ${attempt}."
    fi
    echo "kind infrastructure cleanup OK for ${cluster}"
    exit 0
  fi

  recovered_transient=1
  if [ "${attempt}" -lt "${attempts}" ]; then
    echo "::warning title=Retrying Kind infrastructure cleanup::Attempt ${attempt}/${attempts} did not leave an empty resource inventory."
    sleep "${delay_seconds}"
  fi
done

inventory_cleanup_state >/dev/null 2>&1 || true
print_failure_diagnostics
exit 1
