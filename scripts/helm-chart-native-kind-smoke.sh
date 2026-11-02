#!/usr/bin/env bash
# Helm-chart kind smoke for the native Server (server#946). It installs the
# chart's default published native image, with no runtime value, against a
# fresh in-cluster MySQL database, then exercises the guards that need a live
# release:
#   1. install, wait for the single server Deployment, run helm test and an
#      authenticated API call; assert that no PHP role or Redis Secret exists
#   2. upgrade with an empty runtime and confirm the release stays native
#   3. confirm the schema-upgrade hook refuses unless live state proves every
#      Server stopped (replicas, client-side render, autoscaling values, a live
#      autoscaler, a live pod), then run it (it verifies a current schema)
#   4. confirm an upgrade of a release without the native runtime marker,
#      as chart 0.x left every PHP release, refuses an empty runtime
#   5. uninstall cleanly
#
# Designed to run on a CI runner with docker, kind, kubectl, helm, curl and
# python3 on PATH.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
chart_dir="${repo_root}/k8s/helm/durable-workflow"
fixture_manifest="${repo_root}/scripts/helm-chart-kind-fixtures.yaml"
namespace="${K8S_HELM_NATIVE_SMOKE_NAMESPACE:-durable-workflow-native}"
release="${K8S_HELM_NATIVE_SMOKE_RELEASE:-native}"
stub_release="${release}-php-stub"
cluster="${K8S_HELM_SMOKE_CLUSTER:-durable-workflow-helm-smoke}"
kind_node_image="${K8S_HELM_SMOKE_KIND_NODE_IMAGE:-kindest/node:v1.29.4}"
artifact_dir="${K8S_HELM_NATIVE_SMOKE_ARTIFACT_DIR:-/tmp/durable-workflow-helm-native-kind-smoke}"
port="${K8S_HELM_NATIVE_SMOKE_PORT:-18181}"
reuse_cluster="${K8S_HELM_SMOKE_REUSE_CLUSTER:-0}"

kubectl_bin="${KUBECTL:-kubectl}"
kind_bin="${KIND:-kind}"
helm_bin="${HELM:-helm}"

created_cluster=0
port_forward_pid=""
work_dir="$(mktemp -d)"

for command in "${kubectl_bin}" "${helm_bin}" curl python3; do
  command -v "${command}" >/dev/null 2>&1 || { echo "Missing required command: ${command}" >&2; exit 127; }
done
mkdir -p "${artifact_dir}"

collect_artifacts() {
  "${helm_bin}" -n "${namespace}" status "${release}" >"${artifact_dir}/helm-status.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" get all,configmap,secret,pdb,pvc -o wide >"${artifact_dir}/resources.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" describe all >"${artifact_dir}/describe.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" get events --sort-by=.lastTimestamp >"${artifact_dir}/events.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs -l app.kubernetes.io/component=server --tail=-1 --all-containers=true >"${artifact_dir}/server.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs -l app.kubernetes.io/component=schema-upgrade --tail=-1 --all-containers=true >"${artifact_dir}/schema-upgrade.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-mysql --all-containers=true >"${artifact_dir}/mysql.log" 2>&1 || true
}

cleanup() {
  local status=$?
  if [[ -n "${port_forward_pid}" ]]; then
    kill "${port_forward_pid}" >/dev/null 2>&1 || true
  fi
  if [[ "${status}" -ne 0 ]]; then
    collect_artifacts
    echo "native helm chart kind smoke failed; artifacts in ${artifact_dir}" >&2
  fi
  rm -rf "${work_dir}"
  if [[ "${created_cluster}" = "1" ]]; then
    "${kind_bin}" delete cluster --name "${cluster}" >/dev/null 2>&1 || true
  fi
  exit "${status}"
}
trap cleanup EXIT

