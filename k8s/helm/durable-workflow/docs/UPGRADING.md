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

Bumping the image without bumping the chart is supported when both the
chart's `appVersion` claim and the running chart version permit it (see the
table at the top of this file):

```bash
helm upgrade durable-workflow ./k8s/helm/durable-workflow \
  --reuse-values \
  --set image.tag=0.3.1
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
back up first, then change image refs.
