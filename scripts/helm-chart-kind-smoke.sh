#!/usr/bin/env bash
# Helm-chart kind smoke. Mirrors scripts/k8s-kind-smoke.sh but exercises the
# self-serve chart at k8s/helm/durable-workflow/ end-to-end:
#   1. build a server image and load it into kind
#   2. provision in-cluster MySQL + Redis (the chart never bundles them)
#   3. helm install the chart
#   4. verify the bootstrap Job ran, /api/ready returns 200, and a worker
#      can register
#   5. helm upgrade in-place to confirm the rolling-upgrade contract
#   6. helm uninstall cleanly
#
# Designed to run on a CI runner with docker, kind, kubectl, and helm on PATH.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
chart_dir="${repo_root}/k8s/helm/durable-workflow"
fixture_manifest="${repo_root}/scripts/helm-chart-kind-fixtures.yaml"
namespace="${K8S_HELM_SMOKE_NAMESPACE:-durable-workflow}"
release="${K8S_HELM_SMOKE_RELEASE:-durable-workflow}"
cluster="${K8S_HELM_SMOKE_CLUSTER:-durable-workflow-helm-smoke}"
image="${K8S_HELM_SMOKE_IMAGE:-durableworkflow/server:helm-kind-smoke}"
kind_node_image="${K8S_HELM_SMOKE_KIND_NODE_IMAGE:-kindest/node:v1.29.4}"
artifact_dir="${K8S_HELM_SMOKE_ARTIFACT_DIR:-/tmp/durable-workflow-helm-kind-smoke}"
port="${K8S_HELM_SMOKE_PORT:-18180}"
reuse_cluster="${K8S_HELM_SMOKE_REUSE_CLUSTER:-0}"

kubectl_bin="${KUBECTL:-kubectl}"
kind_bin="${KIND:-kind}"
docker_bin="${DOCKER:-docker}"
helm_bin="${HELM:-helm}"

created_cluster=0
port_forward_pid=""

require_bin() {
  local name="$1"
  if ! command -v "$name" >/dev/null 2>&1; then
    echo "Missing required command: ${name}" >&2
    exit 127
  fi
}

collect_artifacts() {
  mkdir -p "${artifact_dir}"
  "${helm_bin}" -n "${namespace}" status "${release}" >"${artifact_dir}/helm-status.txt" 2>&1 || true
  "${helm_bin}" -n "${namespace}" get manifest "${release}" >"${artifact_dir}/helm-manifest.yaml" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" get all,configmap,secret,pdb,ingress,hpa -o wide >"${artifact_dir}/resources.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" describe all >"${artifact_dir}/describe.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" get events --sort-by=.lastTimestamp >"${artifact_dir}/events.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs -l app.kubernetes.io/component=migration --tail=-1 --all-containers=true >"${artifact_dir}/migration-job.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs -l app.kubernetes.io/component=server --tail=-1 --all-containers=true >"${artifact_dir}/server.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs -l app.kubernetes.io/component=worker --tail=-1 --all-containers=true >"${artifact_dir}/worker.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs -l app.kubernetes.io/component=test --tail=-1 --all-containers=true >"${artifact_dir}/test.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-mysql --all-containers=true >"${artifact_dir}/mysql.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-redis --all-containers=true >"${artifact_dir}/redis.log" 2>&1 || true
}

print_failure_summary() {
  local file path
  for file in \
    helm-status.txt \
    events.txt \
    describe.txt \
    resources.txt \
    migration-job.log \
    server.log \
    worker.log \
    test.log \
    mysql.log \
    redis.log \
    port-forward.log; do
    path="${artifact_dir}/${file}"
    if [ ! -f "${path}" ]; then
      continue
    fi
    echo "::group::${file}"
    tail -n 200 "${path}" || cat "${path}" || true
    echo "::endgroup::"
  done
}

cleanup() {
  local status="$?"
  if [ -n "${port_forward_pid}" ]; then
    kill "${port_forward_pid}" >/dev/null 2>&1 || true
  fi

  if [ "${status}" -ne 0 ]; then
    collect_artifacts
    print_failure_summary
  fi

  if [ "${created_cluster}" -eq 1 ] && [ "${KEEP_KIND_CLUSTER:-0}" != "1" ]; then
    "${kind_bin}" delete cluster --name "${cluster}" >/dev/null 2>&1 || true
  fi
  exit "${status}"
}

