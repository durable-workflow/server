# Durable Workflow Helm Chart

This chart deploys a self-hosted Durable Workflow Server onto Kubernetes. It
is the self-serve Helm path for the deployment matrix at
<https://durable-workflow.github.io/docs/2.0/deployment>.

The chart runs one of two Server runtimes, selected by the `runtime` value:

* **native** (the default for new installs) runs the native 3.x Server. Each
  pod is one process that serves the API, checks its database schema at
  startup and runs timers, repair, retention and schedules itself. The chart
  renders one server Deployment and no worker, scheduler, migration Job or
  Redis.
* **php** keeps an existing PHP 2.x installation running: a server
  Deployment, a queue worker Deployment, a singleton scheduler CronJob and a
  bootstrap Job, with Redis. PHP Server 2.x is frozen at 2.11.8 and gets no
  further releases. Move to native when you can (see
  [docs/UPGRADING.md](docs/UPGRADING.md)).

The chart is engine-aware: the templates encode the engine invariants from
[workflow/docs/deployment/multi-node-requirements.md](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/multi-node-requirements.md)
and the failover guarantees from
[server/docs/ha-failover-validation.md](../../docs/ha-failover-validation.md)
(readiness on `/api/ready`, externals-first persistence, no in-cluster
databases bundled, and in PHP mode a singleton scheduler). Override-with-care
knobs that would break those guarantees are flagged in `values.yaml`.

## Status

* Chart version: see `Chart.yaml` (semver — independent of the Server image
  version, which is `appVersion`).
* Chart distribution: published as an OCI artifact at
  `oci://ghcr.io/durable-workflow/charts/durable-workflow`.
* Stability: **beta** — the externals-first contract, the runtime selection,
  the singleton scheduler invariant in PHP mode, secret layout, and resource
  naming are committed to. Any breaking change to those will bump the chart's
  MAJOR version and ship migration steps in [docs/UPGRADING.md](docs/UPGRADING.md).

## Prerequisites

| Requirement | Notes |
| --- | --- |
| Kubernetes ≥ 1.27 | Older versions are not validated; the chart uses `policy/v1` PDB and `autoscaling/v2` HPA. |
| Helm ≥ 3.10 | Required for OCI install and the `lookup` builtin. |
| External MySQL/MariaDB/PostgreSQL | The chart does not bundle a database. Native creates its schema in an empty database on first start. The database itself must already exist. |
| External Redis (or compatible) | PHP mode only. Required for the PHP multi-node correctness contract. Native does not use Redis. |
| Inbound TLS / ingress | Provided by your platform. The chart can render an `Ingress` but does not terminate TLS itself. |

## Install

The chart will refuse to render until you point it at an external database
(and, in PHP mode, Redis) — that is intentional, not a bug. Native mode also
refuses to render without a shared `DW_AUTH_TOKEN`, as the native Server
refuses to start without one.

### From the OCI registry (recommended for production)

```bash
helm install durable-workflow \
  oci://ghcr.io/durable-workflow/charts/durable-workflow \
  --namespace durable-workflow --create-namespace \
  -f my-values.yaml
```

Add `--version <validated-chart-version>` for reproducible production
installs. Chart releases and upgrade notes live with this chart source.

### From a local checkout (for development)

```bash
helm install durable-workflow ./k8s/helm/durable-workflow \
  --namespace durable-workflow --create-namespace \
  -f my-values.yaml
```

## Minimum production values

```yaml
image:
  tag: "3.0.1"
  # Pin a digest in production:
  # digest: "sha256:abc123..."

externalDatabase:
  connection: pgsql
  host: workflow-db.example.internal
  port: 5432
  database: durable_workflow
  existingSecret: durable-workflow-db-credentials

auth:
  existingSecret: durable-workflow-app-credentials

native:
  storage:
    # ReadWriteMany. Required for more than one replica.
    existingClaim: durable-workflow-payloads

server:
  replicaCount: 3
  ingress:
    enabled: true
    className: nginx
    hosts:
      - host: workflow.example.com
        paths:
          - path: /
            pathType: Prefix
    tls:
      - secretName: workflow-tls
        hosts:
          - workflow.example.com
```

The two referenced Secrets must contain:

* **db credentials**: `DB_USERNAME`, `DB_PASSWORD`
* **app credentials**: `DW_AUTH_TOKEN` (required, and native accepts it for every
  role, so use a strong random value), `DW_WORKER_TOKEN`, `DW_OPERATOR_TOKEN`,
  `DW_ADMIN_TOKEN` for narrower role-scoped tokens, and `DW_SERVER_KEY`. Do not
  put `DW_PRINCIPAL_TOKENS` in this Secret: the native Server refuses to start
  with it.

The chart accepts existing Secret names directly, so it composes cleanly with
the External Secrets Operator, Vault Secret Operator, Secrets Store CSI
Driver, or any GitOps secret bridge that produces a Kubernetes `Secret`.

### Native capacity and storage

* Each native pod opens at most four database connections. Size the
  database's connection limit for four per pod at the highest replica count
  (`server.autoscaling.maxReplicas` when autoscaling), plus one for a schema
  upgrade.
* Native keeps external payload objects on local disk at `/app/storage`.
  Without `native.storage.existingClaim` the chart mounts an `emptyDir`, and
  the objects are lost when a pod is replaced. Set a claim before any
  namespace enables external payload storage.
* A native install runs one replica by default. One pod cannot read a payload
  object another pod accepted, so the chart refuses `server.replicaCount`
  above 1 or `server.autoscaling.enabled` unless `native.storage.existingClaim`
  is set, and that claim must be `ReadWriteMany`. The PodDisruptionBudget
  renders only when more than one pod can run, because `minAvailable: 1` on a
  single pod would block every node drain.
* On `SIGTERM` the native Server holds open worker polls until they end. A
  poll waits `DW_WORKER_POLL_TIMEOUT` (30 seconds by default) unless it asks
  for up to 60. `native.terminationGracePeriodSeconds` defaults to 65 so a
  rollout drains every allowed poll. The chart refuses a grace period at or
  below a `DW_WORKER_POLL_TIMEOUT` set in `config.extraEnv`.
* Native liveness probes the listener (`native.livenessProbe`, a TCP check on
  the `http` port), not `/api/health`. `/api/health` reports database
  liveness, so probing it would restart every pod during a database outage,
  while the connection pool recovers on its own. Readiness stays on
  `/api/ready` and takes a pod out of service when its database, schema or
  maintenance loop fails. `server.livenessProbe` applies to PHP mode only.
* Native pods set an ephemeral-storage request and limit from
  `native.ephemeralStorage` (128Mi and 2Gi) unless `server.resources` names
  one. The payload `emptyDir` counts toward that limit. They also mount no
  service account token, because the native Server never calls the
  Kubernetes API. Set `native.automountServiceAccountToken: true` only for a
  sidecar that needs one.
* The chart supports MySQL, MariaDB and PostgreSQL. It refuses SQLite in
  either runtime: native SQLite needs exactly one replica on a ReadWriteOnce
  volume at `/app/database`, which this chart does not provision yet. Use
  `docker-compose.published.yml` for SQLite.

### PHP 2.x installations

An existing PHP installation selects PHP mode explicitly and pins its 2.x
image. The chart refuses a 3.x tag in PHP mode and a 2.x tag in native mode.

```yaml
runtime: php
image:
  tag: "2.11.8"   # or your current 2.x release
externalRedis:
  host: workflow-redis.example.internal
  existingSecret: durable-workflow-redis-credentials
```

PHP mode renders the same resources as chart 0.3.x. Its app credentials Secret
holds `DW_SERVER_KEY`, the role tokens, optional `DW_PRINCIPAL_TOKENS`, and
`DW_AUTH_TOKEN` when you keep `config.auth.backwardCompatible: true`. The
Redis Secret holds `REDIS_USERNAME` and `REDIS_PASSWORD` when Redis enforces
AUTH. See `examples/` for complete PHP values files.

## What the chart renders

