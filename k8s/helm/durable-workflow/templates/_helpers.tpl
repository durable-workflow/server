{{/*
Expand the name of the chart.
*/}}
{{- define "durable-workflow.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/*
Create a default fully qualified app name. Truncated at 63 chars per DNS-1123.
*/}}
{{- define "durable-workflow.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "durable-workflow.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/*
Common labels applied to every resource.
*/}}
{{- define "durable-workflow.labels" -}}
helm.sh/chart: {{ include "durable-workflow.chart" . }}
{{ include "durable-workflow.selectorLabels" . }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/part-of: durable-workflow
{{- with .Values.commonLabels }}
{{ toYaml . }}
{{- end }}
{{- end -}}

{{/*
Selector labels — stable across upgrades.
*/}}
{{- define "durable-workflow.selectorLabels" -}}
app.kubernetes.io/name: {{ include "durable-workflow.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{/*
Component-scoped names. Pass a dict {"context": ., "component": "server"}.
*/}}
{{- define "durable-workflow.componentName" -}}
{{- $ctx := .context -}}
{{- printf "%s-%s" (include "durable-workflow.fullname" $ctx) .component | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "durable-workflow.componentLabels" -}}
{{- $ctx := .context -}}
{{ include "durable-workflow.labels" $ctx }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "durable-workflow.componentSelectorLabels" -}}
{{- $ctx := .context -}}
{{ include "durable-workflow.selectorLabels" $ctx }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{/*
Fail closed before an envelope-only Server revision can remain active while
the dual memo representation is installed. The raw-JSON predecessor and any
deployment already carrying the dual-v1 marker are rolling-compatible.
*/}}
{{- define "durable-workflow.validateMemoPayloadTransition" -}}
{{- if .Release.IsUpgrade -}}
{{- $namespace := .Release.Namespace -}}
{{- $server := lookup "apps/v1" "Deployment" $namespace (include "durable-workflow.serverDeploymentName" .) -}}
{{- $worker := lookup "apps/v1" "Deployment" $namespace (include "durable-workflow.workerDeploymentName" .) -}}
{{- $scheduler := lookup "batch/v1" "CronJob" $namespace (include "durable-workflow.schedulerCronJobName" .) -}}
{{- range $workload := list $server $worker -}}
{{- if $workload -}}
{{- $replicas := 1 -}}
{{- if hasKey $workload.spec "replicas" -}}
{{- $replicas = int (get $workload.spec "replicas") -}}
{{- end -}}
{{- $labels := default (dict) $workload.metadata.labels -}}
{{- $annotations := default (dict) $workload.metadata.annotations -}}
{{- $version := default "unknown" (get $labels "app.kubernetes.io/version") -}}
{{- $storage := default "" (get $annotations "workflows.durable-workflow.dev/memo-payload-storage") -}}
{{- if and (gt $replicas 0) (ne $version "2.0.0-rc.46") (ne $storage "dual-v1") -}}
{{- fail (printf "memo payload transition cannot run while Server %s workload %s has %d replicas. Scale the Server and worker Deployments to zero and suspend the scheduler CronJob before retrying this upgrade; Server 2.0.0-rc.47 and rc.48 cannot coexist with the dual representation." $version $workload.metadata.name $replicas) -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- if $scheduler -}}
{{- $labels := default (dict) $scheduler.metadata.labels -}}
{{- $annotations := default (dict) $scheduler.metadata.annotations -}}
{{- $version := default "unknown" (get $labels "app.kubernetes.io/version") -}}
{{- $storage := default "" (get $annotations "workflows.durable-workflow.dev/memo-payload-storage") -}}
{{- if and (not $scheduler.spec.suspend) (ne $version "2.0.0-rc.46") (ne $storage "dual-v1") -}}
{{- fail (printf "memo payload transition cannot run while Server %s scheduler CronJob %s is active. Suspend it and scale the Server and worker Deployments to zero before retrying this upgrade." $version $scheduler.metadata.name) -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{/*
Image reference. Prefers digest over tag; tag falls back to .Chart.AppVersion.
*/}}
{{- define "durable-workflow.image" -}}
{{- $registry := .Values.image.registry | default "docker.io" -}}
{{- $repo := .Values.image.repository -}}
{{- if .Values.image.digest -}}
{{- printf "%s/%s@%s" $registry $repo .Values.image.digest -}}
{{- else -}}
{{- $tag := .Values.image.tag | default .Chart.AppVersion -}}
{{- printf "%s/%s:%s" $registry $repo $tag -}}
{{- end -}}
{{- end -}}

{{/*
ServiceAccount name resolver.
*/}}
{{- define "durable-workflow.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "durable-workflow.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{/*
Whether the bootstrap Job runs before regular chart resources exist.
*/}}
{{- define "durable-workflow.bootstrapRunsBeforeResources" -}}
{{- if and .Values.bootstrap.enabled (or .Values.bootstrap.useHelmHooks .Values.argocd.useSyncWaves) -}}true{{- end -}}
{{- end -}}

{{/*
Bootstrap Job service account. When the chart creates the workload service
account but the bootstrap Job runs earlier via hooks or sync waves, use the
namespace default service account for the bootstrap Job instead.
*/}}
{{- define "durable-workflow.bootstrapServiceAccountName" -}}
{{- if and .Values.serviceAccount.create (eq (include "durable-workflow.bootstrapRunsBeforeResources" .) "true") -}}
default
{{- else -}}
{{- include "durable-workflow.serviceAccountName" . -}}
{{- end }}
{{- end -}}

{{/*
Resource names. Centralised so app code, hooks, and docs stay aligned.
*/}}
{{- define "durable-workflow.configMapName" -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "config") }}
{{- end -}}

{{- define "durable-workflow.appSecretName" -}}
{{- if .Values.auth.existingSecret -}}
{{- .Values.auth.existingSecret -}}
{{- else -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "app-secrets") }}
{{- end -}}
{{- end -}}

