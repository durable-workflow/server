# Durable Workflow Helm Chart

This chart deploys a self-hosted Durable Workflow standalone server, worker
pool, scheduler/maintenance runner, and bootstrap job onto Kubernetes. It is
the self-serve Helm path for the deployment matrix at
<https://durable-workflow.github.io/docs/2.0/deployment>.

The chart is engine-aware: the templates encode the engine invariants from
[workflow/docs/deployment/multi-node-requirements.md](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/multi-node-requirements.md)
and the failover guarantees from
[server/docs/ha-failover-validation.md](../../docs/ha-failover-validation.md)
(singleton scheduler, readiness on `/api/ready`, externals-first persistence,
no in-cluster databases bundled). Override-with-care knobs that would break
those guarantees are flagged in `values.yaml`.

## Status

* Chart version: see `Chart.yaml` (semver — independent of the Server image
  version, which is `appVersion`).
* Chart distribution: published as an OCI artifact at
  `oci://ghcr.io/durable-workflow/charts/durable-workflow`.
* Stability: **beta** — the externals-first contract, singleton scheduler
  invariant, secret layout, and resource naming are committed to. Any breaking
  change to those will bump the chart's MAJOR version and ship migration steps
  in [docs/UPGRADING.md](docs/UPGRADING.md).

## Prerequisites

| Requirement | Notes |
| --- | --- |
| Kubernetes ≥ 1.27 | Older versions are not validated; the chart uses `policy/v1` PDB and `autoscaling/v2` HPA. |
| Helm ≥ 3.10 | Required for OCI install and the `lookup` builtin. |
| External MySQL/PostgreSQL | The chart does not bundle a database. |
| External Redis (or compatible) | Required for the multi-node correctness contract. |
| Inbound TLS / ingress | Provided by your platform. The chart can render an `Ingress` but does not terminate TLS itself. |

## Install

The chart will refuse to render until you point it at an external database and
Redis — that is intentional, not a bug.

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
  tag: "2.0.3"
  # Pin a digest in production:
  # digest: "sha256:abc123..."
  # memoPayloadStorage: "raw-json-v1" # Required for a digest or custom image.

externalDatabase:
  connection: pgsql
  host: workflow-db.example.internal
  port: 5432
  database: durable_workflow
  existingSecret: durable-workflow-db-credentials

externalRedis:
  host: workflow-redis.example.internal
  port: 6379
  existingSecret: durable-workflow-redis-credentials

auth:
  existingSecret: durable-workflow-app-credentials

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

The three referenced Secrets must contain:

* **db credentials**: `DB_USERNAME`, `DB_PASSWORD`
* **Redis credentials** (only when Redis enforces AUTH): `REDIS_USERNAME`,
  `REDIS_PASSWORD`
* **app credentials**: `DW_SERVER_KEY`, `DW_WORKER_TOKEN`,
  `DW_OPERATOR_TOKEN`, `DW_ADMIN_TOKEN`, optional `DW_PRINCIPAL_TOKENS` for
  named bearer-token principals, plus optional `DW_AUTH_TOKEN` when you keep
  `config.auth.backwardCompatible: true`.

The chart accepts existing Secret names directly, so it composes cleanly with
the External Secrets Operator, Vault Secret Operator, Secrets Store CSI
Driver, or any GitOps secret bridge that produces a Kubernetes `Secret`.

## What the chart renders

| Resource | Purpose | Disabled when |
| --- | --- | --- |
| `Job` (`*-migrate`) | Runs `server-bootstrap` (migrations, namespace bootstrap) before workloads accept traffic. Helm hook by default; sync-wave annotation for Argo CD. | `bootstrap.enabled: false` |
| `Deployment` (`*-server`) | Stateless API replicas. Liveness on `/api/health`, readiness on `/api/ready`. | `server.enabled: false` |
| `Service` (`*-server`) | ClusterIP in front of the API. | `server.enabled: false` |
| `PodDisruptionBudget` (`*-server`) | Default `minAvailable: 1`. | `server.pdb.enabled: false` |
| `Ingress` (`*-server`) | Optional. | `server.ingress.enabled: false` (default) |
| `HorizontalPodAutoscaler` (`*-server`) | Optional. | `server.autoscaling.enabled: false` (default) |
| `Deployment` (`*-worker`) | Worker pool. Independent scale axis. | `worker.enabled: false` |
| `CronJob` (`*-scheduler`) | Singleton scheduler/maintenance runner. **`concurrencyPolicy: Forbid`** is enforced — see below. | `scheduler.enabled: false` |
| `ConfigMap` (`*-config`) | All non-secret config (DB host, Redis host, auth driver, metrics knobs). | Always rendered. |
| `Secret` (`*-app-secrets`) | Server signing key, role-scoped tokens, and named principal token map. | `auth.existingSecret` is set. |
| `Secret` (`*-database`) | DB username/password. | `externalDatabase.existingSecret` is set. |
| `Secret` (`*-redis`) | Redis username/password. | `externalRedis.existingSecret` is set or no inline credentials supplied. |
| `ServiceAccount` (`*`) | Namespace-scoped SA for every workload. | `serviceAccount.create: false` |
| `NetworkPolicy` (`*-server`) | Optional ingress allow-list to the API. | `networkPolicy.enabled: false` (default) |

