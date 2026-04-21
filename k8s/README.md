# Raw Kubernetes Manifests

These manifests are the self-serve Kubernetes starting point for teams that
already operate Kubernetes. They are intentionally raw, inspectable resources
instead of a Helm chart.

The default image is pinned to the public Docker Hub release tag:

```text
durableworkflow/server:0.2
```

Before production use, patch every workload image to the exact published tag or
digest you intend to run:

```bash
kubectl set image -n durable-workflow deploy/durable-workflow-server \
  server=durableworkflow/server:0.2
kubectl set image -n durable-workflow deploy/durable-workflow-worker \
  worker=durableworkflow/server:0.2
kubectl set image -n durable-workflow cronjob/durable-workflow-scheduler \
  scheduler=durableworkflow/server:0.2
```

GitHub Container Registry publishes the same release line at
`ghcr.io/durable-workflow/server:0.2`. Digest pinning is preferred for strict
change control.

The manifests expect you to provide:

- an external MySQL or PostgreSQL database;
- external Redis or another supported lock-capable cache backend;
- real database, Redis, worker, operator, and admin secrets;
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

Helm charts, managed-Kubernetes provider validation, advanced HA, multi-region,
custom operators, storage classes, network policies, and environment-specific
security hardening are support-led or tracked separately. Use overlays or direct
patches for namespace, image, resource, replica, ingress, and secret-manager
integration choices.