expect_refusal() {
  local scenario="$1" expected="$2" output
  shift 2
  if output="$("$@" 2>&1)"; then
    printf 'expected a refusal: %s\n%s\n' "${scenario}" "${output}" >&2
    exit 1
  fi
  if ! grep -qF "${expected}" <<<"${output}"; then
    printf 'refusal for %s did not explain itself; expected "%s":\n%s\n' "${scenario}" "${expected}" "${output}" >&2
    exit 1
  fi
  printf 'PASS refused: %s\n' "${scenario}"
}

start_port_forward() {
  "${kubectl_bin}" -n "${namespace}" port-forward "svc/${release}-durable-workflow-server" "${port}:8080" \
    >"${artifact_dir}/port-forward.log" 2>&1 &
  port_forward_pid=$!
  for _ in $(seq 1 30); do
    if curl -fsS "http://127.0.0.1:${port}/api/health" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "port-forward to the native Service never answered" >&2
  exit 1
}

# 1. Cluster and a fresh MySQL database. The shared fixture manifest is reused
#    without Redis, which native does not need, in its own namespace so a
#    database the PHP smoke bootstrapped is never offered to native.
if [[ "${reuse_cluster}" != "1" ]]; then
  command -v "${kind_bin}" >/dev/null 2>&1 || { echo "Missing required command: ${kind_bin}" >&2; exit 127; }
  "${kind_bin}" create cluster --name "${cluster}" --image "${kind_node_image}" --wait 120s
  created_cluster=1
fi

python3 - "${fixture_manifest}" "${namespace}" >"${work_dir}/mysql.yaml" <<'PY'
import sys
source, namespace = sys.argv[1], sys.argv[2]
documents = open(source).read().split("\n---\n")
kept = [doc for doc in documents if "redis" not in doc]
text = "\n---\n".join(kept)
text = text.replace("namespace: durable-workflow\n", f"namespace: {namespace}\n")
text = text.replace("kind: Namespace\nmetadata:\n  name: durable-workflow\n", f"kind: Namespace\nmetadata:\n  name: {namespace}\n")
print(text)
PY
"${kubectl_bin}" apply -f "${work_dir}/mysql.yaml"
"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-mysql --timeout=180s

values="${work_dir}/values.yaml"
cat >"${values}" <<EOF
externalDatabase:
  connection: mysql
  host: mysql.${namespace}.svc.cluster.local
  port: 3306
  database: durable_workflow
  auth:
    username: durable_workflow
    password: durable_workflow

auth:
  authToken: kind-native-shared-token
  workerToken: kind-native-worker-token
  operatorToken: kind-native-operator-token
  adminToken: kind-native-admin-token

server:
  replicaCount: 1
  pdb:
    enabled: false
EOF
image_options=()
if [[ -n "${K8S_HELM_NATIVE_SMOKE_IMAGE_TAG:-}" ]]; then
  image_options=(--set-string "image.tag=${K8S_HELM_NATIVE_SMOKE_IMAGE_TAG}")
fi

# 2. Install with no runtime value: a new install runs native.
"${helm_bin}" install "${release}" "${chart_dir}" --namespace "${namespace}" \
  -f "${values}" "${image_options[@]}" --wait --timeout 5m

workloads="$("${kubectl_bin}" -n "${namespace}" get deploy,cronjob,job \
  -l "app.kubernetes.io/instance=${release}" -o name)"
if [[ "${workloads}" != "deployment.apps/${release}-durable-workflow-server" ]]; then
  printf 'native mode must render only the server Deployment, found:\n%s\n' "${workloads}" >&2
  exit 1
fi
if "${kubectl_bin}" -n "${namespace}" get secret "${release}-durable-workflow-redis" >/dev/null 2>&1; then
  echo "native mode rendered a Redis Secret" >&2
  exit 1
fi
marker="$("${kubectl_bin}" -n "${namespace}" get deploy "${release}-durable-workflow-server" \
  -o jsonpath='{.metadata.annotations.workflows\.durable-workflow\.dev/runtime}')"
[[ "${marker}" = "native" ]] || { echo "server Deployment lacks the native runtime marker" >&2; exit 1; }