{{- define "durable-workflow.databaseSecretName" -}}
{{- if .Values.externalDatabase.existingSecret -}}
{{- .Values.externalDatabase.existingSecret -}}
{{- else -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "database") }}
{{- end -}}
{{- end -}}

{{- define "durable-workflow.redisSecretName" -}}
{{- if .Values.externalRedis.existingSecret -}}
{{- .Values.externalRedis.existingSecret -}}
{{- else -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "redis") }}
{{- end -}}
{{- end -}}

{{- define "durable-workflow.bootstrapJobName" -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "migrate") }}
{{- end -}}

{{- define "durable-workflow.serverDeploymentName" -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "server") }}
{{- end -}}

{{- define "durable-workflow.workerDeploymentName" -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "worker") }}
{{- end -}}

{{- define "durable-workflow.schedulerCronJobName" -}}
{{ include "durable-workflow.componentName" (dict "context" . "component" "scheduler") }}
{{- end -}}

{{/*
Validation: required external persistence settings.
The chart deliberately refuses to render if these are missing — bundling a
default in-cluster database would silently break the externals-first contract.
*/}}
{{- define "durable-workflow.validateExternalPersistence" -}}
{{- if not .Values.externalDatabase.host -}}
{{- fail "externalDatabase.host is required. The chart does not bundle a database; point this at your managed MySQL/PostgreSQL endpoint. See k8s/helm/durable-workflow/README.md." -}}
{{- end -}}
{{- if not .Values.externalRedis.host -}}
{{- fail "externalRedis.host is required for the multi-node correctness contract. Point this at your managed Redis (ElastiCache, Memorystore, Sentinel, etc.). See k8s/helm/durable-workflow/README.md." -}}
{{- end -}}
{{- end -}}

{{/*
Standard env block consumed by every workload. Wires:
  - the shared ConfigMap (all non-secret config)
  - the app secret (server key, role-scoped tokens) — existing or chart-rendered
  - the database secret (DB_USERNAME, DB_PASSWORD)
  - the Redis secret (optional — keys flagged optional so anonymous Redis works)
*/}}
{{- define "durable-workflow.standardEnvFrom" -}}
- configMapRef:
    name: {{ include "durable-workflow.configMapName" . }}
