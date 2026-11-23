#!/usr/bin/env bash
# Bootstrap the disposable MySQL fixture used by the native Helm install checks.
set -euo pipefail

namespace="$1"
release="$2"
native_image="$3"
kubectl_bin="${KUBECTL:-kubectl}"
work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT

run_schema_command() {
  local command="$1"
  "${kubectl_bin}" -n "${namespace}" run "${release}-${command}" \
    --image="${native_image}" --restart=Never --attach --rm --quiet --pod-running-timeout=120s \
    --env=DB_CONNECTION=mysql --env="DB_HOST=mysql.${namespace}.svc.cluster.local" \
    --env=DB_DATABASE=durable_workflow --env=DB_USERNAME=durable_workflow \
    --env=DB_PASSWORD=durable_workflow --command -- durable-workflow-server "${command}"
}

mysql_admin() {
  "${kubectl_bin}" -n "${namespace}" exec -i deploy/durable-workflow-mysql -- sh -ec '
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    exec mysql --protocol=TCP --host=127.0.0.1 --user=root --batch --skip-column-names
  '
}

# Apply required DDL through the database fixture, then verify as the scoped
# account. Keep the database's original binary-logging settings.
binlog_flags="$(mysql_admin <<< 'SELECT @@log_bin, @@log_bin_trust_function_creators')"
if ! run_schema_command schema-bootstrap >"${work_dir}/bootstrap.out" 2>"${work_dir}/bootstrap.err"; then
  if ! grep -Fxq native_child_checkpoint_trigger_privilege_required \
    "${work_dir}/bootstrap.out" "${work_dir}/bootstrap.err"; then
    cat "${work_dir}/bootstrap.out" "${work_dir}/bootstrap.err" >&2
    exit 1
  fi
  run_schema_command schema-checkpoint-ddl >"${work_dir}/checkpoint.sql"
  test -s "${work_dir}/checkpoint.sql"
  mysql_admin <"${work_dir}/checkpoint.sql"
  run_schema_command schema-bootstrap
  echo "PASS native checkpoint DDL applied by the database administrator and verified by the scoped account"
fi
[[ "$(mysql_admin <<< 'SELECT @@log_bin, @@log_bin_trust_function_creators')" == "${binlog_flags}" ]]