"${helm_bin}" test "${release}" --namespace "${namespace}"
start_port_forward
curl -fsS "http://127.0.0.1:${port}/api/ready" | tee "${artifact_dir}/ready.json"
echo
curl -fsS -H "Authorization: Bearer kind-native-admin-token" -H "X-Namespace: default" \
  "http://127.0.0.1:${port}/api/cluster/info" >"${artifact_dir}/cluster-info.json"
kill "${port_forward_pid}" >/dev/null 2>&1 || true
port_forward_pid=""

# 3. An upgrade with an empty runtime finds the native marker and stays native.
"${helm_bin}" upgrade "${release}" "${chart_dir}" --namespace "${namespace}" \
  -f "${values}" "${image_options[@]}" --set-string server.podAnnotations.kindSmoke="upgrade-${RANDOM}" \
  --wait --timeout 5m
echo "PASS allowed: upgrade with an empty runtime stays native"

# 4. The schema-upgrade hook renders only when live state proves every native
#    Server is stopped: a visible, native-marked Deployment at zero replicas,
#    no autoscaler and no running server pod. Each counterfactual is refused.
schema_upgrade() {
  local target="$1"
  shift
  "${helm_bin}" upgrade "${target}" "${chart_dir}" --namespace "${namespace}" \
    -f "${values}" "${image_options[@]}" --set native.schemaUpgrade.enabled=true "$@"
}
server_deployment="${release}-durable-workflow-server"

expect_refusal "schema upgrade while the Server runs" "has 1 replicas" \
  schema_upgrade "${release}" --dry-run=server

"${helm_bin}" upgrade "${release}" "${chart_dir}" --namespace "${namespace}" \
  -f "${values}" "${image_options[@]}" --set server.replicaCount=0 --wait --timeout 5m
"${kubectl_bin}" -n "${namespace}" wait --for=delete pod \
  -l "app.kubernetes.io/instance=${release},app.kubernetes.io/component=server" --timeout=120s

# An explicit runtime keeps the runtime selection, which also needs the live
# Deployment, from refusing first.
expect_refusal "schema upgrade without a cluster view" "needs to see the live Deployment" \
  schema_upgrade "${release}" --dry-run=client --set runtime=native
expect_refusal "schema upgrade with autoscaling in the values" "cannot run with server.autoscaling.enabled" \
  schema_upgrade "${release}" --dry-run=server --set server.autoscaling.enabled=true \
  --set-string native.storage.existingClaim=unused-claim

cat >"${work_dir}/stray-autoscaler.yaml" <<YAML
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: stray-autoscaler
  namespace: ${namespace}
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: ${server_deployment}
  minReplicas: 1
  maxReplicas: 2
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 80
YAML
# An autoscaler leaves a Deployment at zero replicas alone, so this one only
# proves the guard sees it.
"${kubectl_bin}" apply -f "${work_dir}/stray-autoscaler.yaml" >/dev/null
expect_refusal "schema upgrade with a live autoscaler" "HorizontalPodAutoscaler stray-autoscaler" \
  schema_upgrade "${release}" --dry-run=server
"${kubectl_bin}" -n "${namespace}" delete hpa stray-autoscaler --wait >/dev/null

"${kubectl_bin}" -n "${namespace}" run stray-server --image=registry.k8s.io/pause:3.9 --restart=Never \
  --labels="app.kubernetes.io/instance=${release},app.kubernetes.io/component=server" >/dev/null
expect_refusal "schema upgrade with a live server pod" "server pod stray-server is still" \
  schema_upgrade "${release}" --dry-run=server
"${kubectl_bin}" -n "${namespace}" delete pod stray-server --grace-period=0 --force --wait >/dev/null 2>&1

# The positive render: the hook Job runs schema-upgrade with the database CA.
schema_upgrade "${release}" --dry-run=server --set-string externalDatabase.sslMode=verify-full \
  --set-string externalDatabase.tls.existingSecret=smoke-database-ca >"${work_dir}/hook.yaml"
