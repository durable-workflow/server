The PHP production Dockerfile pins the official PHP 8.3.36 Apache/Trixie index
in `parent.v1.json`. Both selected platform inventories report APT and libapt
3.0.4, the Debian 13 fix for
[CVE-2026-109518](https://lists.debian.org/debian-security-announce/2026/msg00526.html).
The recorded child, config, companion and complete SPDX predicate hashes were
independently checked, including each statement's child subject. These public
metadata facts do not constitute an installed-image or build qualification.

Before the first command in both the base and production stages, the Dockerfile
runs `require-fixed-apt.sh` through an absolute, empty environment. It reads the
actual Debian release, architecture, fully installed APT/libapt records and
effective APT client version with fixed system commands. Unknown, missing,
mixed, unconfigured or below-floor observations refuse before a package
operation. The helper has no inputs, download or self-upgrade path.

Downstream source consumers can run `python3 scripts/ci/verify-php-apt-source.py`
against their selected Server checkout before a build. It checks the reviewed
parent/guard and complete Dockerfile bytes, literal parent and inherited
first-RUN ordering. A changed Dockerfile requires renewed review and binding.
It performs no network, image or package operation and reports no installed observation.
Consumers must additionally bind the exact accepted Server commit and verifier
bytes through their reviewed source authority. A passing source prerequisite
does not authorize a build, publication or runtime operation.

The source guard deliberately covers this production Dockerfile. The separate
feature-workflow parent and historical performance/conformance Dockerfiles have
their own subjects and acceptance records. The historical parents remain
unqualified for future networked APT until their own prerequisites are met.
