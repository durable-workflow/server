#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
namespace="${K8S_SMOKE_NAMESPACE:-durable-workflow}"
cluster="${K8S_SMOKE_CLUSTER:-durable-workflow-server-smoke}"
image="${K8S_SMOKE_IMAGE:-durableworkflow/server:k8s-smoke}"
kind_node_image="${K8S_SMOKE_KIND_NODE_IMAGE:-kindest/node:v1.29.4}"
artifact_dir="${K8S_SMOKE_ARTIFACT_DIR:-/tmp/durable-workflow-k8s-kind-smoke-artifacts}"
rendered_dir="${artifact_dir}/rendered-manifests"
port="${K8S_SMOKE_PORT:-18080}"
kubectl_bin="${KUBECTL:-kubectl}"
kind_bin="${KIND:-kind}"
docker_bin="${DOCKER:-docker}"

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
  cp -R "${rendered_dir}" "${artifact_dir}/rendered-manifests-final" 2>/dev/null || true

  "${kubectl_bin}" -n "${namespace}" get all,configmap,pdb -o wide >"${artifact_dir}/resources.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" describe all >"${artifact_dir}/describe.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" get events --sort-by=.lastTimestamp >"${artifact_dir}/events.txt" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs job/durable-workflow-migrate --all-containers=true >"${artifact_dir}/migration-job.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-server --all-containers=true >"${artifact_dir}/server.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-worker --all-containers=true >"${artifact_dir}/worker.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-mysql --all-containers=true >"${artifact_dir}/mysql.log" 2>&1 || true
  "${kubectl_bin}" -n "${namespace}" logs deploy/durable-workflow-redis --all-containers=true >"${artifact_dir}/redis.log" 2>&1 || true
}

cleanup() {
  local status="$?"

  if [ -n "${port_forward_pid}" ]; then
    kill "${port_forward_pid}" >/dev/null 2>&1 || true
  fi

  if [ "${status}" -ne 0 ]; then
    collect_artifacts
  fi

  if [ "${created_cluster}" -eq 1 ] && [ "${KEEP_KIND_CLUSTER:-0}" != "1" ]; then
    "${kind_bin}" delete cluster --name "${cluster}" >/dev/null 2>&1 || true
  fi

  exit "${status}"
}

