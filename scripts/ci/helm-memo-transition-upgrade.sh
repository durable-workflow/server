#!/usr/bin/env bash

set -euo pipefail

chart_path="${HELM_MEMO_TRANSITION_CHART_PATH:-k8s/helm/durable-workflow}"
namespace="${HELM_MEMO_TRANSITION_NAMESPACE:-dw-memo-transition}"
release="memo-transition"
fixture="${chart_path}/ci/inline-secrets-values.yaml"
storage_annotation="workflows.durable-workflow.dev/memo-payload-storage"
temporary_directory="$(mktemp -d)"
stub_chart="${temporary_directory}/stub"
successor_tag="$(sed -nE 's/^appVersion:[[:space:]]*"([^"]+)"/\1/p' "${chart_path}/Chart.yaml")"

if [[ -z "${successor_tag}" ]]; then
    printf 'could not resolve the successor image tag from %s/Chart.yaml\n' "${chart_path}" >&2
    exit 1
fi

cleanup() {
    if command -v kubectl >/dev/null 2>&1; then
        kubectl delete namespace "${namespace}" --ignore-not-found --wait=false >/dev/null
    fi
    rm -rf "${temporary_directory}"
}
trap cleanup EXIT

for command in helm kubectl; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        printf 'required command is unavailable: %s\n' "${command}" >&2
        exit 1
    fi
done

mkdir -p "${stub_chart}/templates"

cat > "${stub_chart}/Chart.yaml" <<'YAML'
apiVersion: v2
name: memo-transition-stub
version: 0.1.0
YAML

cat > "${stub_chart}/templates/workloads.yaml" <<'YAML'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: memo-transition-server
  labels:
    app.kubernetes.io/version: "2.0.0-rc.46"
spec:
  replicas: 1
  selector:
    matchLabels:
      app.kubernetes.io/name: durable-workflow
      app.kubernetes.io/instance: memo-transition
      app.kubernetes.io/component: server
  template:
    metadata:
      labels:
        app.kubernetes.io/name: durable-workflow
        app.kubernetes.io/instance: memo-transition
        app.kubernetes.io/component: server
    spec:
      containers:
        - name: server
          image: docker.io/durableworkflow/server:2.0.0-rc.46
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: memo-transition-worker
  labels:
    app.kubernetes.io/version: "2.0.0-rc.46"
spec:
  replicas: 1
  selector:
    matchLabels:
      app.kubernetes.io/name: durable-workflow
      app.kubernetes.io/instance: memo-transition
      app.kubernetes.io/component: worker
  template:
    metadata:
      labels:
        app.kubernetes.io/name: durable-workflow
        app.kubernetes.io/instance: memo-transition
        app.kubernetes.io/component: worker
    spec:
      containers:
        - name: worker
          image: docker.io/durableworkflow/server:2.0.0-rc.46
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: memo-transition-scheduler
  labels:
    app.kubernetes.io/version: "2.0.0-rc.46"
spec:
  schedule: "0 * * * *"
  suspend: false
  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: Never
          containers:
            - name: scheduler
              image: docker.io/durableworkflow/server:2.0.0-rc.46
YAML

kubectl create namespace "${namespace}" >/dev/null
helm install "${release}" "${stub_chart}" --namespace "${namespace}" >/dev/null

set_running_state() {
    local image="$1"
    local replicas="$2"
    local scheduler_suspended="$3"
    local capability="${4:-}"

    kubectl --namespace "${namespace}" set image \
        deployment/memo-transition-server server="${image}" >/dev/null
    kubectl --namespace "${namespace}" set image \
        deployment/memo-transition-worker worker="${image}" >/dev/null
    kubectl --namespace "${namespace}" set image \
        cronjob/memo-transition-scheduler scheduler="${image}" >/dev/null
    kubectl --namespace "${namespace}" scale \
        deployment/memo-transition-server \
        deployment/memo-transition-worker \
        --replicas="${replicas}" >/dev/null
    kubectl --namespace "${namespace}" patch cronjob memo-transition-scheduler \
        --type merge \
        --patch "{\"spec\":{\"suspend\":${scheduler_suspended}}}" >/dev/null

    for resource in \
        deployment/memo-transition-server \
        deployment/memo-transition-worker \
        cronjob/memo-transition-scheduler; do
        kubectl --namespace "${namespace}" annotate "${resource}" \
            "${storage_annotation}-" >/dev/null 2>&1 || true
        if [[ -n "${capability}" ]]; then
            kubectl --namespace "${namespace}" annotate "${resource}" \
                "${storage_annotation}=${capability}" --overwrite >/dev/null
        fi
    done
}

run_upgrade() {
    local target_tag="$1"
    local target_digest="${2:-}"
    local target_capability="${3:-}"

    helm upgrade "${release}" "${chart_path}" \
        --namespace "${namespace}" \
        --dry-run=server \
        --values "${fixture}" \
        --set-string fullnameOverride=memo-transition \
        --set-string image.tag="${target_tag}" \
        --set-string image.digest="${target_digest}" \
        --set-string image.memoPayloadStorage="${target_capability}"
}

expect_allowed() {
    local scenario="$1"
    local output
    shift

    if ! output="$(run_upgrade "$@" 2>&1)"; then
        printf 'expected Helm upgrade scenario to pass: %s\n%s\n' "${scenario}" "${output}" >&2
        exit 1
    fi
    printf 'PASS allowed: %s\n' "${scenario}"
}

expect_blocked() {
    local scenario="$1"
    local output
    shift

    if output="$(run_upgrade "$@" 2>&1)"; then
        printf 'expected Helm upgrade scenario to be blocked: %s\n%s\n' "${scenario}" "${output}" >&2
        exit 1
    fi
    if ! grep -Fq 'memo payload transition cannot' <<<"${output}"; then
        printf 'scenario failed outside the memo-transition guard: %s\n%s\n' "${scenario}" "${output}" >&2
        exit 1
    fi
    printf 'PASS blocked: %s\n' "${scenario}"
}

official_rc46="docker.io/durableworkflow/server:2.0.0-rc.46"
official_rc47="docker.io/durableworkflow/server:2.0.0-rc.47"
official_rc48="docker.io/durableworkflow/server:2.0.0-rc.48"
opaque_digest="docker.io/durableworkflow/server@sha256:1111111111111111111111111111111111111111111111111111111111111111"

set_running_state "${official_rc47}" 1 false
expect_blocked 'rc.46 chart label with an rc.47 running image' "${successor_tag}"

set_running_state "${official_rc47}" 1 false dual-v1
expect_blocked 'rc.47 running image with a stale dual-v1 marker' "${successor_tag}"

set_running_state "${official_rc48}" 1 false
expect_blocked 'rc.46 chart label with an rc.48 running image' "${successor_tag}"

set_running_state "${official_rc46}" 1 false
expect_allowed 'actual rc.46 predecessor' "${successor_tag}"

set_running_state "${opaque_digest}" 1 false dual-v1
expect_allowed 'existing dual-v1 workload with an opaque image' "${successor_tag}"

set_running_state "${official_rc48}" 0 true
expect_allowed 'scaled-to-zero Deployments and suspended scheduler' "${successor_tag}"

set_running_state "${opaque_digest}" 1 false
expect_blocked 'active digest without an established capability' "${successor_tag}"

set_running_state 'registry.example.test/server:custom' 1 false
expect_blocked 'active custom tag without an established capability' "${successor_tag}"

set_running_state "${official_rc46}" 1 false
expect_blocked 'envelope-only target tag' '2.0.0-rc.48'
expect_allowed 'official stable 2.0.0 target tag' '2.0.0'
expect_blocked 'digest target without a capability declaration' \
    "${successor_tag}" 'sha256:2222222222222222222222222222222222222222222222222222222222222222'
expect_allowed 'digest target with a verified dual-v1 capability' \
    "${successor_tag}" 'sha256:2222222222222222222222222222222222222222222222222222222222222222' 'dual-v1'
expect_blocked 'custom target tag without a capability declaration' 'custom'
expect_allowed 'custom target tag with a verified raw-json-v1 capability' 'custom' '' 'raw-json-v1'
expect_blocked 'known envelope-only target with a contradictory declaration' \
    '2.0.0-rc.47' '' 'dual-v1'
