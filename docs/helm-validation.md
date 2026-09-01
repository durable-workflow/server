# Helm Chart Validation

This note records the operator-side contract for the self-serve Helm path.
It is the server-side view of the engine contract in
[`durable-workflow/workflow#docs/deployment/helm.md`](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/helm.md):
the library doc names what the chart can promise without weakening any
existing engine guarantee; this doc names the operator topology, validation
harness, distribution path, and recovery-packet evidence required to claim
the self-serve Helm contract on the standalone Durable Workflow Server image.

The chart itself lives at
[`k8s/helm/durable-workflow/`](../k8s/helm/durable-workflow/) with
[install/upgrade docs](../k8s/helm/durable-workflow/README.md) and a
[chart-version upgrading guide](../k8s/helm/durable-workflow/docs/UPGRADING.md).

## Decision

Promote Helm from "support-led" to a self-serve contract that is layered on
the existing raw-Kubernetes contract.

The first public Helm shape:

- Renders the same workloads as the raw manifests at
  [`k8s/`](../k8s/): bootstrap Job, API Deployment + Service +
  PodDisruptionBudget, worker Deployment, scheduler/maintenance CronJob.
- Encodes the engine invariants (singleton scheduler, readiness on
  `/api/ready`, externals-first persistence, bootstrap-before-workloads
  ordering) as defaults that cannot be silently subverted via values.
- Distributes versioned chart releases via OCI at
  `oci://ghcr.io/durable-workflow/charts/durable-workflow`.
- Has chart CI: `helm lint`, `helm template` against multiple values
  fixtures, `kubeconform` against every Kubernetes version in the support
  matrix, and `chart-testing` (`ct lint-and-install`) against a kind
  cluster on every PR that touches `k8s/helm/**`.
- Carries a chart-side semver and an upgrading guide. The chart's `version`
  is independent of the server image's `appVersion`; both follow semver,
  and breaking chart changes require a chart-MAJOR bump and a documented
  migration step.

Active/active multi-region, hands-free regional failover, duplicate
scheduler runners as a steady-state topology, and provider-specific
managed-Kubernetes validation remain support-led — the same boundary that
applies to the raw-manifest contract. Helm is a packaging and rollout
contract, not a new engine topology.

## Rationale

Three observations make a narrow self-serve Helm contract possible:

1. **The engine's existing single-region HA contract is independent of how
   manifests are produced.** It cares about the resulting workloads —
   readiness probes, singleton scheduler, shared substrate — not whether
   they were rendered by `kustomize`, `helm template`, or copy-pasted YAML.
   The chart's job is to ensure those rendered workloads keep matching the
   contract; nothing in the chart relaxes any engine guarantee.

2. **External persistence is already the contract.** The raw manifests have
   never bundled MySQL or Redis; the chart inherits that. This avoids the
   common Helm-chart pitfall of shipping a "convenient" embedded database
   that becomes load-bearing in production and can't be rolled forward
   safely.

3. **Helm hooks plus GitOps-friendly annotations cover both rollout
   models.** Pre-install/pre-upgrade hooks keep the bootstrap Job ahead of
   workloads under `helm install` / `helm upgrade`. Argo CD sync-wave and
   Flux depends-on annotations achieve the same ordering for controllers
   that don't honour Helm hooks. Operators don't have to re-design rollout
   per orchestrator.

## Engine invariants the chart enforces

