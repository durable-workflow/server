# Durable Workflow Helm

This directory holds the self-serve Helm chart for the Durable Workflow
standalone server, together with the example values files and CI fixtures.

* `durable-workflow/` — the chart itself. See its
  [README](durable-workflow/README.md) and
  [UPGRADING guide](durable-workflow/docs/UPGRADING.md).
* `examples/` — copy-and-adapt values files for the most common shapes
  (production with externally-managed secrets, dev, External Secrets
  Operator companion, etc.).
* `durable-workflow/ci/` — minimum-viable values fixtures used by chart-CI
  (`helm lint`, `helm template`, `ct lint-and-install`).

The chart is the supported Helm path for the deployment matrix at
<https://durable-workflow.github.io/docs/2.0/deployment>. The raw manifests
in [`../`](..) remain a supported, lower-level alternative; both share the
same external-persistence contract and the same singleton-scheduler
invariant. Pick one or the other per environment, not both.
