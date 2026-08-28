# Upgrading the Durable Workflow Helm chart

The chart's own version (`Chart.yaml.version`) follows
[semver 2.0](https://semver.org/spec/v2.0.0.html) **independently** of the
server image version (`Chart.yaml.appVersion`). They are upgraded on separate
cadences; both should be pinned in production.

| Stream | What it controls | Where it lives |
| --- | --- | --- |
| `version` (chart semver) | The shape of the rendered manifests, the values contract, and the per-resource defaults. | `Chart.yaml`, this chart, this guide. |
| `appVersion` (image semver) | The Durable Workflow server image the chart targets. | `Chart.yaml`, [server release notes](https://github.com/durable-workflow/server/releases), and the engine [compatibility matrix](https://durable-workflow.github.io/docs/2.0/compatibility). |

## Semver expectations for the chart

* **MAJOR (`x.0.0`)**: a values key was renamed or removed, a default was
  changed in a way that requires an operator action, or a previously-rendered
  resource changed kind/name/labels in a way that triggers Kubernetes to
  recreate it. Migration steps for every MAJOR are listed below.
* **MINOR (`0.x.0`)**: new optional values, new optional resources, or
  forward-compatible defaults. Existing values keep working without changes.
* **PATCH (`0.0.x`)**: bug fixes, doc updates, or template changes that
  cannot affect a rendered manifest's output.

The chart pins `appVersion` to the server image stream it was tested against
in CI. A patch chart bump is allowed to bump `appVersion` to a server patch.
Crossing a server MAJOR or MINOR (e.g. `0.2 → 0.3`) requires at least a
chart MINOR bump and an entry below.

## Universal upgrade procedure

1. Read this guide for every chart-version increment you are crossing
   (e.g. for a `0.1.0 → 0.3.0` upgrade, read `0.2.0` and `0.3.0`).
2. Back up the workflow database and snapshot Redis if your operator
   recovery packet requires it (see
   [Operator Operating Envelope](https://durable-workflow.github.io/docs/2.0/operator-operating-envelope)).
3. Render a diff before applying:

   ```bash
   helm get values durable-workflow -n durable-workflow > current.yaml
   helm diff upgrade durable-workflow ./k8s/helm/durable-workflow \
     --version <new-chart-version> \
     -f my-values.yaml \
     --namespace durable-workflow
   ```
4. Apply:

   ```bash
   helm upgrade durable-workflow ./k8s/helm/durable-workflow \
     --version <new-chart-version> \
     -f my-values.yaml \
     --namespace durable-workflow \
     --atomic --wait --timeout 10m
   ```
   The default Helm hook order runs the bootstrap Job before the new
   server/worker pods take traffic. With Argo CD or Flux, the chart's
   sync-wave / depends-on annotations enforce the same ordering.
5. After the rollout, confirm the engine view matches the operator view:

   ```bash
   kubectl -n durable-workflow rollout status deploy/durable-workflow-server
   curl -H "Authorization: Bearer $DW_ADMIN_TOKEN" \
     http://workflow.example.com/api/cluster/info | jq '.topology'
   ```

   `topology.current_shape` should still be `standalone_server` or whatever
   shape your previous release advertised.

## Per-version migration notes

### 0.1.49

This release advances the chart Server identity to `2.0.0-rc.53` and the
default worker protocol to `1.19`. The HTTP and stream protocol documents use
fresh identities for the updated negotiation contract. Existing supported
1.x workers remain compatible, while workers newer than `1.19` fail closed.
No manual data migration is required.

### 0.1.48

This release advances the chart Server identity to `2.0.0-rc.52`, aligns HTTP
and stream protocol negotiation at `1.18`, and accepts both list- and map-shaped
local-activity heartbeat detail values. Existing values remain compatible, and
no manual data migration is required.

### 0.1.47

This release advances the chart Server identity to `2.0.0-rc.51` and the
worker protocol to `1.18`. Workers selecting protocol `1.18` must register a
structured capability manifest for local activities, worker sessions, and
sticky execution; each supported entry must exactly match its flat routing
capability. Workers selecting an older supported protocol remain eligible
without the new manifest, and the three portable worker-affinity features stay
opt-in.

Bootstrap adds a nullable capability-manifest column to worker registrations.
No manual data migration is required. Replacing a worker clears stale sticky
affinity and forces durable cold replay, so sticky execution remains an
optimization rather than a correctness dependency.

The release also narrows external-payload translation to declared runtime
payload positions, retains structured workflow-task failure diagnostics, and
preserves memo projections in run listings after continue-as-new. Existing
application values remain compatible.

### 0.1.45

The memo-transition guard now proves that an incompatible predecessor has
stopped by listing the chart-managed Pods and scheduler Jobs. Setting a
Deployment's desired replicas to zero or suspending the CronJob is only the
first step: a nonterminal Pod, a terminating Pod that is still held by a
finalizer, or a nonterminal scheduler Job still blocks the bootstrap. Completed
and failed Jobs and Pods may remain for history, and verified `raw-json-v1` and
`dual-v1` executions remain eligible for ordinary rolling upgrades.

The identity running `helm upgrade` must be able to get and list Deployments,
CronJobs, Pods, and Jobs in the release namespace so the `lookup` checks can
fail closed. For an envelope-only or unidentified predecessor:

1. Back up the database, drain external workers, and temporarily disable the
   Server or worker HPA.
2. Suspend the scheduler and scale both Deployments to zero.

   ```bash
   kubectl -n durable-workflow patch cronjob durable-workflow-scheduler \
     --type merge -p '{"spec":{"suspend":true}}'
   kubectl -n durable-workflow scale \
     deploy/durable-workflow-server deploy/durable-workflow-worker \
     --replicas=0
   ```

3. Inspect the managed executions. Wait for every nonterminal Server, worker,
   and scheduler Pod to disappear, and for each scheduler Job either to report
   a `Complete`/`Failed` condition or to be deleted. A Pod shown as
   `Terminating` is still executable for purposes of this guard.

   ```bash
   kubectl -n durable-workflow get pods \
     -l 'app.kubernetes.io/instance=durable-workflow'
   kubectl -n durable-workflow get jobs \
     -l 'app.kubernetes.io/instance=durable-workflow,app.kubernetes.io/component=scheduler'
   ```

4. Retry the Helm upgrade only after that quiescence check is satisfied. Restore
   the configured replica counts, autoscaling, and scheduler after bootstrap
   succeeds.

### 0.1.43

The memo-transition guard now reads each active Deployment or CronJob pod
template image instead of treating `app.kubernetes.io/version` as the running
Server identity. Official `docker.io/durableworkflow/server` release tags have
a chart-owned storage classification. Custom repositories, custom tags, and
digest pins are opaque to Helm and must declare their verified capability:

```yaml
image:
  digest: "sha256:<manifest-digest>"
  memoPayloadStorage: "dual-v1"
```

Use `raw-json-v1` only for an image verified to retain the raw JSON memo
representation. The chart rejects the envelope-only `2.0.0-rc.47` and
`2.0.0-rc.48` identities even if a contradictory capability is supplied.
Existing values that retain an opaque image fail closed until the declaration
is added; this prevents a retained override from silently becoming the target
of the dual-representation migration.

For an active envelope-only or unidentified predecessor, keep the target on a
verified raw-JSON or dual-representation image, then use the stop-the-world
procedure below: back up, drain external workers, disable either HPA, scale the
Server and worker Deployments to zero, and suspend the scheduler CronJob before
the upgrade. An active workload already marked `dual-v1` remains eligible for
ordinary rolling upgrades unless its pod template names a known incompatible
official image.

### 0.1.42

This release advances the chart Server identity to `2.0.0-rc.50`, requires
Workflow `2.0.0-rc.45`, and enforces the advertised `memo_upserts` and
`typed_search_attributes` worker capabilities during registration, workflow
task routing, and completion. No database migration is required. Mixed fleets
should upgrade and re-register capable workers before they resume workflows
whose history contains memo upserts or typed search-attribute upserts.
Protocol `1.17` also gates authoring and replay of durable condition-wait
occurrence identity; protocol `1.16` workers remain eligible for unaffected
workflow history.

An interrupted `2.0.0-rc.47` or `2.0.0-rc.48` envelope-only migration on
MySQL can leave a rewritten ID prefix without a completed migration record.
Bootstrap now fails closed before changing any memo rows when that source is
ambiguous. The safest recovery is to restore the pre-rewrite backup and run
bootstrap once with `DW_WORKFLOW_MEMO_MIGRATION_RECOVERY=raw-json`. If the
exact last converted row ID has instead been verified from the interrupted
migration, run bootstrap once with
`DW_WORKFLOW_MEMO_MIGRATION_RECOVERY=envelope-prefix:<last-converted-id>`.
Do not guess the cutoff, and clear the recovery setting after bootstrap
succeeds. PostgreSQL rolls an unrecorded migration back transactionally and
does not require this override.

### 0.1.41

This release advances the chart Server identity to `2.0.0-rc.49` and expands
workflow memo storage into a predecessor-readable JSON projection plus a
sequence-bound lossless payload. Upgrades directly from Server `2.0.0-rc.46`
can use the normal rolling procedure: both revisions read and write one logical
memo value while the restart-safe backfill runs.

Server `2.0.0-rc.47` and `2.0.0-rc.48` used the short-lived envelope-only
representation and cannot coexist with the dual representation. Before
upgrading either release, back up the database, suspend the scheduler CronJob,
drain external workers, temporarily disable either HPA, and scale the chart's
Server and worker Deployments to zero. The chart fails an upgrade when it can
see an active incompatible workload. Run the upgrade only after the old
revision is fully stopped, then restore the configured replica counts,
autoscaling, and scheduler after bootstrap succeeds.

Do not use an ordinary Helm rollback from `2.0.0-rc.49` to `2.0.0-rc.47` or
`2.0.0-rc.48`: those images cannot read the compatibility projection. Restore
the pre-upgrade database backup before starting either envelope-only revision.
Rollback to `2.0.0-rc.46` retains the readable JSON projection, but still drain
the successor first so only one runtime revision writes during rollback.

### 0.1.40

This release advances the chart Server identity to `2.0.0-rc.48` and separates
deterministic capacity schema source qualification from the fail-closed audit of
the canonical public schema routes. Existing `0.1.39` values remain compatible,
and no data migration is required.

### 0.1.39

This release advances the chart Server identity to `2.0.0-rc.47` and adds
portable workflow memo updates while advancing the advertised worker protocol
to 1.16. Memo updates remain available to compatible 1.14+ workers. The normal
database migration converts existing memo rows to their portable payload
envelopes; no manual memo rewrite is required.

### 0.1.37

This release advances the chart Server identity to `2.0.0-rc.46` and preserves
declared workflow search-attribute types across the worker command boundary.
Existing `0.1.36` values remain compatible.

### 0.1.36

This release advances the chart Server identity to `2.0.0-rc.45` and bounds
message-stream payload and terminal state retention. Apply the new inbound
stream retention-state migration before rollout. Existing `0.1.35` values
remain compatible.

### 0.1.35

This release advances the chart Server identity to `2.0.0-rc.44` and adds the
bounded external-payload reclamation pass to the maintenance runner. Apply the
new runtime external-payload migration before rollout. Existing `0.1.34` values
remain compatible.

### 0.1.34

This release advances the chart Server identity to `2.0.0-rc.43` and routes
external payload upload and fetch through the authenticated namespace runtime.
Existing `0.1.33` values remain compatible.

### 0.1.33

This release advances the chart Server identity to `2.0.0-rc.42` and adds
portable durable message streams through worker protocol 1.15. Apply the new
stream-state migration before accepting message input. Existing workflow
signals and histories require no migration.

### 0.1.32

This release advances the chart Server identity to `2.0.0-rc.41` and ships the
Fiber-backed straight-line PHP conformance workers for current service-mode
validation. Existing `0.1.31` values remain compatible.

### 0.1.31

This release advances the chart Server identity to `2.0.0-rc.40` and adds
replay-safe workflow-command emission for run-scoped Workflow Streams. Existing
`0.1.30` values remain compatible.

### 0.1.30

This release advances the chart Server identity to `2.0.0-rc.39` and accepts
deterministic parallel-group metadata from service-mode workers. Existing
`0.1.29` values remain compatible.

### 0.1.29

This release advances the chart Server identity to `2.0.0-rc.38` and publishes
the capacity schema inventory and qualification contract for canonical public
routes. Existing `0.1.28` values remain compatible.

### 0.1.28

This release advances the chart Server identity to `2.0.0-rc.37` and publishes
worker OpenAPI document version 9 for the cached-poll conflict response union.
Existing `0.1.27` values remain compatible.

### 0.1.27

This release advances the chart Server identity to `2.0.0-rc.36` and adds a
focused published-artifact PHP CLI signal diagnostic with bounded post-attempt
state evidence. Existing `0.1.26` values remain compatible.

### 0.1.26

This release advances the chart Server identity to `2.0.0-rc.35` and excludes
compound credential fields from retained portable signals/queries conformance
text evidence. Existing `0.1.25` values remain compatible.

### 0.1.25

This release advances the chart Server identity to `2.0.0-rc.34` and retains
bounded failed-client routing diagnostics in portable signals/queries
conformance evidence. Existing `0.1.24` values remain compatible.

### 0.1.24

This release advances the chart Server identity to `2.0.0-rc.33`. PHP
service-mode workflows must use direct `WorkflowContext` calls and ordinary
return values; generator-returning handlers are unsupported.

### 0.1.23

This release advances the chart Server identity to `2.0.0-rc.32`. Existing
`0.1.22` values remain compatible.

### 0.1.22

This release adds the namespace capacity-evidence contract for Server
`2.0.0-rc.31`. The contract is additive and does not change plans, billing, or
infrastructure. Existing `0.1.21` values remain compatible.

### 0.1.21

This release advances the chart Server identity to `2.0.0-rc.31` and derives
the embedded Workflow package from the Server Composer lock. The default
runtime image remains governed by the separately qualified compatibility
authority. Existing `0.1.20` values remain compatible.

### 0.1.20

This release routes the default runtime image through the passing public
compatibility authority. Existing `0.1.19` values remain compatible; operators
who explicitly retain a different image tag or digest keep that reproducible
override.

### 0.1.19

This release advances the default Server image to `2.0.0-rc.30`. Existing
`0.1.18` values remain compatible. The bootstrap hook inventories every
active or replay-relevant persisted payload codec and Avro frame before new
pods take traffic. If it reports `unsupported_payload_codec`, stop the rollout
and follow the emitted drain/export-and-re-encode remediation; customer
history is never deleted by the preflight.

### 0.1.18

This release advances the default Server image to `2.0.0-rc.29`. Existing
`0.1.17` values remain compatible.

### 0.1.16

This release advances the default Server image to `2.0.0-rc.28`. Existing
`0.1.15` values remain compatible.

### 0.1.15

This release advances the default Server image to `2.0.0-rc.27`. Existing
`0.1.14` values remain compatible.

### 0.1.14

This release advances the default Server image to `2.0.0-rc.25`. Existing
`0.1.13` values remain compatible.

### 0.1.13

This release advances the default Server image to `2.0.0-rc.24`. Existing
`0.1.12` values remain compatible.

### 0.1.12

This release advances the default Server image to `2.0.0-rc.23`. Existing
`0.1.11` values remain compatible.

### 0.1.10

This release advances the default Server image to `2.0.0-rc.21`. Existing
`0.1.9` values remain compatible.

### 0.1.9

This release advances the default Server image to `2.0.0-rc.19`. Existing
`0.1.8` values remain compatible.

### 0.1.8

This release advances the default Server image to `2.0.0-rc.18`. Existing
`0.1.7` values remain compatible.

### 0.1.7

This release advances the default Server image to `2.0.0-rc.17`. Existing
`0.1.6` values remain compatible.

### 0.1.6

This release advances the default Server image to `2.0.0-rc.16`. Existing
`0.1.5` values remain compatible.

### 0.1.5

This release advances the default Server image to `2.0.0-rc.15`. Existing
`0.1.4` values remain compatible.

### 0.1.4

This release advances the default Server image to `2.0.0-rc.14`. Existing
`0.1.3` values remain compatible.

### 0.1.3

This release advances the default Server image to `2.0.0-rc.13`. Existing
`0.1.2` values remain compatible.

### 0.1.2

This release advances the default Server image to `2.0.0-rc.12`. Existing
`0.1.1` values remain compatible.

### 0.1.1

This release establishes the immutable public OCI and HTTPS distribution
channels and pins the default Server image to `2.0.0-rc.11`. Existing `0.1.0`
values remain compatible.

### 0.1.0 (initial release)

No prior chart version. New deployments only. The published manifests
under `server/k8s/*.yaml` continue to be supported as a raw-manifest path;
they remain the explicit "no Helm" alternative. Migrating from the raw
manifests to the chart is a clean import:

```bash
# 1. Capture the live ConfigMap and Secrets so the chart can consume them.
kubectl -n durable-workflow get configmap durable-workflow-config -o yaml > existing-config.yaml
kubectl -n durable-workflow get secret durable-workflow-app-secrets -o yaml > existing-app-secret.yaml

# 2. Install the chart with auth.existingSecret pointing at the live Secret.
#    Set fullnameOverride: durable-workflow so the chart adopts the
#    existing resource names rather than introducing new ones.
helm install durable-workflow ./k8s/helm/durable-workflow \
  --namespace durable-workflow \
  --set fullnameOverride=durable-workflow \
  --set auth.existingSecret=durable-workflow-app-secrets \
  --set externalDatabase.existingSecret=durable-workflow-database \
  --set externalRedis.existingSecret=durable-workflow-redis \
  -f migrate-from-raw.yaml
```

The chart will adopt the existing names. Helm's "resource exists, was not
created by Helm" error is handled by `helm install --take-ownership` (Helm
3.16+) or by deleting the raw resources first.

## Upgrading the server image alone

Bumping the image without bumping the chart is supported when the image's
memo-storage capability and the running chart version permit it (see the table
at the top of this file). Official Server release tags are classified by the
chart. A digest or custom image must include `image.memoPayloadStorage` so a
later chart upgrade can validate the retained override:

```bash
helm upgrade durable-workflow ./k8s/helm/durable-workflow \
  --reuse-values \
  --set image.tag=2.0.0-rc.50

helm upgrade durable-workflow ./k8s/helm/durable-workflow \
  --reuse-values \
  --set image.digest=sha256:<manifest-digest> \
  --set image.memoPayloadStorage=dual-v1
```

Crossing a server MINOR/MAJOR may require a chart upgrade if the image
expects a new env var or migration. Check the server release notes and
this guide together.

## Rollback

```bash
helm rollback durable-workflow <revision> --namespace durable-workflow --wait
```

Helm rollback re-runs the bootstrap Job by default. If a database migration
is not safely reversible, restore from the backup taken in step 2 of the
upgrade procedure before rolling the workloads back. The single-node
upgrade order on the deployment guide also applies here:
back up first, then change image refs. A rollback that restores an opaque image
also restores its recorded `image.memoPayloadStorage`; correct or remove a
stale declaration before retrying. Never declare `dual-v1` for the
envelope-only `2.0.0-rc.47` or `2.0.0-rc.48` images.
