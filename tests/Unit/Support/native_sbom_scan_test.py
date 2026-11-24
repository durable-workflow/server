"""Synthetic Docker responses for the confined SBOM scanner boundary."""
import copy
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[3] / "scripts/ci"))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import native_sbom_scan as scan
from native_alias_evidence import Refusal as DockerRefusal
from native_sbom_archive_test import archive, fixture
from native_sbom_policy import Refusal as SbomRefusal


SCOPE = "general-native-sbom-test"
OWNER = "dev.durable-workflow.qualification.scope"
VERSION_ID, SCAN_ID = "1" * 64, "2" * 64
VOLUME_ID = "3" * 64
ENVIRONMENT = ["HOME=/tmp", "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"]
TMPFS = "rw,noexec,nosuid,nodev,size=512m,mode=1777"
PIN = json.loads((scan.ROOT / "tool-pins.v1.json").read_bytes())["generator"]
HOST = {"NetworkMode": "none", "IpcMode": "none", "ReadonlyRootfs": True, "Privileged": False,
        "CapDrop": ["ALL"], "SecurityOpt": ["no-new-privileges"], "PortBindings": {},
        "PublishAllPorts": False, "Memory": 1610612736, "MemorySwap": 1610612736,
        "NanoCpus": 1000000000, "PidsLimit": 64, "Tmpfs": {"/tmp": TMPFS},
        "LogConfig": {"Type": "none", "Config": {}}}
RAW = b'{"spdxVersion":"SPDX-2.3"}\n'


class DockerScript:
    def __init__(self, test, steps=()):
        self.test = test
        self.steps = list(steps)

    def run(self, args, limit=None):
        self.test.assertTrue(self.steps, args)
        expected_args, result, expected_limit = self.steps.pop(0)
        self.test.assertEqual(expected_args, args)
        if expected_limit is not None:
            self.test.assertEqual(expected_limit, limit)
        if isinstance(result, Exception):
            raise result
        return result if isinstance(result, bytes) else json.dumps(result).encode()

    def finished(self):
        self.test.assertFalse(self.steps)