- secretRef:
    name: {{ include "durable-workflow.appSecretName" . }}
{{- end -}}

{{- define "durable-workflow.standardEnv" -}}
- name: DB_USERNAME
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.databaseSecretName" . }}
      key: {{ .Values.externalDatabase.existingSecretUsernameKey | default "DB_USERNAME" }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.databaseSecretName" . }}
      key: {{ .Values.externalDatabase.existingSecretPasswordKey | default "DB_PASSWORD" }}
- name: REDIS_USERNAME
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.redisSecretName" . }}
      key: {{ .Values.externalRedis.existingSecretUsernameKey | default "REDIS_USERNAME" }}
      optional: true
- name: REDIS_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.redisSecretName" . }}
      key: {{ .Values.externalRedis.existingSecretPasswordKey | default "REDIS_PASSWORD" }}
      optional: true
{{- end -}}

{{/*
Bootstrap hook envFrom block. Existing Secrets are assumed to be managed out of band.
*/}}
{{- define "durable-workflow.bootstrapEnvFrom" -}}
{{- if eq (include "durable-workflow.bootstrapRunsBeforeResources" .) "true" }}
{{- if .Values.auth.existingSecret }}
- secretRef:
    name: {{ include "durable-workflow.appSecretName" . }}
{{- end }}
{{- else }}
{{- include "durable-workflow.standardEnvFrom" . }}
{{- end }}
{{- end -}}

{{/*
Bootstrap hook env block. Inline chart-managed config/secrets so the Job does
not depend on regular resources Helm or Argo create afterward.
*/}}
{{- define "durable-workflow.bootstrapEnv" -}}
{{- if eq (include "durable-workflow.bootstrapRunsBeforeResources" .) "true" }}
- name: APP_NAME
  value: {{ .Values.config.appName | quote }}
- name: APP_VERSION
  value: {{ default .Chart.AppVersion .Values.config.appVersion | quote }}
- name: APP_ENV
  value: {{ .Values.config.appEnv | quote }}
- name: APP_DEBUG
  value: {{ .Values.config.appDebug | quote }}
- name: DB_CONNECTION
  value: {{ .Values.externalDatabase.connection | quote }}
- name: DB_HOST
  value: {{ .Values.externalDatabase.host | quote }}
- name: DB_PORT
  value: {{ .Values.externalDatabase.port | quote }}
- name: DB_DATABASE
  value: {{ .Values.externalDatabase.database | quote }}
{{- with .Values.externalDatabase.socket }}
- name: DB_SOCKET
  value: {{ . | quote }}
{{- end }}
{{- with .Values.externalDatabase.sslMode }}
- name: DB_SSL_MODE
  value: {{ . | quote }}
{{- end }}
- name: REDIS_HOST
  value: {{ .Values.externalRedis.host | quote }}
- name: REDIS_PORT
  value: {{ .Values.externalRedis.port | quote }}
- name: REDIS_DB
  value: {{ .Values.externalRedis.database | quote }}
{{- if .Values.externalRedis.tls }}
- name: REDIS_SCHEME
  value: "tls"
{{- end }}
- name: QUEUE_CONNECTION
  value: "redis"
- name: CACHE_STORE
  value: "redis"
- name: DW_AUTH_DRIVER
  value: {{ .Values.config.auth.driver | quote }}
- name: DW_AUTH_BACKWARD_COMPATIBLE
  value: {{ .Values.config.auth.backwardCompatible | quote }}
- name: DW_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT
  value: {{ .Values.config.metrics.workflowTaskFailureTypeLimit | quote }}
{{- range $k, $v := .Values.config.extraEnv }}
- name: {{ $k }}
  value: {{ $v | quote }}
{{- end }}
{{- if not .Values.auth.existingSecret }}
{{- if .Values.auth.serverKey }}
- name: DW_SERVER_KEY
  value: {{ .Values.auth.serverKey | quote }}
{{- end }}
{{- if .Values.auth.authToken }}
- name: DW_AUTH_TOKEN
  value: {{ .Values.auth.authToken | quote }}
{{- end }}
{{- if .Values.auth.principalTokens }}
- name: DW_PRINCIPAL_TOKENS
  value: {{ .Values.auth.principalTokens | quote }}
{{- end }}
{{- if .Values.auth.workerToken }}
- name: DW_WORKER_TOKEN
  value: {{ .Values.auth.workerToken | quote }}
{{- end }}
{{- if .Values.auth.operatorToken }}
- name: DW_OPERATOR_TOKEN
  value: {{ .Values.auth.operatorToken | quote }}
{{- end }}
{{- if .Values.auth.adminToken }}
- name: DW_ADMIN_TOKEN
  value: {{ .Values.auth.adminToken | quote }}
{{- end }}
{{- range $k, $v := .Values.auth.extra }}
- name: {{ $k }}
  value: {{ $v | quote }}
{{- end }}
{{- end }}
{{- if .Values.externalDatabase.existingSecret }}
- name: DB_USERNAME
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.databaseSecretName" . }}
      key: {{ .Values.externalDatabase.existingSecretUsernameKey | default "DB_USERNAME" }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.databaseSecretName" . }}
      key: {{ .Values.externalDatabase.existingSecretPasswordKey | default "DB_PASSWORD" }}
{{- else }}
- name: DB_USERNAME
  value: {{ required "externalDatabase.auth.username is required when externalDatabase.existingSecret is empty" .Values.externalDatabase.auth.username | quote }}
- name: DB_PASSWORD
  value: {{ required "externalDatabase.auth.password is required when externalDatabase.existingSecret is empty" .Values.externalDatabase.auth.password | quote }}
{{- end }}
{{- if .Values.externalRedis.existingSecret }}
- name: REDIS_USERNAME
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.redisSecretName" . }}
      key: {{ .Values.externalRedis.existingSecretUsernameKey | default "REDIS_USERNAME" }}
      optional: true
- name: REDIS_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "durable-workflow.redisSecretName" . }}
      key: {{ .Values.externalRedis.existingSecretPasswordKey | default "REDIS_PASSWORD" }}
      optional: true
{{- else }}
{{- with .Values.externalRedis.auth.username }}
- name: REDIS_USERNAME
  value: {{ . | quote }}
{{- end }}
{{- with .Values.externalRedis.auth.password }}
- name: REDIS_PASSWORD
  value: {{ . | quote }}
{{- end }}
{{- end }}
{{- else }}
{{- include "durable-workflow.standardEnv" . }}
{{- end }}
{{- end -}}