render_manifests() {
  rm -rf "${artifact_dir}"
  mkdir -p "${rendered_dir}"
  cp "${repo_root}"/k8s/*.yaml "${rendered_dir}/"

  find "${rendered_dir}" -name '*.yaml' -print0 \
    | xargs -0 sed -i "s#durableworkflow/server:0.2#${image}#g"
}

apply_smoke_dependencies() {
  cat <<'YAML' | "${kubectl_bin}" apply -f -
apiVersion: v1
kind: Service
metadata:
  name: mysql
  namespace: durable-workflow
spec:
  selector:
    app.kubernetes.io/name: durable-workflow-mysql
  ports:
    - name: mysql
      port: 3306
      targetPort: mysql
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: durable-workflow-mysql
  namespace: durable-workflow
spec:
  selector:
    matchLabels:
      app.kubernetes.io/name: durable-workflow-mysql
  template:
    metadata:
      labels:
        app.kubernetes.io/name: durable-workflow-mysql
    spec:
      containers:
        - name: mysql
          image: mysql:8.4
          ports:
            - name: mysql
              containerPort: 3306
          env:
            - name: MYSQL_DATABASE
              value: durable_workflow
            - name: MYSQL_USER
              valueFrom:
                secretKeyRef:
                  name: durable-workflow-database
                  key: DB_USERNAME
            - name: MYSQL_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: durable-workflow-database
                  key: DB_PASSWORD
            - name: MYSQL_ROOT_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: durable-workflow-database
                  key: DB_PASSWORD
          readinessProbe:
            exec:
              command: ["sh", "-c", "mysqladmin ping -h 127.0.0.1 -u$MYSQL_USER -p$MYSQL_PASSWORD --silent"]
            initialDelaySeconds: 5
            periodSeconds: 5
          resources:
            requests:
              cpu: 250m
              memory: 512Mi
            limits:
              cpu: "1"
              memory: 1Gi
---
apiVersion: v1
kind: Service
metadata:
  name: redis
  namespace: durable-workflow
spec:
  selector:
    app.kubernetes.io/name: durable-workflow-redis
  ports:
    - name: redis
      port: 6379
      targetPort: redis
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: durable-workflow-redis
  namespace: durable-workflow
spec:
  selector:
    matchLabels:
      app.kubernetes.io/name: durable-workflow-redis
  template:
    metadata:
      labels:
        app.kubernetes.io/name: durable-workflow-redis
    spec:
      containers:
        - name: redis
          image: redis:7-alpine
          ports:
            - name: redis
              containerPort: 6379
          readinessProbe:
            exec:
              command: ["redis-cli", "ping"]
            initialDelaySeconds: 3
            periodSeconds: 5
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 250m
              memory: 256Mi
YAML
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
  "${kubectl_bin}" wait --for=condition=Ready nodes --all --timeout=30s
}

trap cleanup EXIT

require_bin "${docker_bin}"
require_bin "${kind_bin}"
require_bin "${kubectl_bin}"
require_bin curl
require_bin openssl

render_manifests

"${docker_bin}" build -t "${image}" "${repo_root}"

if ! "${kind_bin}" get clusters | grep -Fxq "${cluster}"; then
  "${kind_bin}" create cluster --name "${cluster}" --image "${kind_node_image}" --wait 120s
  created_cluster=1
fi

"${kind_bin}" load docker-image "${image}" --name "${cluster}"
wait_for_kubernetes_api

"${kubectl_bin}" apply -f "${rendered_dir}/namespace.yaml"
"${kubectl_bin}" apply -f "${rendered_dir}/secret.yaml"

app_key="base64:$(openssl rand -base64 32)"
"${kubectl_bin}" -n "${namespace}" create secret generic durable-workflow-app-secrets \
  --from-literal="DW_SERVER_KEY=${app_key}" \
  --from-literal=DW_AUTH_TOKEN=kind-auth-token \
  --from-literal=DW_WORKER_TOKEN=kind-worker-token \
  --from-literal=DW_OPERATOR_TOKEN=kind-operator-token \
  --from-literal=DW_ADMIN_TOKEN=kind-admin-token \
  --dry-run=client -o yaml | "${kubectl_bin}" apply -f -

"${kubectl_bin}" -n "${namespace}" create secret generic durable-workflow-database \
  --from-literal=DB_USERNAME=durable_workflow \
  --from-literal=DB_PASSWORD=durable_workflow \
  --dry-run=client -o yaml | "${kubectl_bin}" apply -f -

"${kubectl_bin}" -n "${namespace}" create secret generic durable-workflow-redis \
  --from-literal=REDIS_USERNAME= \
  --from-literal=REDIS_PASSWORD= \
  --dry-run=client -o yaml | "${kubectl_bin}" apply -f -

apply_smoke_dependencies
"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-mysql --timeout=180s
"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-redis --timeout=120s

"${kubectl_bin}" apply -f "${rendered_dir}/migration-job.yaml"
"${kubectl_bin}" -n "${namespace}" wait --for=condition=complete --timeout=300s job/durable-workflow-migrate

"${kubectl_bin}" apply -f "${rendered_dir}/server-pdb.yaml"
"${kubectl_bin}" apply -f "${rendered_dir}/server-deployment.yaml"
"${kubectl_bin}" apply -f "${rendered_dir}/worker-deployment.yaml"
"${kubectl_bin}" apply -f "${rendered_dir}/scheduler-cronjob.yaml"

"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-server --timeout=180s
"${kubectl_bin}" -n "${namespace}" rollout status deploy/durable-workflow-worker --timeout=180s

"${kubectl_bin}" -n "${namespace}" port-forward svc/durable-workflow-server "${port}:8080" >"${artifact_dir}/port-forward.log" 2>&1 &
port_forward_pid="$!"
sleep 2

wait_for_http "http://127.0.0.1:${port}/api/ready"
wait_for_http "http://127.0.0.1:${port}/api/cluster/info" \
  -H "Authorization: Bearer kind-worker-token" \
  -H "X-Namespace: default"

curl -fsS -X POST "http://127.0.0.1:${port}/api/worker/register" \
  -H "Authorization: Bearer kind-worker-token" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -H "Content-Type: application/json" \
  --data '{"worker_id":"kind-smoke-worker","task_queue":"default","runtime":"python"}' \
  | tee "${artifact_dir}/worker-register.json" >/dev/null

collect_artifacts
