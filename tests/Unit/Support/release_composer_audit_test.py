"""The actual release-audit shell must refuse before making an unsafe request."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
AUDIT = ROOT / "scripts/ci/audit-immutable-release-container.sh"
WRAPPER = ROOT / "scripts/ci/audit-immutable-release.sh"


class ReleaseComposerAuditTest(unittest.TestCase):
    def setUp(self) -> None:
        temporary = tempfile.TemporaryDirectory(prefix="dw-release-audit-test-")
        self.addCleanup(temporary.cleanup)
        self.work = Path(temporary.name)
        self.bin = self.work / "bin"
        self.bin.mkdir()
        self.log = self.work / "commands.jsonl"
        self.ca = self.work / "ca.pem"
        self.ca.write_text("constructed checked bundle\n")
        self.capath = self.work / "empty-ca"
        self.capath.mkdir()
        self.env = {
            **os.environ,
            "PATH": str(self.bin) + os.pathsep + os.environ["PATH"],
            "AUDIT_TEST_LOG": str(self.log),
            "COMPOSER_CAFILE": str(self.ca),
            "COMPOSER_CAPATH": str(self.capath),
        }
        self.executable("apk", '''
import json, os, sys
with open(os.environ["AUDIT_TEST_LOG"], "a") as output:
    output.write(json.dumps(["apk", *sys.argv[1:]]) + "\\n")
raise SystemExit(int(os.environ.get("APK_ADD_STATUS" if sys.argv[1] == "add" else "APK_INFO_STATUS", "0")))
''')
        self.executable("su-exec", '''
import json, os, sys
with open(os.environ["AUDIT_TEST_LOG"], "a") as output:
    output.write(json.dumps(["su-exec", *sys.argv[1:]]) + "\\n")
os.execvp(sys.argv[2], sys.argv[2:])
''')
        self.executable("composer", '''
import json, os, sys
with open(os.environ["AUDIT_TEST_LOG"], "a") as output:
    output.write(json.dumps(["composer", *sys.argv[1:]]) + "\\n")
raise SystemExit(int(os.environ.get("COMPOSER_STATUS", "0")))
''')

    def executable(self, name: str, body: str) -> None:
        path = self.bin / name
        path.write_text("#!/usr/bin/env python3\n" + body.lstrip())
        path.chmod(0o755)

    def run_audit(self, source: str | None = None, identity: str = "1000:1000") -> subprocess.CompletedProcess[str]:
        script = AUDIT
        if source is not None:
            script = self.work / "mutated-audit.sh"
            script.write_text(source)
        return subprocess.run(["sh", str(script), identity], env=self.env, capture_output=True, text=True)

    def commands(self) -> list[list[str]]:
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def assert_safe_order(self, result: subprocess.CompletedProcess[str]) -> None:
        self.assertEqual(0, result.returncode, result.stderr)
        commands = self.commands()
        self.assertEqual(["apk", "apk", "su-exec", "composer"], [command[0] for command in commands])
        self.assertEqual(["apk", "add", "--no-cache", "--upgrade", "brotli-libs>=1.2.1-r0", "su-exec"], commands[0])
        self.assertEqual(["apk", "info", "-e", "brotli-libs>=1.2.1-r0"], commands[1])
        self.assertEqual("1000:1000", commands[2][1])
        self.assertEqual(["composer", "--no-plugins", "--no-scripts", "audit", "--locked", "--no-dev", "--no-interaction"], commands[3])

    def test_installs_and_verifies_floor_before_nonprivileged_locked_audit(self) -> None:
        self.assert_safe_order(self.run_audit())

    def test_failed_upgrade_cannot_reach_composer(self) -> None:
        self.env["APK_ADD_STATUS"] = "23"
        self.assertEqual(23, self.run_audit().returncode)
        self.assertEqual(["apk"], [command[0] for command in self.commands()])

    def test_unmet_floor_cannot_reach_composer(self) -> None:
        self.env["APK_INFO_STATUS"] = "24"
        self.assertEqual(24, self.run_audit().returncode)
        self.assertEqual(["apk", "apk"], [command[0] for command in self.commands()])

    def test_audit_failure_keeps_its_exit_code(self) -> None:
        self.env["COMPOSER_STATUS"] = "7"
        self.assertEqual(7, self.run_audit().returncode)
        self.assertEqual("composer", self.commands()[-1][0])

    def test_root_or_malformed_identity_refuses_before_download(self) -> None:
        for identity in ["0:0", "0:1000", "1000:0", "1000", "1000:1000:1", "1000:abc", "-1:1000"]:
            with self.subTest(identity):
                self.log.unlink(missing_ok=True)
                self.assertNotEqual(0, self.run_audit(identity=identity).returncode)
                self.assertEqual([], self.commands())

    def test_missing_or_nonempty_ca_pair_refuses_before_package_download(self) -> None:
        self.env.pop("COMPOSER_CAFILE")
        self.assertNotEqual(0, self.run_audit().returncode)
        self.assertEqual([], self.commands())
        self.env["COMPOSER_CAFILE"] = str(self.ca)
        (self.capath / "extra.pem").write_text("unexpected root")
        self.assertNotEqual(0, self.run_audit().returncode)
        self.assertEqual([], self.commands())

    def test_removing_or_moving_the_floor_is_caught(self) -> None:
        source = AUDIT.read_text()
        floor = "apk info -e 'brotli-libs>=1.2.1-r0' >/dev/null\n"
        self.assertIn(floor, source)
        for mutated in [source.replace(floor, ""), source.replace(floor, "") + floor]:
            with self.subTest(source=mutated):
                self.log.unlink(missing_ok=True)
                result = self.run_audit(mutated)
                with self.assertRaises(AssertionError):
                    self.assert_safe_order(result)

    def prepare_wrapper(self) -> None:
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:P-256",
            "-nodes", "-keyout", str(self.work / "test.key"), "-out", str(self.ca),
            "-subj", "/CN=release-audit-constructed-ca", "-days", "1",
        ], check=True, capture_output=True)
        self.env["AUDIT_TEST_CA"] = str(self.ca)
        self.env["RUNNER_TEMP"] = str(self.work)
        self.executable("docker", '''
import json, os, pathlib, sys
args = sys.argv[1:]
with open(os.environ["AUDIT_TEST_LOG"], "a") as output:
    output.write(json.dumps(["docker", *args]) + "\\n")
if "cat" in args:
    print(pathlib.Path(os.environ["AUDIT_TEST_CA"]).read_text(), end="")
raise SystemExit(0)
''')

    def test_wrapper_checks_bundle_and_binds_source_and_trust_read_only(self) -> None:
        self.prepare_wrapper()
        result = subprocess.run(["sh", str(WRAPPER), str(self.work)], env=self.env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        commands = self.commands()
        self.assertEqual(2, len(commands))
        self.assertIn("none", commands[0])
        args = commands[1]
        self.assertIn("COMPOSER_CAFILE=/release-trust/ca.pem", args)
        self.assertIn("COMPOSER_CAPATH=/release-trust/no-ca-directory", args)
        self.assertIn("SSL_CERT_FILE=/release-trust/ca.pem", args)
        self.assertIn("SSL_CERT_DIR=/release-trust/no-ca-directory", args)
        mounts = [args[index + 1] for index, value in enumerate(args) if value == "--mount"]
        self.assertEqual(3, len(mounts))
        self.assertTrue(all(mount.endswith(",readonly") for mount in mounts))
        trust = next(mount for mount in mounts if ",dst=/release-trust," in mount)
        directory = trust.split("src=", 1)[1].split(",", 1)[0]
        self.assertFalse(Path(directory).exists(), "the disposable CA directory must be retired")
        self.assertEqual([], list(self.work.glob("release-audit.*")))

    def test_bad_bundle_refuses_before_starting_networked_audit(self) -> None:
        self.prepare_wrapper()
        self.ca.write_text("not a certificate bundle\n")
        result = subprocess.run(["sh", str(WRAPPER), str(self.work)], env=self.env, capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(1, len(self.commands()))
        self.assertEqual([], list(self.work.glob("release-audit.*")))


if __name__ == "__main__":
    unittest.main()