class SbomScannerTest(unittest.TestCase):
    def setUp(self):
        uid, gid = (scan.os.geteuid(), scan.os.getegid())
        # Root cannot start the real scanner. Source tests can simulate the job user.
        self.uid, self.gid = uid or 1001, gid or 1001
        self.user = f"{self.uid}:{self.gid}"
        config_uid = patch.object(scan.os, "geteuid", return_value=self.uid)
        config_gid = patch.object(scan.os, "getegid", return_value=self.gid)
        config_uid.start()
        config_gid.start()
        self.addCleanup(config_uid.stop)
        self.addCleanup(config_gid.stop)
        if not uid:
            # Only the archive seam is replaced in root-run source tests.
            archive_seam = patch.object(scan, "consume_owned_oci_export", side_effect=self.consume_fixture)
            archive_seam.start()
            self.addCleanup(archive_seam.stop)

    @staticmethod
    def consume_fixture(directory, expected_config, platform, consumer):
        from native_sbom_archive import oci_archive_identity
        from io import BytesIO

        path = directory / "input.oci.tar"
        return oci_archive_identity(BytesIO(path.read_bytes()), expected_config, platform), consumer(path)

    def image(self, platform, use_descriptor=False):
        selected = PIN["platforms"][platform]
        child, config = selected["child_manifest_digest"], selected["image_config_digest"]
        reference = "docker.io/anchore/syft@" + child
        identity = child if use_descriptor else config
        data = {"Id": identity, "Os": "linux", "Architecture": platform.split("/")[1],
                "RepoDigests": ["anchore/syft@" + child],
                "Config": {"Entrypoint": ["/syft"], "Volumes": None}}
        if use_descriptor:
            data["Descriptor"] = {"digest": child}
        args = ["docker", "image", "inspect", "--platform", platform, reference]
        return reference, identity, (args, [data], None)

    def operation(self, operation, identity, reference, platform, directory, result, mutate=None):
        container = VERSION_ID if operation == "version" else SCAN_ID
        mounts = [("/syft.yaml", scan.CONFIG)]
        args = ["--config", "/syft.yaml", operation]
        output_limit = 4096
        if operation == "version":
            args += ["-o", "json"]
        else:
            mounts.append(("/input.oci.tar", directory / "input.oci.tar"))
            args += ["--from", "oci-archive", "--platform", platform, "--scope", "squashed",
                     "-o", "spdx-json@2.3", "/input.oci.tar"]
            output_limit = 16 * 1024 * 1024
        create = [
            "docker", "create", "--name", SCOPE + "-" + operation, "--label", OWNER + "=" + SCOPE,
            "--platform", platform, "--pull=never", "--network=none", "--ipc=none", "--read-only",
            "--user", self.user, "--cap-drop=ALL", "--security-opt=no-new-privileges",
            "--env", ENVIRONMENT[0], "--env", ENVIRONMENT[1], "--tmpfs", "/tmp:" + TMPFS,
            "--workdir=/tmp", "--cpus=1", "--memory=1536m", "--memory-swap=1536m", "--pids-limit=64",
            "--log-driver=none", "--entrypoint=/syft",
        ]
        for target, path in mounts:
            create += ["--mount", f"type=bind,src={path},dst={target},readonly"]
        observed = {
            "Id": container, "Name": f"/{SCOPE}-{operation}", "Image": identity,
            "Config": {"Image": reference, "User": self.user, "Entrypoint": ["/syft"], "Cmd": args,
                       "Labels": {OWNER: SCOPE}, "Env": list(ENVIRONMENT), "WorkingDir": "/tmp"},
            "HostConfig": copy.deepcopy(HOST),
            "Mounts": [{"Type": "bind", "Source": str(path), "Destination": target, "RW": False}
                       for target, path in mounts],
        }
        steps = [(create + [reference, *args], (container + "\n").encode(), 128),
                 (["docker", "container", "inspect", container], [observed], None)]
        if mutate:
            mutate(observed)
        else:
            steps.append((["docker", "container", "start", "--attach", container], result, output_limit))
        return steps

    def cleanup_inventory(self, owned, named):
        selector = ["--filter", "label=" + OWNER + "=" + SCOPE]
        networks = ["docker", "network", "ls", "-q", "--no-trunc", *selector]
        containers = ["docker", "container", "ls", "-aq", "--no-trunc"]
        volumes = ["docker", "volume", "ls", "-q", *selector]
        return [([*containers, *selector], owned, None),
                ([*containers, "--filter", f"name=^/{SCOPE}-(version|scan)$"], named, None),
                (networks, b"", None), (volumes, b"", None)]

    def cleanup(self, anonymous_mount=False, volume_after_removal=b""):
        containers = f"{VERSION_ID}\n{SCAN_ID}\n".encode()
        steps = self.cleanup_inventory(containers, containers)
        for identity, operation in ((VERSION_ID, "version"), (SCAN_ID, "scan")):
            mounts = [{"Type": "volume", "Name": VOLUME_ID}] if anonymous_mount and operation == "scan" else []
            steps.append((["docker", "container", "inspect", identity],
                          [{"Id": identity, "Name": f"/{SCOPE}-{operation}",
                            "Config": {"Labels": {OWNER: SCOPE}}, "Mounts": mounts}], None))
        if anonymous_mount:
            steps.append((["docker", "volume", "inspect", VOLUME_ID],
                          [{"Name": VOLUME_ID, "Labels": {"com.docker.volume.anonymous": ""}}], None))
        steps.append((["docker", "container", "rm", "-f", "-v", VERSION_ID, SCAN_ID], b"", None))
        steps.extend(self.cleanup_inventory(b"", b""))
        if anonymous_mount:
            steps.append((["docker", "volume", "ls", "-q", "--filter", f"name=^{VOLUME_ID}$"],
                          volume_after_removal, None))
        return DockerScript(self, steps)

    def version(self, platform):
        return {"application": "syft", "version": PIN["version"],
                "gitCommit": PIN["upstream_source_commit"], "platform": platform}

    def test_both_platforms_use_pinned_scanner_with_exact_confinement_and_cleanup(self):
        for platform, use_descriptor in (("linux/amd64", False), ("linux/arm64", True)):
            with self.subTest(platform=platform), TemporaryDirectory() as tmp:
                path = Path(tmp)
                files, config = fixture(platform)
                (path / "input.oci.tar").write_bytes(archive(files))
                reference, identity, image = self.image(platform, use_descriptor)
                steps = [image, *self.operation("version", identity, reference, platform, path,
                                                self.version(platform)),
                         *self.operation("scan", identity, reference, platform, path, RAW)]
                commands, cleanup = DockerScript(self, steps), self.cleanup()
                receipt, raw = scan.collect_spdx(path, config, platform, SCOPE, commands, cleanup)
                self.assertEqual(RAW, raw)
                self.assertEqual(reference, receipt["scanner_image"])
                self.assertEqual(config, receipt["export"]["image_config_digest"])
                self.assertTrue(receipt["cleanup"]["verified_absent"])
                commands.finished()
                cleanup.finished()

    def test_unexpected_effective_environment_mount_or_privilege_refuses_before_start(self):
        variants = {
            "sbom_scanner_environment": lambda data: data["Config"]["Env"].append("EXTRA=unexpected"),
            "sbom_scanner_mounts": lambda data: data["Mounts"][0].update(RW=True),
            "sbom_scanner_confinement": lambda data: data["HostConfig"].update(Privileged=True),
            "sbom_scanner_container": lambda data: data.update(Image="sha256:" + "9" * 64),
        }
        platform = "linux/amd64"
        for reason, mutate in variants.items():
            with self.subTest(reason=reason), TemporaryDirectory() as tmp:
                path = Path(tmp)
                files, config = fixture(platform)
                (path / "input.oci.tar").write_bytes(archive(files))
                reference, identity, image = self.image(platform)
                steps = [image, *self.operation("version", identity, reference, platform, path, None, mutate)]
                commands = DockerScript(self, steps)
                with patch.object(scan, "_cleanup_scanner") as cleanup, self.assertRaisesRegex(SbomRefusal, reason):
                    scan.collect_spdx(path, config, platform, SCOPE, commands, None)
                cleanup.assert_called_once_with(None, SCOPE)
                commands.finished()

    def test_version_refusal_keeps_original_reason_when_cleanup_fails(self):
        platform = "linux/amd64"
        with TemporaryDirectory() as tmp:
            path = Path(tmp)
            files, config = fixture(platform)
            (path / "input.oci.tar").write_bytes(archive(files))
            reference, identity, image = self.image(platform)
            wrong = {**self.version(platform), "version": "1.54.0"}
            steps = [image, *self.operation("version", identity, reference, platform, path, wrong)]
            commands = DockerScript(self, steps)
            with patch.object(scan, "_cleanup_scanner", side_effect=DockerRefusal("cleanup_not_absent")):
                with self.assertRaisesRegex(SbomRefusal, "sbom_scanner_version") as stopped:
                    scan.collect_spdx(path, config, platform, SCOPE, commands, None)
            self.assertEqual(["sbom_cleanup_failed"], stopped.exception.__notes__)
            commands.finished()

    def test_root_and_foreign_scope_refuse_before_docker(self):
        commands = DockerScript(self)
        with self.assertRaisesRegex(SbomRefusal, "sbom_scanner_scope"):
            scan.collect_spdx(Path("/fixture"), "", "linux/amd64", "foreign-scope", commands, None)
        with patch.object(scan.os, "getegid", return_value=0), self.assertRaisesRegex(SbomRefusal, "sbom_scanner_nonroot"):
            scan.collect_spdx(Path("/fixture"), "", "linux/amd64", SCOPE, commands, None)

    def test_cleanup_reads_back_only_a_verified_anonymous_mount(self):
        commands = self.cleanup(anonymous_mount=True)
        self.assertTrue(scan._cleanup_scanner(commands, SCOPE)["verified_absent"])
        commands.finished()

    def test_cleanup_requires_anonymous_mount_absence_after_container_removal(self):
        commands = self.cleanup(anonymous_mount=True, volume_after_removal=f"{VOLUME_ID}\n".encode())
        with self.assertRaisesRegex(SbomRefusal, "sbom_cleanup_not_absent"):
            scan._cleanup_scanner(commands, SCOPE)
        commands.finished()

    def test_cleanup_refuses_when_a_scanner_name_lacks_the_owner_label(self):
        commands = DockerScript(self, self.cleanup_inventory(b"", f"{VERSION_ID}\n".encode())[:2])
        with self.assertRaisesRegex(SbomRefusal, "sbom_cleanup_container_inventory"):
            scan._cleanup_scanner(commands, SCOPE)
        commands.finished()


if __name__ == "__main__":
    unittest.main()