| Invariant | Where the chart enforces it | Why it can't be relaxed |
| --- | --- | --- |
| Singleton scheduler/maintenance runner. | `scheduler.concurrencyPolicy` is locked to `Forbid` (values schema rejects anything else); the chart never renders a parallel scheduler `Deployment` or `StatefulSet`. | Duplicate scheduler runners as a steady-state topology fall outside the single-region HA contract. |
| Readiness checked on `/api/ready` (DB+Redis usability), not `/api/health`. | `server.readinessProbe` defaults to `/api/ready`; example values and the README call out the consequence of changing it. | A load balancer routing on `/api/health` would not drain a node whose database had failed over. |
| Bootstrap runs before workloads. | Migration Job carries Helm hooks (`pre-install,pre-upgrade,hook-weight=-5,before-hook-creation,hook-succeeded`) and Argo CD sync-wave / Flux depends-on annotations. | Workload pods that boot against an unmigrated database fail readiness, then page at random. |
| External persistence is the contract. | The chart `fail`s during render when `externalDatabase.host` or `externalRedis.host` is empty; no in-cluster database or Redis template exists in the chart. | Bundling the database silently moves it into the chart's lifecycle and breaks the recovery-packet contract from [Operator Operating Envelope](https://durable-workflow.github.io/docs/2.0/operator-operating-envelope). |
| `RollingUpdate` defaults to `maxUnavailable: 0`. | `server.strategy.rollingUpdate.maxUnavailable` defaults to `0`. | Matches the [rolling-upgrade contract](https://durable-workflow.github.io/docs/2.0/rolling-upgrades); operators who haven't met that contract should set `strategy.type: Recreate` instead. |

## Configurable knobs (the table-stakes contract)

The chart exposes — and CI exercises — the knobs the deployment matrix
calls out as table-stakes for a serious Helm path:

* **Liveness/readiness probes** are first-class values
  (`server.livenessProbe`, `server.readinessProbe`,
  `server.startupProbe`, `worker.livenessProbe`).
* **Deployment strategy** is explicit (`server.strategy`,
  `worker.strategy`) and defaults to the rolling-upgrade contract.
* **Rollout pacing** lives in `server.minReadySeconds`,
  `progressDeadlineSeconds`, `revisionHistoryLimit`,
  `topologySpreadConstraints`, and HPA settings.
* **PodDisruptionBudget** is a values toggle for both server and worker
  (`*.pdb.enabled`, `*.pdb.minAvailable`, `*.pdb.maxUnavailable`).
* **Resource knobs** (`*.resources.requests`, `*.resources.limits`) and
  pod-level scheduling controls (`nodeSelector`, `tolerations`, `affinity`,
  `priorityClassName`) are exposed for every workload.
* **Secret-management paths** support the existing-secret pattern for the
  app secret, the database secret, and the Redis secret. The example
  `examples/values-external-secrets-operator.yaml` shows the External
  Secrets Operator companion shape; equivalent patterns work for Vault
  Secret Operator and the Secrets Store CSI Driver.
* **External persistence** is the contract: no bundled databases, no
  bundled Redis, no in-cluster default the operator ends up depending on.

## Validation harness

`.github/workflows/helm-chart-checks.yml` runs on every PR that
touches `k8s/helm/**` or the chart smoke script. The harness has two jobs:

1. **`lint-and-template`** — runs `helm lint` over the chart and over
   every CI fixture, renders each fixture with `helm template`, and runs
   `kubeconform` over the rendered output against every Kubernetes version
   in `KUBE_VERSIONS` (currently 1.27 — 1.30).
2. **`ct-lint-install`** — runs `chart-testing`'s `ct lint`, then
   provisions a kind cluster and runs `scripts/helm-chart-kind-smoke.sh`,
   which:
   - builds the server image and loads it into kind;
   - provisions disposable in-cluster MySQL + Redis fixtures;
   - `helm install`s the chart with inline secrets;
   - waits for the bootstrap Job, the server Deployment, and the worker
     Deployment to roll out;
   - asserts `/api/ready` returns 200 through the chart-rendered Service;
   - registers a worker against `/api/worker/register` to confirm the
     end-to-end engine surface;
   - runs `helm test`;
   - runs `helm upgrade` in place and re-checks readiness;
   - runs `helm uninstall` and asserts no chart-owned resources remain.

The CI fixtures intentionally cover three shapes:

| Fixture | Purpose |
| --- | --- |
| `ci/inline-secrets-values.yaml` | Smallest renderable chart — exercises secret rendering. |
| `ci/existing-secrets-values.yaml` | GitOps / externally-managed-secret path. The chart renders no Secret resources of its own. |
| `ci/ingress-and-hpa-values.yaml` | Optional templates: Ingress, HPA, NetworkPolicy, server + worker PDB. |

A chart change that affects rendered manifests must keep all three
fixtures green, and the kind smoke must end with a clean uninstall.

## Distribution

Releases are cut from `Chart.yaml.version`. Each release publishes an OCI
artifact at `oci://ghcr.io/durable-workflow/charts/durable-workflow` with the
chart's version as the OCI tag.

`scripts/ci/helm_chart_release.py` records the chart version, appVersion,
chart-source revision, package digest, default image reference, and resolved
image digest. The Server chart-release workflow rejects changed package
content under an existing chart version and proves anonymous OCI installation.

The "install/upgrade from released charts, not only from a checkout" line
on the deployment guide is satisfied by the OCI distribution path; the
checkout install (`helm install ./k8s/helm/durable-workflow/`) remains
supported for chart development and air-gapped review.

## Recovery-packet additions

A deployment claiming the self-serve Helm contract MUST extend its
recovery packet (per the
[Operator Operating Envelope](https://durable-workflow.github.io/docs/2.0/operator-operating-envelope))
with two pieces of evidence on top of the raw-manifest packet:

* the **chart version + values revision** that produced the running
  manifests (`helm get values <release> --revision <n>`), so a recovery
  rebuild reproduces the same manifests;
* the **chart-upgrade rehearsal** for the most recent chart MINOR or
  MAJOR boundary the deployment crossed, including a successful
  `helm rollback` from the new version to the previous version, with
  `/api/ready` returning 200 at both ends.

A deployment that has not run that chart-upgrade rehearsal is not yet
self-serve under this contract; it remains support-led until the rehearsal
evidence is recorded and refreshed on the cadence the Operator Operating
Envelope publishes.

## Boundary against unsupported claims

The Helm contract is the same boundary as the raw-manifest contract,
narrowed to the rollout/packaging surface the chart owns. The following
remain outside it and continue to require a support-led design pass:

- active/active multi-writer database topologies;
- automatic or hands-free regional failover (active/passive with
  operator-driven failover stays the
  [next contract over](https://durable-workflow.github.io/docs/2.0/deployment#activepassive-multi-region));
- duplicate scheduler/maintenance runners as a steady-state topology;
- engine-enforced region-pinned task queues;
- provider-specific managed-Kubernetes validation (EKS, GKE, AKS, OpenShift);
- broad "five-nines" or "zero-downtime" SLA promises beyond the bounded
  recovery times in the
  [single-region HA failover contract](https://durable-workflow.github.io/docs/2.0/deployment#single-region-high-availability-and-failover).

The contract is *self-serve install, upgrade, and rollback of a Durable
Workflow deployment using published Helm charts*, not an uptime promise
that depends on the operator's database, network, or orchestrator choices.
Marketing or SLA language for self-hosted Helm deployments MUST NOT cross
that line without dedicated validation.
