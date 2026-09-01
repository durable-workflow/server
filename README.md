# Durable Workflow Server

[![Tests](https://github.com/durable-workflow/server/actions/workflows/phpunit-feature.yml/badge.svg?branch=main)](https://github.com/durable-workflow/server/actions/workflows/phpunit-feature.yml)
[![Release](https://img.shields.io/github/v/release/durable-workflow/server)](https://github.com/durable-workflow/server/releases/latest)
[![Docker Pulls](https://img.shields.io/docker/pulls/durableworkflow/server)](https://hub.docker.com/r/durableworkflow/server)
[![License](https://img.shields.io/github/license/durable-workflow/server)](LICENSE)

Durable Workflow Server is the self-hosted, language-neutral runtime for
durable workflows. It records workflow state and history, matches tasks to
workers, fires timers and schedules, manages namespaces, and resumes execution
after process or infrastructure restarts.

Applications connect through first-party PHP, Python, and Rust SDKs. Workers
run in your application environment and can scale independently from Server.
For a managed runtime, use [Durable Workflow Cloud](https://cloud.durable-workflow.com/).
Laravel applications can also run the engine directly in
[embedded mode](https://durable-workflow.com/docs/2.0/category/embedded/).

[Documentation](https://durable-workflow.com/docs/2.0/) | [Sample App](https://github.com/durable-workflow/sample-app) | [CLI](https://github.com/durable-workflow/cli) | [Waterline](https://github.com/durable-workflow/waterline)

## Run Server

The published Compose stack starts Server with MySQL, Redis, a queue worker,
and the scheduler. It bootstraps the database and the default namespace before
accepting traffic.

```bash
curl -fsSLO https://raw.githubusercontent.com/durable-workflow/server/main/docker-compose.published.yml

export DW_AUTH_TOKEN=dev-token
docker compose -f docker-compose.published.yml up -d --wait

curl http://localhost:8080/api/health
curl http://localhost:8080/api/ready
curl -H "Authorization: Bearer $DW_AUTH_TOKEN" \
  http://localhost:8080/api/cluster/info
```

This development configuration uses a single compatibility token. Production
deployments should use role-scoped worker, operator, and administrator
credentials and pin the Server image by version or digest. See the
[self-hosting reference](docs/server-reference.md) for SQLite, production
Compose, authentication, backup, upgrade, API, and configuration guidance.

Install the current CLI for server administration and workflow inspection:

```bash
curl -fsSL https://durable-workflow.com/install.sh | sh
export PATH="$HOME/.local/bin:$PATH"
```

## Choose an SDK

| Language | Guide and API reference | Package |
| --- | --- | --- |
| PHP | [php.durable-workflow.com](https://php.durable-workflow.com/) | [`durable-workflow/sdk`](https://packagist.org/packages/durable-workflow/sdk) |
| Python | [python.durable-workflow.com](https://python.durable-workflow.com/) | [`durable-workflow`](https://pypi.org/project/durable-workflow/) |
| Rust | [rust.durable-workflow.com](https://rust.durable-workflow.com/) | [`durable-workflow`](https://crates.io/crates/durable-workflow) |

SDK clients start and inspect workflows. SDK workers register workflow and
activity types, poll named task queues, execute user code, and report results
to Server. Type names and portable Avro values form the cross-language
contract, so a workflow in one language can dispatch activities to another.

## Deployment Paths

| Deployment | Use it for | Guide |
| --- | --- | --- |
| SQLite containers | Local evaluation with minimal infrastructure | [Official image and SQLite](docs/server-reference.md#official-image--sqlite) |
| Compose with MySQL and Redis | Development and single-host production | [Compose deployment](docs/server-reference.md#official-image--compose) |
| Small cluster | Multiple API nodes with external persistence | [Small-cluster contract](docs/small-cluster-validation.md) |
| Kubernetes | Independently scalable API and worker pools | [Helm chart](k8s/helm/durable-workflow/README.md) |
| Active/passive regions | Operator-controlled regional failover | [Multi-region contract](docs/multi-region-validation.md) |

Server supports SQLite for a single-node runtime and MySQL or PostgreSQL for
shared durable state. Multi-node deployments use shared Redis for queue and
coordination state. The database remains authoritative for workflow history.

## Capabilities

- Durable workflow and activity execution with retries, timeouts, and heartbeats
- Signals, queries, updates, timers, schedules, and child workflows
- Search attributes, memo, history export, and namespace retention policies
- Worker sessions, task-queue routing, build rollout controls, and backpressure
- Role-scoped authentication and machine-readable capability discovery
- External payload storage for large encoded values
- Waterline-compatible operational APIs and Prometheus metrics

## Reference

- [Self-hosting, HTTP API, authentication, and configuration](docs/server-reference.md)
- [Control-plane OpenAPI contract](resources/platform-protocol-specs/control-plane-api.openapi.yaml)
- [Worker protocol OpenAPI contract](resources/platform-protocol-specs/worker-protocol-api.openapi.yaml)
- [Worker stream AsyncAPI contract](resources/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml)
- [Capacity benchmark suite](benchmarks/capacity/v1/README.md)
- [Bounded-growth policy](docs/bounded-growth.md)
- [External payload storage contract](docs/contracts/external-payload-storage.md)
- [Helm upgrade guide](k8s/helm/durable-workflow/docs/UPGRADING.md)

## Development

```bash
git clone https://github.com/durable-workflow/server.git
cd server
cp .env.example .env
docker compose up -d --wait
```

Repository checks and focused test commands are documented in
[CONTRIBUTING.md](CONTRIBUTING.md). Bugs and feature requests belong in
[GitHub Issues](https://github.com/durable-workflow/server/issues).

## License

Durable Workflow Server is released under the [MIT License](LICENSE).
