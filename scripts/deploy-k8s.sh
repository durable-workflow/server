#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
k8s_dir="${repo_root}/k8s"
kubectl_bin="${KUBECTL:-kubectl}"
namespace="durable-workflow"
migration_timeout="${K8S_MIGRATION_TIMEOUT:-300s}"

require_secret() {
  local name="$1"

  if ! "${kubectl_bin}" -n "${namespace}" get secret "${name}" >/dev/null 2>&1; then
    echo "Missing required secret ${name} in namespace ${namespace}" >&2
    echo "Create it before deploying; see README.md Kubernetes instructions." >&2
    exit 1
  fi
}

echo "Applying namespace and shared configuration"
"${kubectl_bin}" apply -f "${k8s_dir}/namespace.yaml"
"${kubectl_bin}" apply -f "${k8s_dir}/secret.yaml"
require_secret durable-workflow-database

if "${kubectl_bin}" -n "${namespace}" get job durable-workflow-migrate >/dev/null 2>&1; then
  echo "Deleting previous migration job"
  "${kubectl_bin}" -n "${namespace}" delete job durable-workflow-migrate --wait=true
fi

echo "Running migration job"
"${kubectl_bin}" apply -f "${k8s_dir}/migration-job.yaml"
"${kubectl_bin}" -n "${namespace}" wait \
  --for=condition=complete \
  --timeout="${migration_timeout}" \
  job/durable-workflow-migrate

echo "Applying long-running workloads"
"${kubectl_bin}" apply -f "${k8s_dir}/server-pdb.yaml"
"${kubectl_bin}" apply -f "${k8s_dir}/server-deployment.yaml"
"${kubectl_bin}" apply -f "${k8s_dir}/worker-deployment.yaml"
"${kubectl_bin}" apply -f "${k8s_dir}/scheduler-cronjob.yaml"

echo "Deployment submitted"