for expected in '"helm.sh/hook": pre-upgrade' 'args: ["schema-upgrade"]' 'automountServiceAccountToken: false' \
  'name: DB_SSLROOTCERT' 'secretName: "smoke-database-ca"'; do
  grep -qF "${expected}" "${work_dir}/hook.yaml" || {
    printf 'the authorized schema-upgrade render lacks %s\n' "${expected}" >&2
    exit 1
  }
done
echo "PASS allowed: schema-upgrade hook renders once every Server is stopped"

schema_upgrade "${release}" --wait --timeout 5m
"${kubectl_bin}" -n "${namespace}" rollout status "deploy/${server_deployment}" --timeout=180s
echo "PASS allowed: schema-upgrade hook ran with every Server stopped"

# 5. A release whose server Deployment lacks the native marker, as every PHP
#    release from chart 0.x does, must choose a runtime explicitly.
stub_chart="${work_dir}/php-stub"
mkdir -p "${stub_chart}/templates"
cat >"${stub_chart}/Chart.yaml" <<'YAML'
apiVersion: v2
name: durable-workflow
version: 0.3.9
appVersion: "2.11.8"
YAML
cat >"${stub_chart}/templates/server.yaml" <<'YAML'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ .Release.Name }}-durable-workflow-server
spec:
  replicas: 0
  selector:
    matchLabels:
      app.kubernetes.io/instance: {{ .Release.Name }}
      app.kubernetes.io/component: server
  template:
    metadata:
      labels:
        app.kubernetes.io/instance: {{ .Release.Name }}
        app.kubernetes.io/component: server
    spec:
      containers:
        - name: server
          image: docker.io/durableworkflow/server:2.11.8
YAML
"${helm_bin}" install "${stub_release}" "${stub_chart}" --namespace "${namespace}" >/dev/null
expect_refusal "upgrade of a PHP release with an empty runtime" "runs the PHP 2.x Server" \
  "${helm_bin}" upgrade "${stub_release}" "${chart_dir}" --namespace "${namespace}" \
  -f "${values}" "${image_options[@]}" --dry-run=server
"${helm_bin}" upgrade "${stub_release}" "${chart_dir}" --namespace "${namespace}" \
  -f "${values}" "${image_options[@]}" --set runtime=native --dry-run=server >/dev/null
echo "PASS allowed: an explicit runtime renders against the PHP release"
# A stopped PHP predecessor is not a native schema: schema-upgrade must never
# run against it, marked or not.
expect_refusal "schema upgrade of an unmarked predecessor" "does not carry the native runtime marker" \
  schema_upgrade "${stub_release}" --set runtime=native --dry-run=server
"${kubectl_bin}" -n "${namespace}" annotate deploy "${stub_release}-durable-workflow-server" \
  workflows.durable-workflow.dev/runtime=php >/dev/null
expect_refusal "schema upgrade of a PHP-marked predecessor" "does not carry the native runtime marker" \
  schema_upgrade "${stub_release}" --set runtime=native --dry-run=server
"${helm_bin}" uninstall "${stub_release}" --namespace "${namespace}" --wait --timeout 2m

# 6. Uninstall leaves only the externally managed database behind.
"${helm_bin}" uninstall "${release}" --namespace "${namespace}" --wait --timeout 5m
remaining=""
for _ in $(seq 1 30); do
  remaining="$("${kubectl_bin}" -n "${namespace}" get all,configmap,secret,pdb \
    -l "app.kubernetes.io/instance=${release}" -o name 2>/dev/null || true)"
  [[ -z "${remaining}" ]] && break
  sleep 2
done
if [[ -n "${remaining}" ]]; then
  printf 'helm uninstall left chart-owned resources behind:\n%s\n' "${remaining}" >&2
  exit 1
fi

if [[ "${created_cluster}" != "1" ]]; then
  "${kubectl_bin}" delete namespace "${namespace}" --wait=false >/dev/null
fi
echo "native helm chart kind smoke OK"
