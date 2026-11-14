#!/usr/bin/env python3
"""One reviewed, disposable hosted capture for Server #1204. Never publishes."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shutil
import signal
import stat
import subprocess
import sys
import time
import uuid

ANCHOR = "688c1cddb1783529f8fc097ece08bebaeded3706"
BRANCH = "investigate/server-1204-hosted-context"
SELECTION_SHA = "5e33a068f02bdef527417b5a604ea1eb459b5ba253dd853db73b2f03125d8c9a"
ROOT = Path(__file__).resolve().parents[2]
PROFILE = ROOT / "scripts/ci/server-1204/profile.json"
SELECTION = ROOT / "scripts/ci/server-1204/native-tests-b.txt"
OWNER = "dev.durable-workflow.server1204"
ROLES = {"fetch", "database", "bootstrap", "compile", "inventory", "tests"}


def require(condition, reason):
    if not condition:
        raise RuntimeError(reason)


def sha(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def selected_tests(raw):
    require(hashlib.sha256(raw).hexdigest() == SELECTION_SHA, "selection digest changed")
    names = raw.decode("ascii").splitlines()
    require(len(names) == len(set(names)) == 405, "selection is not 405 unique tests")
    require(names == sorted(names) and all(re.fullmatch(r"[A-Za-z0-9_:]+", x) for x in names),
            "selection grammar/order changed")
    return names


def inventory(raw, expected):
    names = sorted(line.removesuffix(": test") for line in raw.splitlines() if line.endswith(": test"))
    require(len(names) == len(set(names)) == 810, "binary inventory changed")
    require(names[1::2] == expected, "binary native-tests-b differs from original membership")


def observations(log, code, expected):
    completed = []
    contexts = []
    phases = []
    for line in log.splitlines():
        _, _, body = line.partition(" ")
        match = re.fullmatch(r"test (.+) \.\.\. (ok|FAILED|ignored(?:, .*)?)", body)
        if match:
            completed.append({"test": match[1], "status": match[2].split(", ", 1)[0]})
        if body.startswith("server1204_context "):
            contexts.append(json.loads(body.removeprefix("server1204_context ")))
        if "server1204_acquire_failure" in body:
            phases.append(line)
    names = [x["test"] for x in completed]
    require(len(names) == len(set(names)) and set(names) <= set(expected), "foreign/duplicate result")
    complete = set(names) == set(expected)
    target = "worker_poll_fence::held_activity_polls_end_when_the_registration_changes"
    target_phases = {x["phase"] for x in contexts if x["test"] == target}
    registration_observed = {"database_create", "activity_reregistration_start",
                             "activity_reregistration_returned"} <= target_phases
    passed = (code == 0 and complete and sum(x["status"] == "ok" for x in completed) == 404
              and sum(x["status"] == "ignored" for x in completed) == 1 and registration_observed)
    return {"outcome": "baseline-not-reproduced" if passed else
            "test-failed" if code == 101 and any(x["status"] == "FAILED" for x in completed) else "runner-blocked",
            "exit_code": code, "completed": completed, "complete_inventory": complete,
            "target_started": "database_create" in target_phases,
            "target_registration_observed": registration_observed, "phase_observations": phases,
            "pool_timeout_observed": bool(re.search(r'phase=transition_queue|pool_timeout=true|"error_kind"\s*:\s*"pool_timeout"', log))}


def run(args, timeout=30, check=True, output=None):
    if output is None:
        return subprocess.run(args, check=check, timeout=timeout, text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    with output.open("wb") as stream:
        return subprocess.run(args, check=check, timeout=timeout, stdout=stream, stderr=subprocess.STDOUT)


def validate(source):
    profile = json.loads(PROFILE.read_text())
    require(profile["observer_commit"] == ANCHOR, "observer anchor changed")
    require(profile["test_seconds"] == 900 and profile["test_threads"] == 2, "test bounds changed")
    require(profile["runner"] == {"cpus": 2, "memory_gib": 8, "pids": 512}, "runner bounds changed")
    require(profile["database"] == {"cpus": 1, "memory_gib": 1, "pids": 256}, "DB bounds changed")
    names = selected_tests(SELECTION.read_bytes())
    require(run(["git", "-C", str(source), "rev-parse", "HEAD"]).stdout.strip() == ANCHOR,
            "observer checkout differs")
    require(not run(["git", "-C", str(source), "status", "--porcelain"]).stdout.strip(),
            "observer checkout is dirty")
    return profile, names


class Capture:
    def __init__(self, source, evidence):
        self.source = source.resolve()
        self.evidence = evidence.resolve()
        self.private = self.evidence.parent / ".server-1204-private"
        self.scope = "dw-server1204-" + "-".join(os.environ[x] for x in
                                               ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT", "GITHUB_JOB"))
        require(re.fullmatch(r"dw-server1204-[0-9]+-1-[A-Za-z0-9_-]+", self.scope), "invalid run scope")
        self.deadline = time.monotonic() + 2700
        self.network = None
        self.containers = {}
        self.nonce = None
        self.cleanup_errors = []
        self.image_ids = {}

    def write(self, name, value):
        (self.evidence / name).write_text(json.dumps(value, indent=2) + "\n")

    def docker(self, *args, timeout=30, check=True, output=None):
        remaining = self.deadline - time.monotonic()
        require(remaining > 0, "workload/cleanup deadline expired")
        return run(["docker", "--config", str(self.private / "docker"), *args],
                   timeout=min(timeout, remaining), check=check, output=output)

    def labels(self, role):
        return {OWNER: self.scope, OWNER + ".nonce": self.nonce, OWNER + ".role": role}

    def owned(self, identifier, role):
        value = json.loads(self.docker("inspect", identifier).stdout)[0]
        require(value["Id"] == identifier and all(value["Config"]["Labels"].get(k) == v
                                                   for k, v in self.labels(role).items()), "foreign container")
        return value

    def create(self, role, image, args, cpus, memory, pids, network="none", readonly=True,
               mounts=(), env=(), user=None):
        require(role in ROLES and role not in self.containers, "duplicate/unknown role")
        command = ["create", "--name", self.scope + "-" + role, "--init", "--pull=never",
                   "--cpus", str(cpus), "--memory", str(memory) + "g", "--memory-swap", str(memory) + "g",
                   "--pids-limit", str(pids), "--network", network,
                   "--user", user or f"{os.getuid()}:{os.getgid()}", "--entrypoint", args[0]]
        if readonly:
            command.append("--read-only")
        if role in {"fetch", "compile", "inventory", "tests"}:
            command += ["--workdir", "/source/rust"]
        if role == "database":
            command += ["--network-alias", "parity-mysql"]
        for k, v in self.labels(role).items():
            command += ["--label", k + "=" + v]
        for item in mounts:
            command += ["-v", item]
        for item in env:
            command += ["-e", item]
        identifier = self.docker(*command, image, *args[1:]).stdout.strip()
        require(re.fullmatch(r"[a-f0-9]{64}", identifier), "invalid container ID")
        self.containers[role] = identifier
        value = self.owned(identifier, role)
        require(value["Image"] == self.image_ids[image], "image differs from pulled pin")
        h = value["HostConfig"]
        require(h["NanoCpus"] == cpus * 10**9 and h["Memory"] == memory * 1024**3
                and h["MemorySwap"] == memory * 1024**3 and h["PidsLimit"] == pids,
                "container limits differ")
        require(h["Init"] and h["ReadonlyRootfs"] == readonly and h["NetworkMode"] == network
                and not h.get("Privileged") and not h.get("PortBindings"), "container isolation differs")
        require(value["Config"]["Entrypoint"] == [args[0]] and value["Config"]["Cmd"] == args[1:],
                "container command differs")
        expected_binds = []
        for mount in mounts:
            parts = mount.split(":")
            require(len(parts) in (2, 3), "unsupported mount grammar")
            expected_binds.append((parts[0], parts[1], len(parts) == 2 or parts[2] != "ro"))
        observed_binds = [(x["Source"], x["Destination"], x["RW"])
                          for x in value["Mounts"] if x["Type"] == "bind"]
        require(sorted(observed_binds) == sorted(expected_binds) and not h.get("VolumesFrom")
                and not h.get("Devices"), "container binds/devices differ")
        require(value["Config"]["User"] == (user or f"{os.getuid()}:{os.getgid()}"), "container user differs")
        self.write(role + "-created.json", value)
        return identifier

    def execute(self, role, seconds, allow_failure=False):
        identifier = self.containers[role]
        self.owned(identifier, role)
        self.docker("start", identifier)
        if role == "tests":
            network = json.loads(self.docker("network", "inspect", self.network).stdout)[0]
            require(network["Id"] == self.network and network["Internal"]
                    and set(network["Containers"]) <= set(self.containers.values()), "foreign network attachment")
            self.write("network-running.json", network)
        result = self.docker("wait", identifier, timeout=seconds + 15)
        code = int(result.stdout.strip())
        value = self.owned(identifier, role)
        self.write(role + "-returned.json", value)
        self.docker("logs", "--timestamps", identifier, output=self.evidence / (role + ".log"))
        require(not value["State"]["OOMKilled"] and value["State"]["ExitCode"] == code, "OOM/state mismatch")
        require(allow_failure or code == 0, role + " failed")
        return code

    def cleanup(self, receipt="cleanup.json"):
        self.deadline = time.monotonic() + 120
        selector = "label=" + OWNER + "=" + self.scope
        for identifier in self.docker("ps", "-aq", "--no-trunc", "--filter", selector).stdout.splitlines():
            try:
                value = json.loads(self.docker("inspect", identifier).stdout)[0]
                role = value["Config"]["Labels"].get(OWNER + ".role")
                require(role in ROLES, "unknown cleanup role")
                value = self.owned(identifier, role)
                self.containers[role] = identifier
                volumes = [x["Name"] for x in value["Mounts"] if x["Type"] == "volume"]
                self.write(role + "-final.json", value)
                self.docker("logs", "--timestamps", "--tail", "2000", identifier,
                            output=self.evidence / (role + "-cleanup.log"))
                if value["State"]["Running"]:
                    self.docker("stop", "--time", "10", identifier, timeout=20)
                self.owned(identifier, role)
                self.docker("rm", "-v", identifier)
                require(not self.docker("ps", "-aq", "--filter", "id=" + identifier).stdout.strip(),
                        "container ID remains")
                missing = self.docker("inspect", identifier, check=False)
                require(missing.returncode != 0 and json.loads(missing.stdout) == [], "container inspect remains")
                for volume in volumes:
                    missing = self.docker("volume", "inspect", volume, check=False)
                    require(missing.returncode != 0 and json.loads(missing.stdout) == [], "owned anonymous volume remains")
            except Exception as error:
                self.cleanup_errors.append(str(error))
        for identifier in self.docker("network", "ls", "-q", "--no-trunc", "--filter", selector).stdout.splitlines():
            try:
                value = json.loads(self.docker("network", "inspect", identifier).stdout)[0]
                require(value["Id"] == identifier and value["Labels"].get(OWNER + ".nonce") == self.nonce
                        and not value["Containers"], "foreign/nonempty network")
                self.docker("network", "rm", identifier)
                require(not self.docker("network", "ls", "-q", "--filter", "id=" + identifier).stdout.strip(),
                        "network ID remains")
            except Exception as error:
                self.cleanup_errors.append(str(error))
        require(not self.docker("ps", "-aq", "--filter", selector).stdout.strip(), "container scope remains")
        require(not self.docker("network", "ls", "-q", "--filter", selector).stdout.strip(), "network scope remains")
        self.write(receipt, {"scope": self.scope, "containers": self.containers,
                                    "network": self.network, "errors": self.cleanup_errors,
                                    "pass": not self.cleanup_errors})
        require(not self.cleanup_errors, "owned cleanup incomplete")
        if self.private.exists():
            require(not self.private.is_symlink(), "private directory replaced")
            shutil.rmtree(self.private)

    def capture(self, profile, names):
        require(platform.system() == "Linux" and platform.machine() == "x86_64", "native amd64 required")
        require(sys.version_info >= (3, 11), "Python 3.11 or newer required")
        self.evidence.mkdir(mode=0o700)
        self.private.mkdir(mode=0o700)
        self.nonce = uuid.uuid4().hex
        self.write("owner.json", {"scope": self.scope, "nonce": self.nonce, "profile_commit": os.environ["GITHUB_SHA"]})
        for name in ("docker", "cargo", "target", "tmp", "tls", "mysql"):
            (self.private / name).mkdir(mode=0o700)
        self.write("profile.json", profile)
        info = json.loads(self.docker("info", "--format", "{{json .}}").stdout)
        require(info["OSType"] == "linux" and info["Architecture"] in ("x86_64", "amd64")
                and info["NCPU"] >= 2 and info["MemTotal"] >= 9 * 1024**3, "host capacity/architecture mismatch")
        self.write("host.json", {k: info[k] for k in ("OSType", "Architecture", "NCPU", "MemTotal", "ServerVersion")})
        for role, image in profile["images"].items():
            self.docker("pull", "--platform=linux/amd64", image, timeout=180,
                        output=self.evidence / (role + "-pull.log"))
            observed = json.loads(self.docker("image", "inspect", image).stdout)
            require(len(observed) == 1 and observed[0]["Architecture"] == "amd64"
                    and observed[0]["Os"] == "linux", "image platform differs")
            self.image_ids[image] = observed[0]["Id"]
            self.write(role + "-image.json", observed)
        images = profile["images"]
        build_env = ["CARGO_HOME=/cargo", "CARGO_TARGET_DIR=/target", "CARGO_BUILD_JOBS=2"]
        build_mounts = [str(self.source) + ":/source:ro", str(self.private / "cargo") + ":/cargo",
                        str(self.private / "target") + ":/target", str(self.private / "tmp") + ":/tmp"]
        self.create("fetch", images["rust"], ["timeout", "300", "cargo", "fetch", "--locked",
                    "--manifest-path", "/source/rust/Cargo.toml"], 2, 2, 512, network="bridge",
                    mounts=build_mounts, env=build_env)
        self.execute("fetch", 300)
        tls = self.private / "tls"
        run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-sha256", "-days", "1",
             "-keyout", str(tls / "ca.key"), "-out", str(tls / "ca.crt"), "-subj", "/CN=development-ca",
             "-addext", "basicConstraints=critical,CA:TRUE"])
        run(["openssl", "req", "-newkey", "rsa:2048", "-nodes", "-sha256", "-keyout", str(tls / "server.key"),
             "-out", str(tls / "server.csr"), "-subj", "/CN=parity-mysql", "-addext", "subjectAltName=DNS:parity-mysql",
             "-addext", "basicConstraints=critical,CA:FALSE", "-addext", "extendedKeyUsage=serverAuth"])
        run(["openssl", "x509", "-req", "-in", str(tls / "server.csr"), "-CA", str(tls / "ca.crt"),
             "-CAkey", str(tls / "ca.key"), "-CAcreateserial", "-days", "1", "-copy_extensions", "copy",
             "-out", str(tls / "server.crt")])
        self.network = self.docker("network", "create", "--internal", "--label", OWNER + "=" + self.scope,
            "--label", OWNER + ".nonce=" + self.nonce, self.scope).stdout.strip()
        require(re.fullmatch(r"[a-f0-9]{64}", self.network), "invalid network ID")
        observed_network = json.loads(self.docker("network", "inspect", self.network).stdout)[0]
        require(observed_network["Id"] == self.network and observed_network["Internal"]
                and observed_network["Labels"].get(OWNER + ".nonce") == self.nonce, "network differs")
        self.write("network-created.json", observed_network)
        self.create("database", images["mariadb"], ["docker-entrypoint.sh", "--skip-log-bin",
                    "--innodb-buffer-pool-size=128M", "--performance-schema=OFF", "--ssl-ca=/tls/ca.crt",
                    "--ssl-cert=/tls/server.crt", "--ssl-key=/tls/server.key"], 1, 1, 256,
                    network=self.network, readonly=False,
                    mounts=[str(self.private / "mysql") + ":/var/lib/mysql", str(tls) + ":/tls:ro"],
                    env=["MYSQL_ROOT_PASSWORD=parity-disposable-password", "MYSQL_ROOT_HOST=%", "MYSQL_DATABASE=php_ref"])
        self.docker("start", self.containers["database"])
        ready = time.monotonic() + 60
        while True:
            require(time.monotonic() < ready, "DB readiness expired")
            answer = self.sql("SELECT 1", check=False, timeout=min(30, ready - time.monotonic()))
            if answer.returncode == 0:
                break
            require(time.monotonic() < ready, "DB readiness expired")
            time.sleep(1)
        self.sql("CREATE DATABASE native_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        (self.evidence / "database-version.txt").write_text(self.sql("SELECT VERSION()").stdout)
        self.create("bootstrap", images["layout"], ["php", "artisan", "server:bootstrap", "--force"],
                    1, 1, 256, network=self.network, readonly=False, user="1000:1000",
                    env=["DB_CONNECTION=mysql", "DB_HOST=parity-mysql", "DB_DATABASE=php_ref", "DB_USERNAME=root",
                         "DB_PASSWORD=parity-disposable-password", "CACHE_STORE=file", "QUEUE_CONNECTION=database",
                         "DW_AUTH_TOKEN=parity-test-token"])
        self.execute("bootstrap", 180)
        self.create("compile", images["rust"], ["timeout", "600", "cargo", "test", "--locked", "--offline",
                    "--manifest-path", "/source/rust/Cargo.toml", "--test", "execution", "--no-run", "--message-format=json"],
                    2, 8, 512, mounts=build_mounts, env=build_env)
        self.execute("compile", 600)
        executables = []
        helper_executables = []
        for line in (self.evidence / "compile.log").read_text().splitlines():
            _, _, body = line.partition(" ")
            if body.startswith("{"):
                item = json.loads(body)
                if item.get("reason") == "compiler-artifact" and item.get("target", {}).get("name") == "execution" and item.get("executable"):
                    executables.append(item["executable"])
                if (item.get("reason") == "compiler-artifact"
                        and item.get("target", {}).get("name") == "durable-workflow-server"
                        and item.get("executable") == "/target/debug/durable-workflow-server"):
                    helper_executables.append(item["executable"])
        require(len(executables) == 1 and re.fullmatch(r"/target/debug/deps/execution-[0-9a-f]+", executables[0]),
                "execution executable is not unique")
        require(len(helper_executables) == 1, "native subprocess executable is not unique")
        executable = executables[0]
        bound_binaries = {}
        for path in executables + helper_executables:
            binary = self.private / "target" / path.removeprefix("/target/")
            identity = binary.lstat()
            require(stat.S_ISREG(identity.st_mode) and not binary.is_symlink(), "nonregular executable")
            with binary.open("rb") as stream:
                require(stream.read(4) == b"\x7fELF", "non-ELF executable")
            bound_binaries[path] = {"sha256": sha(binary), "device": identity.st_dev, "inode": identity.st_ino}
        self.write("executables.json", bound_binaries)
        readonly = [str(self.source) + ":/source:ro", str(self.private / "target") + ":/target:ro",
                    str(self.private / "tmp") + ":/tmp"]
        self.create("inventory", images["rust"], [executable, "--list", "--format", "terse"],
                    2, 1, 128, mounts=readonly)
        self.execute("inventory", 30)
        raw = "\n".join(line.partition(" ")[2] for line in (self.evidence / "inventory.log").read_text().splitlines())
        inventory(raw, names)
        (self.evidence / "selected-tests.txt").write_bytes(SELECTION.read_bytes())
        require(self.deadline - time.monotonic() >= 930, "insufficient budget for unchanged 900-second test")
        self.create("tests", images["rust"], ["timeout", "900", executable, "--exact", "--nocapture",
                    "--test-threads=2", *names], 2, 8, 512, network=self.network, mounts=readonly,
                    env=["DW_TEST_MYSQL_URL=mysql://root:parity-disposable-password@parity-mysql:3306/php_ref",
                         "DW_EXECUTION_MYSQL_URL=mysql://root:parity-disposable-password@parity-mysql:3306/php_ref"])
        self.status("before")
        code = self.execute("tests", 900, allow_failure=True)
        self.status("after")
        for path, before in bound_binaries.items():
            binary = self.private / "target" / path.removeprefix("/target/")
            identity = binary.lstat()
            require((identity.st_dev, identity.st_ino) == (before["device"], before["inode"])
                    and sha(binary) == before["sha256"], "executable changed")
        result = observations((self.evidence / "tests.log").read_text(), code, names)
        result.update(observer_commit=ANCHOR, profile_commit=os.environ["GITHUB_SHA"], executables=bound_binaries,
                      selection_sha256=SELECTION_SHA)
        self.write("result.json", result)
        return 0 if result["outcome"] == "baseline-not-reproduced" else 1

    def sql(self, statement, check=True, timeout=30):
        return self.docker("exec", self.containers["database"], "mysql", "-h", "parity-mysql", "-uroot",
                           "-pparity-disposable-password", "-N", "-e", statement, check=check, timeout=timeout)

    def status(self, phase):
        (self.evidence / ("database-" + phase + ".txt")).write_text(self.sql(
            "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections',"
            "'Aborted_connects','Connection_errors_max_connections','Innodb_row_lock_current_waits','Innodb_row_lock_waits');"
            "SELECT ID,COMMAND,TIME,STATE FROM INFORMATION_SCHEMA.PROCESSLIST ORDER BY ID;").stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("check", "run", "cleanup"))
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--evidence", type=Path, required=True)
    args = parser.parse_args()
    if args.mode == "check":
        profile, names = validate(args.source)
        print(json.dumps({"observer": profile["observer_commit"], "selected": len(names), "effect": "none"}))
        return 0
    require(os.environ.get("GITHUB_REPOSITORY") == "durable-workflow/server"
            and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch"
            and os.environ.get("GITHUB_REF") == "refs/heads/" + BRANCH
            and os.environ.get("GITHUB_RUN_ATTEMPT") == "1", "unselected Actions context")
    require(os.environ.get("GITHUB_WORKFLOW_REF") ==
            "durable-workflow/server/.github/workflows/server-parity.yml@refs/heads/" + BRANCH,
            "wrong workflow")
    workspace = Path(os.environ["GITHUB_WORKSPACE"]).resolve()
    require(ROOT == workspace / "capture" and args.source.resolve() == workspace / "observer"
            and args.evidence.resolve() == workspace / "evidence", "wrong invocation paths")
    expected = os.environ.get("EXPECTED_PROFILE_COMMIT", "")
    require(re.fullmatch(r"[a-f0-9]{40}", expected) and expected == os.environ.get("GITHUB_SHA")
            and run(["git", "-C", str(ROOT), "rev-parse", "HEAD"]).stdout.strip() == expected,
            "profile source commit differs")
    capture = Capture(args.source, args.evidence)
    def interrupted(_number, _frame):
        raise RuntimeError("capture interrupted")
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGALRM, interrupted)
    signal.alarm(140 if args.mode == "cleanup" else 2880)
    if args.mode == "cleanup":
        if not (capture.evidence / "owner.json").exists():
            signal.alarm(0)
            return 0
        state = json.loads((capture.evidence / "owner.json").read_text())
        require(state["scope"] == capture.scope and state["profile_commit"] == expected, "foreign cleanup state")
        capture.nonce = state["nonce"]
        capture.cleanup("fallback-cleanup.json")
        signal.alarm(0)
        return 0
    profile, names = validate(args.source)
    code = 1
    try:
        code = capture.capture(profile, names)
    except Exception as error:
        if capture.evidence.exists():
            capture.write("blocked.json", {"outcome": "runner-blocked", "error": str(error)})
    finally:
        if capture.nonce is not None:
            capture.cleanup()
            capture.write("terminal.json", {"exit_code": code, "cleanup_pass": True,
                                            "observer_commit": ANCHOR,
                                            "profile_commit": expected})
    signal.alarm(0)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