trap cleanup EXIT

apply_dependencies() {
  "${kubectl_bin}" apply -f "${fixture_manifest}"
}

wait_for_http() {
  local url="$1"
  local header_args=("${@:2}")
  for _ in $(seq 1 60); do
    if curl -fsS "${header_args[@]}" "${url}" >/dev/null; then
      return 0
    fi
    sleep 2
  done
  curl -fsS "${header_args[@]}" "${url}"
}

wait_for_kubernetes_api() {
  for _ in $(seq 1 60); do
    if "${kubectl_bin}" get --raw='/readyz' >/dev/null 2>&1 \
      && "${kubectl_bin}" wait --for=condition=Ready nodes --all --timeout=5s >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  "${kubectl_bin}" get --raw='/readyz?verbose'
}

start_port_forward() {
  if [ -n "${port_forward_pid}" ]; then
    kill "${port_forward_pid}" >/dev/null 2>&1 || true
    wait "${port_forward_pid}" 2>/dev/null || true
  fi

  "${kubectl_bin}" -n "${namespace}" port-forward "svc/${server_service}" "${port}:8080" \
    >"${artifact_dir}/port-forward.log" 2>&1 &
  port_forward_pid="$!"
  sleep 2
}

list_chart_owned_resources() {
  "${kubectl_bin}" -n "${namespace}" \
    get all,configmap,secret,pdb,ingress,hpa,cronjob,serviceaccount \
    -l "app.kubernetes.io/instance=${release}" \
    -o name 2>/dev/null || true
}

wait_for_chart_resource_cleanup() {
  local remaining

  for _ in $(seq 1 60); do
    remaining="$(list_chart_owned_resources)"
    if [ -z "${remaining}" ]; then
      return 0
    fi
    sleep 2
  done

  printf '%s\n' "${remaining}"
  return 1
}

resolve_chart_resource_name() {
  local kind="$1"
  local component="$2"
  local name

  name="$("${kubectl_bin}" -n "${namespace}" get "${kind}" \
    -l "app.kubernetes.io/instance=${release},app.kubernetes.io/component=${component}" \
    -o jsonpath='{.items[0].metadata.name}')"

  if [ -z "${name}" ]; then
    echo "unable to resolve ${kind} for component ${component} in release ${release}" >&2
    "${kubectl_bin}" -n "${namespace}" get "${kind}" \
      -L app.kubernetes.io/component,app.kubernetes.io/instance >&2 || true
    exit 1
  fi

  printf '%s\n' "${name}"
}

require_bin "${docker_bin}"
require_bin "${kind_bin}"
require_bin "${kubectl_bin}"
require_bin "${helm_bin}"
require_bin curl
require_bin openssl

mkdir -p "${artifact_dir}"
rm -rf "${artifact_dir}"
mkdir -p "${artifact_dir}"

# 1. Build the server image.
docker_build_args=()
if [ -n "${WORKFLOW_PACKAGE_SOURCE:-}" ]; then
  docker_build_args+=(--build-arg "WORKFLOW_PACKAGE_SOURCE=${WORKFLOW_PACKAGE_SOURCE}")
fi
if [ -n "${WORKFLOW_PACKAGE_REF:-}" ]; then
  docker_build_args+=(--build-arg "WORKFLOW_PACKAGE_REF=${WORKFLOW_PACKAGE_REF}")
fi
if [ -n "${WORKFLOW_PACKAGE_COMMIT:-}" ]; then
  docker_build_args+=(--build-arg "WORKFLOW_PACKAGE_COMMIT=${WORKFLOW_PACKAGE_COMMIT}")
fi
if [ -n "${WORKFLOW_PACKAGE_QUALIFICATION_REF:-}" ]; then
  docker_build_args+=(--build-arg "WORKFLOW_PACKAGE_QUALIFICATION_REF=${WORKFLOW_PACKAGE_QUALIFICATION_REF}")
fi

"${docker_bin}" build "${docker_build_args[@]}" -t "${image}" "${repo_root}"

# 2. Provision the kind cluster (or reuse one provided by the runner).
if "${kind_bin}" get clusters | grep -Fxq "${cluster}"; then
  if [ "${reuse_cluster}" != "1" ]; then
    echo "kind cluster ${cluster} already exists; pass K8S_HELM_SMOKE_REUSE_CLUSTER=1 to reuse it" >&2
    exit 1
  fi
