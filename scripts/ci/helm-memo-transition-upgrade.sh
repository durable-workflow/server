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
        while read -r pod; do
            if [[ -n "${pod}" ]]; then
                kubectl --namespace "${namespace}" patch "${pod}" \
                    --type merge \
                    --patch '{"metadata":{"finalizers":[]}}' >/dev/null 2>&1 || true
            fi
        done < <(kubectl --namespace "${namespace}" get pods \
            --output name 2>/dev/null || true)
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
          imagePullPolicy: Never
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
          imagePullPolicy: Never
---
apiVersion: batch/v1
kind: CronJob
metadata:
  name: memo-transition-scheduler
  labels:
    app.kubernetes.io/version: "2.0.0-rc.46"
spec:
  # Valid but impossible date; scheduler Jobs are started explicitly below.
  schedule: "0 0 31 2 *"
  suspend: false
  jobTemplate:
    metadata:
      labels:
        app.kubernetes.io/name: durable-workflow
        app.kubernetes.io/instance: memo-transition
        app.kubernetes.io/component: scheduler
    spec:
      template:
        metadata:
          labels:
            app.kubernetes.io/name: durable-workflow
            app.kubernetes.io/instance: memo-transition
            app.kubernetes.io/component: scheduler
        spec:
          restartPolicy: Never
          containers:
            - name: scheduler
              image: docker.io/durableworkflow/server:2.0.0-rc.46
              imagePullPolicy: Never
YAML

kubectl create namespace "${namespace}" >/dev/null
helm install "${release}" "${stub_chart}" --namespace "${namespace}" >/dev/null

