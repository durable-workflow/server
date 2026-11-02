# Release candidate 3 profiles

The small-host profiles with `artifacts.native_image` set to the published
release candidate:

- [small-host.json](small-host.json) is [../small-host/profile.json](../small-host/profile.json).
- [matched-4c.json](matched-4c.json) is [../small-host/matched-4c.json](../small-host/matched-4c.json).

The candidate is `ghcr.io/durable-workflow/server@sha256:899b404b02f856cb582c5e77a95882a3f9928781083d1e7a3fe569f3ff978d6b`
(tag `3.0.0-rc.3`, source `04cad60ed661db2c9e961d85e7a132d82ee62e46`). The
same index is published as `durableworkflow/server:3.0.0-rc.3`.

What differs from the originals is the rc.3 identity:

- both files: `native_image`, `native_version` and `profile_id`;
- `small-host.json`: `artifacts.native_source` (`04cad60e`), the schema note
  (rc.3 uses schema 8, where rc.2 used 7), and `planned_hosts.comparison`,
  which now names the sibling `matched-4c.json`;
- `matched-4c.json`: `artifacts.native_image_source` (`04cad60e`), and the
  first limitation, which now says the image is the published rc.3 artifact.

`matched-4c.json` keeps `native_source`, `native_build_runner_revision`,
`native_rust_tree` and `native_binary_sha256` unchanged. They describe the
separate October 20 binary-mounted mode, as its limitations say, not
native-image runs. The workload, limits, probe and adapter pins, SDK lock and
PHP reference (published Server 2.11.8) are unchanged.

The originals stay as they are because the November 2 observations in
[../small-host/](../small-host/) cite their `profile_sha256`. Run these with
the same scripts and instructions as the
[small-host README](../small-host/README.md), passing this directory's file
as the profile.