| Resource | Runtime | Purpose | Disabled when |
| --- | --- | --- | --- |
| `Deployment` (`*-server`) | both | API replicas, readiness on `/api/ready`. Liveness is a TCP check in native mode and `/api/health` in PHP mode. In native mode the image's own process runs maintenance, and the Deployment carries the `workflows.durable-workflow.dev/runtime: native` annotation. | `server.enabled: false` |
| `Job` (`*-schema-upgrade`) | native | `pre-upgrade` hook that runs the image's `schema-upgrade` command. Opt-in, and rendered only during `helm upgrade` once live state proves every Server is stopped. See [Native schema upgrades](#native-schema-upgrades). | `native.schemaUpgrade.enabled: false` (default) |
| `Job` (`*-migrate`) | php | Runs `server-bootstrap` (migrations, namespace bootstrap) before workloads accept traffic. Helm hook by default; sync-wave annotation for Argo CD. | `bootstrap.enabled: false` |
| `Service` (`*-server`) | both | ClusterIP in front of the API. | `server.enabled: false` |
| `PodDisruptionBudget` (`*-server`) | both | Default `minAvailable: 1`. In native mode only when more than one pod can run. | `server.pdb.enabled: false` |
| `Ingress` (`*-server`) | both | Optional. | `server.ingress.enabled: false` (default) |
| `HorizontalPodAutoscaler` (`*-server`) | both | Optional. | `server.autoscaling.enabled: false` (default) |
| `Deployment` (`*-worker`) | php | Worker pool. Independent scale axis. | `worker.enabled: false` |
| `CronJob` (`*-scheduler`) | php | Singleton scheduler/maintenance runner, including unscoped repair for expired remote SDK task leases. **`concurrencyPolicy: Forbid`** is enforced — see below. | `scheduler.enabled: false` |
| `ConfigMap` (`*-config`) | both | All non-secret config. Native: database settings, auth driver and `config.extraEnv`. PHP adds Redis, queue, application and metrics settings. | Always rendered. |
| `Secret` (`*-app-secrets`) | both | Server signing key, role-scoped tokens, and named principal token map. | `auth.existingSecret` is set. |
| `Secret` (`*-database`) | both | DB username/password. | `externalDatabase.existingSecret` is set. |
| `Secret` (`*-redis`) | php | Redis username/password. | `externalRedis.existingSecret` is set or no inline credentials supplied. |
| `ServiceAccount` (`*`) | both | Namespace-scoped SA for every workload. | `serviceAccount.create: false` |
| `NetworkPolicy` (`*-server`) | both | Optional ingress allow-list to the API. | `networkPolicy.enabled: false` (default) |

## Application logs

The native Server writes its logs to stderr. In PHP mode the chart defaults
`config.extraEnv.LOG_CHANNEL` to `stderr` for the API, worker, scheduler and
bootstrap job. The native Server ignores that setting. Use `kubectl logs` or
the cluster's log collector. In PHP mode, to choose the minimum severity and
emit newline-delimited JSON:

```yaml
config:
  extraEnv:
    LOG_CHANNEL: "stderr"
    LOG_LEVEL: "info"
    LOG_STDERR_FORMATTER: 'Monolog\Formatter\JsonFormatter'
```

These explicit settings also work with existing published Server images. Override
`LOG_CHANNEL` to select a different Laravel channel. File channels require their
own storage and collection. Restart persistent roles after changing logging
settings because configuration is cached at startup. `DW_LOG_FORMAT` is not a
supported setting.

## Database TLS

For an authenticated encrypted database connection, pre-create a CA bundle
Secret in the release namespace and select `verify-full`:

```yaml
externalDatabase:
  sslMode: verify-full
  tls:
    existingSecret: workflow-database-ca
    caKey: ca.crt
```

The chart sets `DB_SSLMODE` and mounts that Secret read-only at
`/etc/durable-workflow/database-tls/ca.pem`, referenced by `DB_SSLROOTCERT`.
Every role receives the same trust bundle: the API server and the native
schema-upgrade Job, or in PHP mode the API server, worker, scheduler and
bootstrap/migration Job. The Secret must already exist when the pre-install or
pre-upgrade hook starts; the chart does not create it. The `database-tls`
volume name and `/etc/durable-workflow/database-tls` mount path are reserved
when this option is enabled. Existing application extra volumes are retained.

