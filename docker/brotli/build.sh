#!/bin/sh
# Debian 13 has no fixed libbrotli1 for CVE-2026-111497 yet. Rebuild
# Debian's own brotli 1.1.0-2 source with upstream's fix for the 1.1 line
# (google/brotli da9806362874b9bd4b552a3af360b5d6347beb12, released as
# v1.1.1) for the platform being built. The source comes through apt's signed
# Sources index and must also match the hashes in source.sha256. The package
# keeps Debian's file layout and names itself 1.1.0-2+b7+dw1: above the
# trixie build it replaces, below any future Debian fix (+deb13uN).
set -eu

here=$(dirname "$(readlink -f "$0")")
version=$(cat "$here/version")
work=/usr/src/server-brotli-build

sed -i 's/^Types: deb$/Types: deb deb-src/' /etc/apt/sources.list.d/debian.sources
apt-get update
apt-get install -y --no-install-recommends dpkg-dev
mkdir -p "$work" && cd "$work"
apt-get source --download-only brotli=1.1.0-2
sha256sum -c "$here/source.sha256"
dpkg-source -x brotli_1.1.0-2.dsc brotli-1.1.0
cd brotli-1.1.0
apt-get build-dep -y --no-install-recommends -P nopython .

cp "$here/CVE-2026-111497.patch" debian/patches/
echo CVE-2026-111497.patch >> debian/patches/series
{
    printf 'brotli (%s) trixie-security; urgency=high\n\n' "$version"
    printf '  * Durable Workflow rebuild: apply upstream da98063 (v1.1.1),\n'
    printf '    "Detect and report invalid stream earlier." (CVE-2026-111497).\n\n'
    printf ' -- Durable Workflow <support@durable-workflow.com>  Thu, 22 Oct 2026 00:00:00 +0000\n\n'
    cat debian/changelog
} > debian/changelog.new
mv debian/changelog.new debian/changelog

DEB_BUILD_PROFILES=nopython DEB_BUILD_OPTIONS='nocheck noddebs' dpkg-buildpackage -B -uc -us -Pnopython
mkdir -p /out
cp "$work/libbrotli1_${version}_$(dpkg --print-architecture).deb" /out/
dpkg-deb --field "/out/libbrotli1_${version}_$(dpkg --print-architecture).deb" Package Version