else
  "${kind_bin}" create cluster --name "${cluster}" --image "${kind_node_image}" --wait 120s
  created_cluster=1
fi

"${kind_bin}" load docker-image "${image}" --name "${cluster}"
wait_for_kubernetes_api

# 3. Provision in-cluster MySQL + Redis fixtures (the chart never bundles them).
apply_dependencies
"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-mysql --timeout=180s
"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-redis --timeout=120s

# 4. Render install values pointing at the kind-loaded image and inline secrets.
app_key="base64:$(openssl rand -base64 32)"
install_values="${artifact_dir}/install-values.yaml"
cat >"${install_values}" <<EOF
image:
  registry: $(echo "${image}" | awk -F/ '{print $1}')
  repository: $(echo "${image}" | sed -E 's#^[^/]+/([^:]+).*#\1#')
  tag: $(echo "${image}" | awk -F: '{print $2}')
  # This image is built from the checked-out Server source above, which writes
  # both memo representations. Custom tags must attest that verified identity.
  memoPayloadStorage: dual-v1
  pullPolicy: IfNotPresent

externalDatabase:
  connection: mysql
  host: mysql.${namespace}.svc.cluster.local
  port: 3306
  database: durable_workflow
  auth:
    username: durable_workflow
    password: durable_workflow

externalRedis:
  host: redis.${namespace}.svc.cluster.local
  port: 6379

auth:
  serverKey: "${app_key}"
  authToken: kind-auth-token
  workerToken: kind-worker-token
  operatorToken: kind-operator-token
  adminToken: kind-admin-token

server:
  replicaCount: 1
  pdb:
    enabled: false
worker:
  replicaCount: 1
EOF

# 5. helm install. --wait covers the bootstrap Job hook + workload rollouts.
"${helm_bin}" upgrade --install "${release}" "${chart_dir}" \
  --namespace "${namespace}" --create-namespace \
  -f "${install_values}" \
  --wait --timeout 5m

server_service="$(resolve_chart_resource_name svc server)"
server_deployment="$(resolve_chart_resource_name deploy server)"

# 6. Verify the API answers behind the chart-rendered Service.
start_port_forward

wait_for_http "http://127.0.0.1:${port}/api/health"
wait_for_http "http://127.0.0.1:${port}/api/ready"
wait_for_http "http://127.0.0.1:${port}/api/cluster/info" \
  -H "Authorization: Bearer kind-admin-token" \
  -H "X-Namespace: default"

curl -fsS -X POST "http://127.0.0.1:${port}/api/worker/register" \
  -H "Authorization: Bearer kind-worker-token" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -H "Content-Type: application/json" \
  --data '{"worker_id":"helm-smoke-worker","task_queue":"default","runtime":"python"}' \
  | tee "${artifact_dir}/worker-register.json" >/dev/null

# 7. helm test exercises the test-readiness Pod against the in-cluster Service.
"${helm_bin}" test "${release}" --namespace "${namespace}"

# 8. helm upgrade in place (no-op values change to bump revision).
"${helm_bin}" upgrade "${release}" "${chart_dir}" \
  --namespace "${namespace}" \
  -f "${install_values}" \
  --set server.podAnnotations.kindSmoke="upgrade-${RANDOM}" \
  --wait --timeout 5m

server_service="$(resolve_chart_resource_name svc server)"
server_deployment="$(resolve_chart_resource_name deploy server)"
start_port_forward
"${kubectl_bin}" -n "${namespace}" rollout status "deploy/${server_deployment}" --timeout=120s
wait_for_http "http://127.0.0.1:${port}/api/ready"

# 9. helm uninstall is the last assertion: the chart owns its lifecycle and
#    leaves only the externally-managed dependencies behind.
"${helm_bin}" uninstall "${release}" --namespace "${namespace}" --wait --timeout 5m

if ! remaining_chart_resources="$(wait_for_chart_resource_cleanup)"; then
  echo "helm uninstall left chart-owned resources behind:" >&2
  echo "${remaining_chart_resources}" >&2
  exit 1
fi

collect_artifacts
echo "helm chart kind smoke OK"