## Engine invariants the chart enforces

These come from the engine, not the chart. Changing them silently breaks the
engine's correctness contract, so they are either locked or annotated as
override-with-care in `values.yaml`:

* **Singleton scheduler.** The CronJob's `concurrencyPolicy` defaults to
  `Forbid`, the chart never renders a parallel scheduler `Deployment`, and
  the schema rejects any other concurrency value. This matches the contract
  in [single-region HA failover](https://durable-workflow.github.io/docs/2.0/deployment#single-region-high-availability-and-failover).
* **Readiness probe is `/api/ready`, not `/api/health`.** `/api/ready` checks
  the database and Redis are usable. Load-balancer routing decisions on
  `/api/health` would route traffic to a node whose database had failed over.
* **Bootstrap job runs before workloads.** Helm hooks default to
  `pre-install,pre-upgrade`. For Argo CD/Flux users who don't honour Helm
  hooks, sync-wave / depends-on annotations enforce the same ordering.
* **No in-cluster database is bundled.** External persistence is the
  contract; the chart will not render until `externalDatabase.host` and
  `externalRedis.host` are populated.
* **Rolling upgrades default to `maxUnavailable: 0`.** Set
  `server.strategy.type: Recreate` for stop-the-world if your release does
  not satisfy the [rolling-upgrade contract](https://durable-workflow.github.io/docs/2.0/rolling-upgrades).
  A memo-storage transition additionally fails closed while an incompatible
  or unidentified workload is active and validates the target image before
  migration. Digest pins and custom images must declare their verified
  `image.memoPayloadStorage` capability; follow the versioned procedure in
  [UPGRADING.md](docs/UPGRADING.md) before migration.

## GitOps notes

The chart was designed to compose with both Argo CD and Flux without leaning
on Helm hooks alone:

* `argocd.useSyncWaves: true` (default) emits sync-wave annotations so the
  bootstrap Job runs in an earlier sync wave than the workload Deployments
  and CronJob. Flip to `false` only if you actively use Helm hooks under
  Argo CD's `--enable-helm-hooks` flag.
* `flux.useDependsOn: true` (default) emits
  `kustomize.toolkit.fluxcd.io/depends-on` annotations referencing the
  bootstrap Job. The Job name is deterministic, so the annotation is stable
  across reconciliations.

## Verification

```bash
helm test durable-workflow --namespace durable-workflow
```

The test pod hits `/api/health` and `/api/ready` through the in-cluster
Service. Failure means the bootstrap Job did not produce a usable cluster.

For the human verification path, see `templates/NOTES.txt`.

## Upgrade

Read [docs/UPGRADING.md](docs/UPGRADING.md) before crossing any chart MAJOR
or MINOR version boundary. Image (`appVersion`) upgrades and chart upgrades
are governed by separate semver streams.

## Removing the chart

```bash
helm uninstall durable-workflow --namespace durable-workflow
```

`helm uninstall` deletes everything the chart created. The external database
and Redis are explicitly out of the chart's lifecycle — back them up before
you uninstall a production release.

## Validation in this repository

* `helm lint` and `helm template` run on every PR (see
  `.github/workflows/helm-chart-validation.yml`).
* `chart-testing` (`ct lint-and-install`) installs the chart into a kind
  cluster, runs `helm test`, and asserts `/api/ready` responds.
* `kubeconform` validates every rendered manifest against published
  Kubernetes schemas for the kube versions in our support matrix.