Use the database certificate's DNS name as `externalDatabase.host`. `require`
requires encryption but does not guarantee the server's identity. PostgreSQL
also supports `verify-ca`; MySQL/MariaDB refuse that mode and require
`verify-full` for certificate verification. The complete mode and failure
contract is in [Database TLS](../../../docs/server-reference.md#database-tls).

CA files update through the Secret volume rather than a `subPath` mount.
After rotating trust, restart the server pods (and in PHP mode the worker) to
replace existing connections, then check `/api/ready` and run a workflow canary. Poolers need
independent TLS configuration on their database-facing leg.

## Engine invariants the chart enforces

These come from the engine, not the chart. Changing them silently breaks the
engine's correctness contract, so they are either locked or annotated as
override-with-care in `values.yaml`:

* **No silent runtime switch.** An upgrade with an empty `runtime` stays
  native only when the live server Deployment already carries the native
  runtime annotation. A release that chart 0.x installed runs PHP, so its
  upgrade refuses until `runtime` is set. See [docs/UPGRADING.md](docs/UPGRADING.md).
* **Native never upgrades its schema on start.** A pod on an older schema
  refuses with `native_schema_upgrade_required` and stays unready. Schema
  upgrades run only through the explicit hook below.
* **Singleton scheduler (PHP mode).** The CronJob's `concurrencyPolicy` defaults to
  `Forbid`, the chart never renders a parallel scheduler `Deployment`, and
  the schema rejects any other concurrency value. This matches the contract
  in [single-region HA failover](https://durable-workflow.github.io/docs/2.0/deployment#single-region-high-availability-and-failover).
* **Readiness probe is `/api/ready`, not `/api/health`.** In PHP mode
  `/api/ready` checks the database and Redis are usable. In native mode it
  checks the schema and the in-process maintenance loop. Load-balancer routing
  decisions on `/api/health` would route traffic to a node whose database had
  failed over. Keep `/api/ready` out of the liveness probe: in native mode a
  stalled maintenance tick fails readiness on every pod, and a liveness probe
  on it would restart them all. Native liveness is a TCP check for the same
  reason: `/api/health` fails while the database is unreachable.
* **Bootstrap job runs before workloads (PHP mode).** Helm hooks default to
  `pre-install,pre-upgrade`. For Argo CD/Flux users who don't honour Helm
  hooks, sync-wave / depends-on annotations enforce the same ordering.
* **No in-cluster database is bundled.** External persistence is the
  contract; the chart will not render until `externalDatabase.host` (and in
  PHP mode `externalRedis.host`) is populated.
* **Rolling upgrades default to `maxUnavailable: 0`.** Set
  `server.strategy.type: Recreate` for stop-the-world if your release does
  not satisfy the [rolling-upgrade contract](https://durable-workflow.github.io/docs/2.0/rolling-upgrades).
  A memo-storage transition additionally fails closed while an incompatible
  or unidentified workload is active and validates the target image before
  migration. Digest pins and custom images must declare their verified
  `image.memoPayloadStorage` capability; follow the versioned procedure in
  [UPGRADING.md](docs/UPGRADING.md) before migration.

## Native schema upgrades

Patch releases within a native minor line keep the schema, so changing
`image.tag` is enough. A release whose notes announce a schema change needs
the image's `schema-upgrade` command, which requires every native Server
stopped and a database backup first. Use the in-cluster hook with
`helm upgrade`, or the manual path with GitOps or any other client that
renders without the cluster.

### With helm upgrade

1. Scale the Server to zero with autoscaling off, and wait for its pods to
   exit:

   ```bash
   helm upgrade durable-workflow <chart> -f my-values.yaml \
     --set server.replicaCount=0 --set server.autoscaling.enabled=false --wait
   ```
2. Back up the database with your usual procedure.
3. Upgrade to the new image with the hook enabled, your usual replica count
   and autoscaling still off:

   ```bash
   helm upgrade durable-workflow <chart> -f my-values.yaml \
     --set image.tag=<new native release> \
     --set native.schemaUpgrade.enabled=true --wait
   ```

   The `pre-upgrade` hook runs `schema-upgrade` before the new pods start.
   On a database that is already current it only verifies the schema.
4. Turn `native.schemaUpgrade.enabled` off again, and autoscaling back on if
   you use it, in your next upgrade.

The chart renders the hook only during `helm upgrade` against the cluster,
and only when Helm's `lookup` proves every Server is stopped: the release's
server Deployment is visible and carries the native runtime annotation, its
`replicas` is exactly 0, `server.autoscaling.enabled` is off and no
HorizontalPodAutoscaler targets it, and no server pod of the release is still
running. Otherwise it refuses. An install, `helm template`, a client-side dry
run or a GitOps render with the option enabled is always refused, because a
Job applied without Helm's hook ordering could run beside a live Server.

### Manual path (GitOps and helm template)

1. Set `server.replicaCount: 0` with autoscaling off, sync, and wait until no
   server pod of the release remains.
2. Back up the database.
3. Run `schema-upgrade` once with the new image, the release's database
   settings and its database Secret, then wait for it to complete:

   ```yaml
   apiVersion: batch/v1
   kind: Job
   metadata:
     name: durable-workflow-schema-upgrade
   spec:
     backoffLimit: 0
     template:
       spec:
         restartPolicy: Never
         automountServiceAccountToken: false
         securityContext: {runAsNonRoot: true, runAsUser: 1000}
         containers:
           - name: schema-upgrade
             image: docker.io/durableworkflow/server:<new native release>
             args: ["schema-upgrade"]
             envFrom:
               - configMapRef: {name: durable-workflow-config}
             env:
               - name: DB_USERNAME
                 valueFrom: {secretKeyRef: {name: durable-workflow-database, key: DB_USERNAME}}
               - name: DB_PASSWORD
                 valueFrom: {secretKeyRef: {name: durable-workflow-database, key: DB_PASSWORD}}
   ```

   ```bash
   kubectl -n durable-workflow apply -f schema-upgrade-job.yaml
   kubectl -n durable-workflow wait --for=condition=complete \
     job/durable-workflow-schema-upgrade --timeout=30m
   kubectl -n durable-workflow logs job/durable-workflow-schema-upgrade
   ```

   Use your `externalDatabase.existingSecret` and its keys if you set one,
   and mount the database CA Secret at
   `/etc/durable-workflow/database-tls/ca.pem` when you use database TLS. Keep
   this Job out of the GitOps application so it never runs again on its own.
4. Set `image.tag` to the new release and restore `server.replicaCount`.

Restore the backup to go back: there is no schema downgrade.

## GitOps notes

The chart was designed to compose with both Argo CD and Flux without leaning
on Helm hooks alone:

* GitOps controllers that render with `helm template` cannot see the live
  release, so an empty `runtime` always renders native there. Set `runtime`
  explicitly in GitOps values, especially for a PHP installation.

* `argocd.useSyncWaves: true` (default) emits sync-wave annotations so the
  PHP bootstrap Job runs in an earlier sync wave than the workload Deployments
  and CronJob. Flip to `false` only if you actively use Helm hooks under
  Argo CD's `--enable-helm-hooks` flag.
* `flux.useDependsOn: true` (default) emits, in PHP mode,
  `kustomize.toolkit.fluxcd.io/depends-on` annotations referencing the
  bootstrap Job. The Job name is deterministic, so the annotation is stable
  across reconciliations.

## Verification

```bash
helm test durable-workflow --namespace durable-workflow
```

The test pod hits `/api/health` and `/api/ready` through the in-cluster
Service. In native mode a failure usually means the Server could not reach,
create or accept its schema (check the server pod log, for example for
`native_schema_upgrade_required`). In PHP mode it means the bootstrap Job did
not produce a usable cluster.

For the human verification path, see `templates/NOTES.txt`.

## Upgrade

Read [docs/UPGRADING.md](docs/UPGRADING.md) before crossing any chart MAJOR
or MINOR version boundary. Image (`appVersion`) upgrades and chart upgrades
are governed by separate semver streams.

## Removing the chart

```bash
helm uninstall durable-workflow --namespace durable-workflow
```

`helm uninstall` deletes everything the chart created. The external database,
Redis in PHP mode, and any `native.storage.existingClaim` are explicitly out of
the chart's lifecycle — back them up before
you uninstall a production release.

## Validation in this repository

* `helm lint` and `helm template` run on every PR (see
  `.github/workflows/helm-chart-validation.yml`).
* `scripts/ci/test_helm_runtime_modes.py` checks what each runtime renders and
  every render-time refusal.
* A kind cluster installs the chart in both runtimes: PHP from the checked-out
  source image (`scripts/helm-chart-kind-smoke.sh`) and native from the
  published image (`scripts/helm-chart-native-kind-smoke.sh`), runs
  `helm test`, and asserts `/api/ready` responds. The native smoke also
  exercises the runtime and schema-upgrade guards against a live release.
* `kubeconform` validates every rendered manifest against published
  Kubernetes schemas for the kube versions in our support matrix.
