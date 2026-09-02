# Raw Kubernetes Manifests

These manifests are the inspectable, low-level self-serve Kubernetes starting
point. The published [Durable Workflow Helm chart](helm/durable-workflow/) is
the recommended path for most operators; the raw manifests stay supported for
teams that intentionally do not want Helm in the rollout.

Both paths share the same external-persistence contract, the same singleton
scheduler invariant, and the same `/api/ready`-based readiness contract.
Pick one or the other per environment, not both.

The checked-in manifests are synchronized with the repository's stable source
release and pin its Docker Hub tag:

```text
durableworkflow/server:2.0.3
```

Before production use, patch every workload image to the exact published tag or
digest you intend to run:

```bash
kubectl set image -n durable-workflow deploy/durable-workflow-server \
  server=durableworkflow/server:2.0.3
kubectl set image -n durable-workflow deploy/durable-workflow-worker \
  worker=durableworkflow/server:2.0.3
kubectl set image -n durable-workflow cronjob/durable-workflow-scheduler \
  scheduler=durableworkflow/server:2.0.3
```

GitHub Container Registry publishes the same release line at
`ghcr.io/durable-workflow/server:2.0.3`. Digest pinning is preferred for strict
change control.

The manifests expect you to provide:

- an external MySQL or PostgreSQL database;
- external Redis or another supported lock-capable cache backend;
- real database, Redis, worker, operator, admin, and optional named principal
  token secrets;
- an ingress, gateway, or load balancer owned by your cluster platform;
- backup, restore, monitoring, and rollout procedures for your environment.

The included contract is deliberately bounded:

- `k8s/migration-job.yaml` runs `server-bootstrap` before workloads start;
- `k8s/server-deployment.yaml` exposes `/api/health` for liveness and
  `/api/ready` for usable readiness;
- `k8s/worker-deployment.yaml` runs the queue worker;
- `k8s/scheduler-cronjob.yaml` runs recurring schedule, timeout, and retention
  maintenance;
- `k8s/secret.yaml` separates public config from app-level secrets and refers
  to externally managed database and Redis credentials.

Helm charts are now self-serve via [`helm/durable-workflow/`](helm/durable-workflow/);
managed-Kubernetes provider validation, advanced HA, active/active multi-region,
custom operators, storage classes, network policies, and environment-specific
security hardening are support-led or tracked separately.
Active/passive multi-region with operator-driven regional failover follows the
contract in [`docs/multi-region-validation.md`](../docs/multi-region-validation.md);
each region still runs the documented single-region or small-cluster shape, and
this manifest contract does not add automatic cross-region orchestration. Use
overlays or direct
patches for namespace, image, resource, replica, ingress, and secret-manager
integration choices.