set_running_state() {
    local image="$1"
    local replicas="$2"
    local scheduler_suspended="$3"
    local capability="${4:-}"

    kubectl --namespace "${namespace}" scale \
        deployment/memo-transition-server \
        deployment/memo-transition-worker \
        --replicas=0 >/dev/null
    kubectl --namespace "${namespace}" patch cronjob memo-transition-scheduler \
        --type merge \
        --patch '{"spec":{"suspend":true}}' >/dev/null
    kubectl --namespace "${namespace}" delete job \
        --selector 'app.kubernetes.io/name=durable-workflow,app.kubernetes.io/instance=memo-transition,app.kubernetes.io/component=scheduler' \
        --ignore-not-found \
        --wait=true >/dev/null
    kubectl --namespace "${namespace}" delete pod \
        --selector 'app.kubernetes.io/name=durable-workflow,app.kubernetes.io/instance=memo-transition' \
        --ignore-not-found \
        --grace-period=0 \
        --wait=true >/dev/null

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

wait_for_component_pod() {
    local component="$1"
    local expected_image="$2"
    local pod_name=""

    for _ in $(seq 1 60); do
        pod_name="$(kubectl --namespace "${namespace}" get pods \
            --selector "app.kubernetes.io/name=durable-workflow,app.kubernetes.io/instance=memo-transition,app.kubernetes.io/component=${component}" \
            --output 'jsonpath={range .items[*]}{.metadata.name}{"\t"}{.spec.containers[0].image}{"\n"}{end}' \
            | awk -v image="${expected_image}" '$2 == image { print $1; exit }')"
        if [[ -n "${pod_name}" ]]; then
            printf '%s\n' "${pod_name}"
            return
        fi
        sleep 1
    done

    printf 'timed out waiting for a %s pod using %s\n' "${component}" "${expected_image}" >&2
    exit 1
}

wait_for_deletion_timestamp() {
    local resource="$1"

    for _ in $(seq 1 60); do
        if [[ -n "$(kubectl --namespace "${namespace}" get "${resource}" --output 'jsonpath={.metadata.deletionTimestamp}')" ]]; then
            return
        fi
        sleep 1
    done

    printf 'timed out waiting for %s to begin terminating\n' "${resource}" >&2
    exit 1
}

adopt_job_by_scheduler() {
    local job_name="$1"
    local cronjob_uid

    cronjob_uid="$(kubectl --namespace "${namespace}" get cronjob memo-transition-scheduler \
        --output 'jsonpath={.metadata.uid}')"
    kubectl --namespace "${namespace}" patch job "${job_name}" \
        --type merge \
        --patch "{\"metadata\":{\"ownerReferences\":[{\"apiVersion\":\"batch/v1\",\"kind\":\"CronJob\",\"name\":\"memo-transition-scheduler\",\"uid\":\"${cronjob_uid}\",\"controller\":true}]}}" >/dev/null
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
wait_for_component_pod server "${official_rc46}" >/dev/null
expect_allowed 'actual rc.46 predecessor' "${successor_tag}"

set_running_state "${opaque_digest}" 1 false dual-v1
wait_for_component_pod worker "${opaque_digest}" >/dev/null
expect_allowed 'existing dual-v1 workload with an opaque image' "${successor_tag}"

set_running_state "${official_rc48}" 0 true
expect_allowed 'scaled-to-zero Deployments and suspended scheduler' "${successor_tag}"

set_running_state "${opaque_digest}" 1 false
expect_blocked 'active digest without an established capability' "${successor_tag}"

set_running_state 'registry.example.test/server:custom' 1 false
expect_blocked 'active custom tag without an established capability' "${successor_tag}"

set_running_state "${official_rc46}" 1 false
expect_blocked 'envelope-only target tag' '2.0.0-rc.48'
expect_allowed 'current official target tag' "${successor_tag}"
expect_blocked 'digest target without a capability declaration' \
    "${successor_tag}" 'sha256:2222222222222222222222222222222222222222222222222222222222222222'
expect_allowed 'digest target with a verified dual-v1 capability' \
    "${successor_tag}" 'sha256:2222222222222222222222222222222222222222222222222222222222222222' 'dual-v1'
expect_blocked 'custom target tag without a capability declaration' 'custom'
expect_allowed 'custom target tag with a verified raw-json-v1 capability' 'custom' '' 'raw-json-v1'
expect_blocked 'known envelope-only target with a contradictory declaration' \
    '2.0.0-rc.47' '' 'dual-v1'

# Prove desired state is not mistaken for quiescence. Hold a Deployment pod in
# Terminating with a finalizer and keep an execution owned by the suspended
# scheduler CronJob alive across the stop-the-world configuration changes.
set_running_state "${official_rc48}" 1 false
held_server_pod="$(wait_for_component_pod server "${official_rc48}")"
kubectl --namespace "${namespace}" patch pod "${held_server_pod}" \
    --type merge \
    --patch '{"metadata":{"finalizers":["workflows.durable-workflow.dev/memo-transition-hold"]}}' >/dev/null

held_scheduler_job='memo-transition-held-scheduler'
kubectl --namespace "${namespace}" create job "${held_scheduler_job}" \
    --from cronjob/memo-transition-scheduler >/dev/null
adopt_job_by_scheduler "${held_scheduler_job}"
held_scheduler_pod="$(wait_for_component_pod scheduler "${official_rc48}")"

kubectl --namespace "${namespace}" scale \
    deployment/memo-transition-server \
    deployment/memo-transition-worker \
    --replicas=0 >/dev/null
kubectl --namespace "${namespace}" patch cronjob memo-transition-scheduler \
    --type merge \
    --patch '{"spec":{"suspend":true}}' >/dev/null
wait_for_deletion_timestamp "pod/${held_server_pod}"
kubectl --namespace "${namespace}" delete pod \
    --selector 'app.kubernetes.io/name=durable-workflow,app.kubernetes.io/instance=memo-transition,app.kubernetes.io/component=worker' \
    --ignore-not-found \
    --grace-period=0 \
    --wait=true >/dev/null

expect_blocked 'terminating Deployment pod and in-flight scheduler execution after scale and suspend' \
    "${successor_tag}"

kubectl --namespace "${namespace}" patch pod "${held_server_pod}" \
    --type json \
    --patch '[{"op":"remove","path":"/metadata/finalizers"}]' >/dev/null
kubectl --namespace "${namespace}" wait \
    --for=delete "pod/${held_server_pod}" \
    --timeout=60s >/dev/null
expect_blocked 'in-flight scheduler execution after the terminating Deployment pod is gone' \
    "${successor_tag}"

kubectl --namespace "${namespace}" delete job "${held_scheduler_job}" \
    --cascade=orphan \
    --wait=true >/dev/null
expect_blocked 'scheduler pod orphaned during Job deletion' "${successor_tag}"

kubectl --namespace "${namespace}" delete pod "${held_scheduler_pod}" \
    --grace-period=0 \
    --wait=true >/dev/null
expect_allowed 'old Deployment pod and scheduler execution are deleted' "${successor_tag}"

# A completed scheduler execution may remain for history without holding the
# transition closed.
kubectl --namespace "${namespace}" set image \
    cronjob/memo-transition-scheduler scheduler='busybox:1.36.1' >/dev/null
kubectl --namespace "${namespace}" patch cronjob memo-transition-scheduler \
    --type strategic \
    --patch '{"spec":{"jobTemplate":{"spec":{"template":{"spec":{"containers":[{"name":"scheduler","image":"busybox:1.36.1","imagePullPolicy":"IfNotPresent","command":["/bin/sh","-c","exit 0"]}]}}}}}}' >/dev/null
terminal_scheduler_job='memo-transition-terminal-scheduler'
kubectl --namespace "${namespace}" create job "${terminal_scheduler_job}" \
    --from cronjob/memo-transition-scheduler >/dev/null
adopt_job_by_scheduler "${terminal_scheduler_job}"
kubectl --namespace "${namespace}" wait \
    --for=condition=complete "job/${terminal_scheduler_job}" \
    --timeout=120s >/dev/null
expect_allowed 'completed scheduler Job and succeeded pod retained for history' "${successor_tag}"