{{/*
ConfigMap-backed annotation that triggers a rolling restart when config or
secret content changes (preserves the multi-node "every node uses the same
auth tokens, app version, ... " invariant during upgrades).
*/}}
{{- define "durable-workflow.configChecksumAnnotations" -}}
checksum/config: {{ include (print $.Template.BasePath "/configmap.yaml") . | sha256sum }}
{{- if not .Values.auth.existingSecret }}
checksum/app-secret: {{ include (print $.Template.BasePath "/secret-app.yaml") . | sha256sum }}
{{- end }}
{{- end -}}

{{/*
Argo CD sync-wave annotations. Empty when argocd.useSyncWaves is false.
Bootstrap Jobs render with the lower wave so workloads wait on them.
*/}}
{{- define "durable-workflow.argoBootstrapAnnotations" -}}
{{- if .Values.argocd.useSyncWaves }}
argocd.argoproj.io/sync-wave: {{ .Values.argocd.bootstrapWave | quote }}
argocd.argoproj.io/hook: Sync
argocd.argoproj.io/hook-delete-policy: BeforeHookCreation
{{- end }}
{{- end -}}

{{- define "durable-workflow.argoWorkloadAnnotations" -}}
{{- if .Values.argocd.useSyncWaves }}
argocd.argoproj.io/sync-wave: {{ .Values.argocd.workloadWave | quote }}
{{- end }}
{{- end -}}

{{- define "durable-workflow.fluxDependsOnAnnotation" -}}
{{- if .Values.flux.useDependsOn }}
kustomize.toolkit.fluxcd.io/depends-on: {{ printf "%s/%s" .Release.Namespace (include "durable-workflow.bootstrapJobName" .) | quote }}
{{- end }}
{{- end -}}
