"""Inert first-RUN APT boundary controls; no actual APT or image execution."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
APT = ROOT / "docker/apt"
SPEC = importlib.util.spec_from_file_location("php_apt_source", ROOT / "scripts/ci/verify-php-apt-source.py")
CHECK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECK)


def execute_guard(*, version="3.0.4", library=None, status="install ok installed",
                  architecture="amd64", release='"13"', distro="debian",
                  client=None, apt_record=None, query_fails=False, compare_fails=False, args=()):
    """Replace readbacks in a temporary source adapter, never in production."""
    if library is None:
        library = version
    if client is None:
        client = f"apt {version} ({architecture})\nSupported modules: synthetic\n"
    with tempfile.TemporaryDirectory(prefix="server-apt-source-") as temporary:
        work = Path(temporary)
        (work / "os-release").write_text(f"ID={distro}\nVERSION_ID={release}\n")
        (work / "apt-record").write_text(apt_record if apt_record is not None else f"{status}\t{version}\n")
        (work / "library-record").write_text(f"{status}\t{library}\n")
        (work / "client-record").write_text(client)
        query = work / "query"
        query.write_text(f'''#!/bin/sh
/usr/bin/env > '{work}/environment'
{'exit 7' if query_fails else ':'}
case "$3" in
  apt) /bin/cat '{work}/apt-record' ;;
  libapt-pkg7.0) /bin/cat '{work}/library-record' ;;
  *) exit 91 ;;
esac
''')
        dpkg = work / "dpkg"
        dpkg.write_text(f'''#!/bin/sh
case "$1" in
  --print-architecture) printf '%s\\n' '{architecture}' ;;
  --compare-versions) {'exit 8' if compare_fails else 'exec /usr/bin/dpkg "$@"'} ;;
  *) exit 92 ;;
esac
''')
        apt = work / "apt"
        apt.write_text(f'''#!/bin/sh
printf '%s\\n' "$*" >> '{work}/apt-calls'
[ "$#" -eq 1 ] && [ "$1" = --version ] || exit 93
/bin/cat '{work}/client-record'
''')
        for path in (query, dpkg, apt):
            path.chmod(0o700)
        adapter = work / "guard.sh"
        adapter.write_text((APT / "require-fixed-apt.sh").read_text()
                           .replace("/etc/os-release", str(work / "os-release"))
                           .replace("/usr/bin/dpkg-query", str(query))
                           .replace("/usr/bin/dpkg", str(dpkg))
                           .replace("/usr/bin/apt-get", str(apt)))
        result = subprocess.run(["/usr/bin/env", "-i", "PATH=/usr/bin:/bin", "LC_ALL=C", "/bin/sh",
                                 str(adapter), *args], capture_output=True, text=True, timeout=5,
                                env={**os.environ, "GH_TOKEN": "synthetic-not-a-credential",
                                     "DPKG_ROOT": "/synthetic-override", "APT_CONFIG": "/synthetic-config"})
        environment = (work / "environment").read_text() if (work / "environment").exists() else ""
        calls = (work / "apt-calls").read_text() if (work / "apt-calls").exists() else ""
        return result, environment, calls


class PhpAptTest(unittest.TestCase):
    def test_both_architectures_accept_exact_floor_and_later_fixed_pairs(self):
        for architecture in ("amd64", "arm64"):
            for version in ("3.0.4", "3.0.4+deb13u1", "3.0.5"):
                with self.subTest(architecture=architecture, version=version):
                    result, _, calls = execute_guard(architecture=architecture, version=version)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertIn(f"arch={architecture} apt={version}", result.stdout)
                    self.assertEqual(calls, "--version\n")

    def test_old_prerelease_mixed_malformed_and_unconfigured_records_refuse_before_client(self):
        bad = [dict(version="3.0.3"), dict(version="3.0.4~rc1"), dict(library="3.0.3"),
               dict(version=""), dict(version="3.0.4\ninstalled\t3.0.4"),
               dict(status="deinstall ok config-files"), dict(status="install reinstreq installed"),
               dict(apt_record="install ok installed\t3.0.4\ninstall ok installed\t3.0.4\n"),
               dict(apt_record="3.0.4\n"), dict(query_fails=True), dict(compare_fails=True)]
        for values in bad:
            with self.subTest(values=values):
                result, _, calls = execute_guard(**values)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(calls, "")

    def test_unknown_duplicate_or_missing_distro_release_architecture_refuse(self):
        for values in (dict(distro="ubuntu"), dict(distro="debian\nID=debian"), dict(distro=""),
                       dict(release='"12"'), dict(release='"13"\nVERSION_ID="13"'),
                       dict(architecture="i386"), dict(architecture="")):
            with self.subTest(values=values):
                result, _, calls = execute_guard(**values)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(calls, "")

    def test_effective_client_must_match_the_installed_package_and_architecture(self):
        for client in ("apt 3.0.3 (amd64)\n", "apt 3.0.4 (arm64)\n", "", " apt 3.0.4 (amd64)\n"):
            with self.subTest(client=client):
                result, _, _ = execute_guard(client=client)
                self.assertNotEqual(result.returncode, 0)

    def test_no_caller_overrides_or_ambient_credentials_reach_readbacks(self):
        result, _, calls = execute_guard(args=("--floor", "0"))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, "")
        result, environment, _ = execute_guard()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertLessEqual({row.split("=", 1)[0] for row in environment.splitlines()}, {"PATH", "LC_ALL", "PWD"})

    def test_source_binding_rejects_old_unproved_parent_inventory_and_guard(self):
        source = (ROOT / "Dockerfile").read_text()
        proof = (APT / "parent.v1.json").read_bytes()
        guard = (APT / "require-fixed-apt.sh").read_bytes()
        CHECK.require_source_boundary(source, proof, guard)
        for changed in (source.replace(CHECK.INDEX, "sha256:d89e8b3d2033c3179dcd9999d913670eb12a07410a95e60205c64e7d0fac7aef"),
                        source.replace(CHECK.INDEX, "sha256:" + "0" * 64)):
            with self.assertRaisesRegex(ValueError, "unproved PHP parent"):
                CHECK.require_source_boundary(changed, proof, guard)
        value = json.loads(proof)
        for key in ("child", "config", "spdx", "packages"):
            changed = json.loads(proof)
            changed["platforms"]["linux/amd64"][key] = "unproved"
            with self.assertRaisesRegex(ValueError, "unreviewed parent inventory"):
                CHECK.require_source_boundary(source, json.dumps(changed).encode(), guard)
        self.assertEqual(set(value["platforms"]), {"linux/amd64", "linux/arm64"})
        with self.assertRaisesRegex(ValueError, "unreviewed effective-client guard"):
            CHECK.require_source_boundary(source, proof, guard + b"\nexit 0\n")

    def test_first_run_stage_copy_and_inheritance_cannot_be_bypassed(self):
        source = (ROOT / "Dockerfile").read_text()
        proof = (APT / "parent.v1.json").read_bytes()
        guard = (APT / "require-fixed-apt.sh").read_bytes()
        for changed in (source.replace(CHECK.PREFIX + " \\\n    && ", "", 1),
                        source.replace("RUN " + CHECK.PREFIX, "RUN apt-get update\nRUN " + CHECK.PREFIX, 1),
                        source.replace("FROM base AS production", "FROM scratch AS production"),
                        source.replace("COPY docker/apt/require-fixed-apt.sh " + CHECK.GUARD, ""),
                        source.replace("FROM base AS production", "FROM base AS production\nRUN apt-get update"),
                        source.replace("RUN " + CHECK.PREFIX, "run apt-get update\nRUN " + CHECK.PREFIX, 1),
                        source.replace("RUN " + CHECK.PREFIX, "COPY foreign " + CHECK.GUARD + "\nRUN " + CHECK.PREFIX, 1)):
            with self.subTest(changed=changed[:60]), self.assertRaises(ValueError):
                CHECK.require_source_boundary(changed, proof, guard)


if __name__ == "__main__":
    unittest.main()
