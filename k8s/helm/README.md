# Durable Workflow Helm

This directory holds the self-serve Helm chart for the Durable Workflow
standalone server, together with the example values files and CI fixtures.

* `durable-workflow/` — the chart itself. See its
  [README](durable-workflow/README.md) and
  [UPGRADING guide](durable-workflow/docs/UPGRADING.md).
* `examples/` — copy-and-adapt values files. `values-native-dev.yaml` runs
  the native Server, the chart default. The others keep an existing PHP 2.x
  installation (`runtime: php`): production with externally-managed secrets,
  dev, and an External Secrets Operator companion.
* `durable-workflow/ci/` — minimum-viable values fixtures used by chart-CI
  (`helm lint`, `helm template`, `ct lint-and-install`).

The chart is the supported Helm path for the deployment matrix at
<https://durable-workflow.github.io/docs/2.0/deployment>. The raw manifests
in [`../`](..) remain a supported, lower-level alternative; both share the
same external-persistence contract and the same singleton-scheduler
invariant. The raw manifests run the PHP 2.x Server only. Pick one or the
other per environment, not both.
