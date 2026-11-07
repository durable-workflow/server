#!/usr/bin/env python3
"""Transport/producer/cleanup countercontrols; no real Docker or API calls."""
import copy
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import textwrap
from types import SimpleNamespace
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[3] / "scripts/ci"))
import native_alias_evidence as E
import native_alias_promote as P
import native_alias_transport as T
import native_qualification as Q
from native_alias_evidence_test import FakeProvider, RunnerProvider, RUNNER

ROOT = Path(__file__).resolve().parents[3]

SOURCE = "1" * 40
INDEX, CHILD, CONFIG = ["sha256:" + letter * 64 for letter in "abc"]
CID, NID, VOLUME = [letter * 64 for letter in "def"]
SCOPE = "published-compose-301-1-smoke-amd64-local"
CONTEXT = {
    "PROMOTION_TAG": "3.2.0",
    "GITHUB_REPOSITORY": E.REPOSITORY, "GITHUB_EVENT_NAME": "workflow_dispatch",
    "GITHUB_REF": "refs/tags/3.2.0", "GITHUB_REF_NAME": "3.2.0", "GITHUB_SHA": SOURCE,
    "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/" + E.WORKFLOWS["compose"] + "@refs/tags/3.2.0",
    "GITHUB_RUN_ID": "303", "GITHUB_RUN_ATTEMPT": "1",
}


def fake_command_lifecycle(module, exit_code=0, authority_error=False, cleanup_error=None):
    """No process or signal: an early reap makes the fake PID foreign."""
    events = []

    class Pipe:
        def __init__(self, descriptor):
            self.descriptor = descriptor

        def close(self):
            events.append("close-pipe")

    class Process:
        pid = 43210
        reaped = False
        stdout, stderr = Pipe(10), Pipe(11)

        def wait(self, timeout=None):
            events.append("reap")
            if cleanup_error == "reap":
                raise module.subprocess.TimeoutExpired("synthetic-private-marker", timeout)
            self.reaped = True
            return exit_code if exit_code is not None else -9

    process = Process()

    class Selector:
        def __init__(self):
            self.keys = {}

        def __enter__(self):
            return self

        def __exit__(self, *args):
            return False

        def register(self, stream, event, data):
            self.keys[stream.descriptor] = SimpleNamespace(fd=stream.descriptor, fileobj=stream, data=data)

        def unregister(self, stream):
            del self.keys[stream.descriptor]

        def get_map(self):
            return self.keys

        def select(self, timeout):
            return [(value, 1) for value in list(self.keys.values())]

    def observe(kind, pid, flags):
        assert kind == os.P_PID
        assert pid == process.pid
        assert flags & os.WNOWAIT
        assert flags & os.WNOHANG
        events.append("observe")
        if authority_error or process.reaped:
            raise ChildProcessError("synthetic unowned child")
        return None if exit_code is None else SimpleNamespace(si_pid=pid, si_code=os.CLD_EXITED, si_status=exit_code)

    def signal_group(pid, sig):
        assert pid == process.pid
        assert sig == module.signal.SIGKILL
        events.append("foreign-signal" if process.reaped else "owned-signal")
        if cleanup_error == "signal":
            raise PermissionError("synthetic-private-marker")

    clock = [0.0]

    def now():
        clock[0] += 0.1
        return clock[0]

    error, result = None, None
    with patch.object(module.subprocess, "Popen", return_value=process), \
            patch.object(module.selectors, "DefaultSelector", Selector), \
            patch.object(module.os, "read", return_value=b""), \
            patch.object(module.os, "waitid", side_effect=observe), \
            patch.object(module.os, "killpg", side_effect=signal_group), \
            patch.object(module.signal, "getsignal", return_value=module.signal.SIG_DFL), \
            patch.object(module.time, "monotonic", side_effect=now), \
            patch.object(module.time, "sleep"):
        commands = module.Commands(1)
        try:
            result = commands.run(["synthetic-read-only-cli"])
        except E.Refusal as caught:
            error = caught
    return result, error, events


class CommandChildAuthorityTest(unittest.TestCase):
    def test_success_signals_only_before_the_direct_child_is_reaped(self):
        result, error, events = fake_command_lifecycle(T)
        self.assertIsNone(error)
        self.assertEqual(result, b"")
        self.assertNotIn("foreign-signal", events)
        self.assertIn("owned-signal", events)
        self.assertLess(events.index("owned-signal"), events.index("reap"))

    def test_failure_and_timeout_also_clean_up_before_reaping(self):
        for code, reason in ((1, "provider_read_failed"), (None, "read_timeout")):
            with self.subTest(code=code):
                _, error, events = fake_command_lifecycle(T, exit_code=code)
                self.assertIsInstance(error, E.Refusal)
                self.assertEqual(str(error), reason)
                self.assertNotIn("foreign-signal", events)
                self.assertIn("owned-signal", events)
                self.assertLess(events.index("owned-signal"), events.index("reap"))

    def test_unknown_child_authority_never_signals_or_reaps_a_numeric_pid(self):
        _, error, events = fake_command_lifecycle(T, authority_error=True)
        self.assertIsInstance(error, E.Refusal)
        self.assertEqual(str(error), "provider_child_authority_unknown")
        self.assertNotIn("foreign-signal", events)
        self.assertNotIn("owned-signal", events)
        self.assertNotIn("reap", events)
        self.assertEqual(events.count("close-pipe"), 2)

    def test_missing_nonreaping_primitive_refuses_before_process_creation(self):
        commands = T.Commands()
        with patch.object(T.os, "waitid", None), patch.object(T.subprocess, "Popen") as spawn:
            with self.assertRaisesRegex(E.Refusal, "provider_child_authority_unavailable"):
                commands.run(["synthetic-read-only-cli"])
        spawn.assert_not_called()

    def test_an_automatic_or_custom_reaper_refuses_before_process_creation(self):
        for handler in (T.signal.SIG_IGN, lambda *args: None):
            commands = T.Commands()
            with self.subTest(handler=handler), \
                    patch.object(T.signal, "getsignal", return_value=handler), \
                    patch.object(T.subprocess, "Popen") as spawn:
                with self.assertRaisesRegex(E.Refusal, "provider_child_authority_unavailable"):
                    commands.run(["synthetic-read-only-cli"])
            spawn.assert_not_called()

    def test_signal_or_bounded_reap_failure_cannot_return_a_successful_read(self):
        for fault in ("signal", "reap"):
            with self.subTest(fault=fault):
                result, error, events = fake_command_lifecycle(T, cleanup_error=fault)
                self.assertIsNone(result)
                self.assertIsInstance(error, E.Refusal)
                self.assertEqual(str(error), "provider_child_cleanup_failed")
                self.assertNotIn("foreign-signal", events)
                self.assertEqual(events.count("close-pipe"), 2)


class ResourceCommands:
    def __init__(self):
        self.containers, self.networks, self.volumes = {CID}, {NID}, {VOLUME}
        self.removed, self.reads = [], []
        self.owner = SCOPE
        self.corpus_owner = SCOPE
        self.container_name = "/" + SCOPE + "-server"
        self.volume_owner = None
        self.network_owner = SCOPE
        self.fail_list = self.leak = False

    def run(self, argv, limit=E.JSON_LIMIT, env=None):
        self.reads.append(argv)
        resource, operation = argv[1:3]
        if operation == "ls":
            if self.fail_list:
                raise E.Refusal("fake_api_unavailable")
            if resource == "volume" and "--filter" in argv and (
                    type(self.volume_owner) is not dict or self.volume_owner.get(Q.COMPOSE_OWNER) != SCOPE):
                return b""
            return ("\n".join(sorted(getattr(self, resource + "s"))) +
                    ("\n" if getattr(self, resource + "s") else "")).encode()
        if operation == "inspect":
            if resource == "container":
                labels = {"com.docker.compose.project": self.owner}
                if self.corpus_owner is not None:
                    labels[Q.OWNER] = self.corpus_owner
                value = {"Id": CID, "Name": self.container_name, "Image": CONFIG,
                         "Config": {"Labels": labels},
                         "NetworkSettings": {"Networks": {SCOPE: {}}},
                         "Mounts": [{"Type": "volume", "Name": VOLUME}]}
            elif resource == "volume":
                value = {"Name": VOLUME, "Labels": self.volume_owner}
            elif resource == "network":
                value = {"Id": NID, "Name": SCOPE, "Containers": {CID: {}},
                         "Labels": {Q.OWNER: self.network_owner, Q.COMPOSE_OWNER: self.network_owner}}
            else:
                value = {"Id": CONFIG, "Architecture": "amd64", "Os": "linux",
                         "Config": {"Labels": {"org.opencontainers.image.revision": SOURCE,
                                                "dev.durable-workflow.release.tag": "3.2.0"}}}
            return json.dumps([value]).encode()
        if operation == "rm":
            identities = {item for item in argv[3:] if not item.startswith("-")}
            self.removed.append((resource, identities))
            if not self.leak:
                getattr(self, resource + "s").difference_update(identities)
            return b""
        if argv[:3] == ["git", "rev-parse", "HEAD"]:
            return SOURCE.encode()
        if argv[:2] == ["git", "rev-parse"]:
            return ("3" * 40).encode()
        raise AssertionError(argv)


class ProducerProvider:
    def __init__(self):
        self.commands = ResourceCommands()
        self.subject = {"child_manifest_digest": CHILD, "image_config_digest": CONFIG}
        owner = {"id": E.REPOSITORY_ID, "full_name": E.REPOSITORY}
        self.run = {"id": 303, "run_attempt": 1, "head_sha": SOURCE,
                    "path": E.WORKFLOWS["compose"], "event": "workflow_dispatch",
                    "head_branch": "3.2.0", "repository": owner, "head_repository": owner}
        self.job = {"id": 3001, "run_id": 303, "run_attempt": 1, "head_sha": SOURCE,
                    "status": "in_progress", "name": "linux/amd64 local published image"}

    def index_subjects(self, *args):
        return {"linux/amd64": self.subject}

    def workflow_blob(self, *args):
        return "3" * 40

    def api(self, path):
        return {"jobs": [self.job], "total_count": 1} if "/jobs?" in path else self.run


class RunnerCommands:
    def __init__(self, provider):
        self.provider = provider
        self.reads = []
        self.database_config = provider.binding["mysql"]["image_config_digest"]
        self.database_owner = SCOPE
        self.wrong_qemu_digest = False

    def run(self, args, *unused, **kwargs):
        self.reads.append(args)
        if args[:2] == ["git","rev-parse"]:
            return (RUNNER if args[2] == "HEAD" else self.provider.binding_blob if args[2].endswith(E.SUBJECT_PATH) else "3"*40).encode()+b"\n"
        if args[:3] == ["docker","container","ls"]:
            return ("f"*64+"\n").encode()
        identity = args[-1]
        if args[:3] == ["docker","container","inspect"]:
            if identity == CID:
                value = {"Image":self.provider.subjects["linux/amd64"]["image_config_digest"]}
            else:
                value = {"Id":"f"*64,"Image":self.database_config,"Config":{
                    "Image":self.provider.binding["mysql"]["requested_image"],"Labels":{
                    Q.COMPOSE_OWNER:self.database_owner,"com.docker.compose.service":"mysql"}}}
        elif args[:3] == ["docker","image","inspect"]:
            value = {"Id":identity,"Architecture":"amd64","Os":"linux","Config":{"Labels":{
                "org.opencontainers.image.revision":SOURCE,"dev.durable-workflow.release.tag":"3.2.0"}}}
            for name, repo in (("mysql","mysql"),("qemu","tonistiigi/binfmt")):
                if identity == self.provider.binding[name]["image_config_digest"]:
                    value["RepoDigests"] = [repo+"@"+(INDEX if name == "qemu" and self.wrong_qemu_digest else self.provider.binding[name]["child_manifest_digest"])]
        else:
            raise AssertionError("unexpected command")
        return json.dumps([value]).encode()


class RunnerProducerTests(unittest.TestCase):
    def context(self, provider):
        return {**CONTEXT,"GITHUB_SHA":RUNNER,"GITHUB_REF":E.RUNNER_REF,
            "GITHUB_REF_NAME":E.RUNNER_REF.removeprefix("refs/heads/"),
            "GITHUB_WORKFLOW_REF":E.REPOSITORY+"/"+E.WORKFLOWS["compose"]+"@"+E.RUNNER_REF,
            "DW_PUBLISHED_COMPOSE_PROJECT":SCOPE,"DW_PUBLISHED_COMPOSE_PROFILE":"local",
            "MYSQL_VERSION":provider.binding["mysql"]["requested_image"].removeprefix("mysql:"),
            "QUALIFICATION_QEMU_IMAGE":provider.binding["qemu"]["requested_image"]}

    def test_actual_producer_v2_receipt_is_consumable_without_restamping_the_image_source(self):
        provider=RunnerProvider();provider.commands=RunnerCommands(provider)
        provider.index_subjects=lambda *args:copy.deepcopy(provider.subjects)
        provider.runs[303]["status"]="in_progress"
        provider.job_sets[303][0]["status"]="in_progress"
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);path=root/E.SUBJECT_PATH;path.parent.mkdir(parents=True);path.write_bytes(provider.binding_bytes)
            context=self.context(provider)
            context["DW_PUBLISHED_COMPOSE_PROJECT"]=E.resource_scope("compose",303,1,"linux/amd64","local")
            provider.commands.database_owner=context["DW_PUBLISHED_COMPOSE_PROJECT"]
            with patch.dict(os.environ,context,clear=True),patch.object(Q,"ROOT",root):
                image=E.GHCR_IMAGE+"@"+INDEX
                observed=Q.observe_image(provider,"compose",image,CID,"linux/amd64")
                observations={key:True for key in E.COMPOSE_OBSERVATIONS}
                observations["production_default_token_refused"]=None
                clean={"api_ok":True,"verified_absent":True,"container_count":0,"network_count":0,"volume_count":0}
                record=Q.finalize(provider,"compose",image,"linux/amd64","local",observed,observations,clean,"success","success")
                E.bind_receipt(record,"compose",provider.selected["compose"],provider.runs[303],provider.job_sets[303][0],
                               provider.binding["release"],"linux/amd64","local",provider.subjects["linux/amd64"],
                               provider.selected["runner"],provider.binding)
                self.assertEqual(record["image_source_commit"],SOURCE)
                self.assertEqual(record["runner_commit"],RUNNER)
                dependencies=Q.observe_dependencies(provider.commands,"compose","linux/arm64",context["DW_PUBLISHED_COMPOSE_PROJECT"],provider.binding)
                E.bind_dependencies(dependencies,"compose","linux/arm64",provider.binding)
                provider.commands.wrong_qemu_digest=True
                with self.assertRaisesRegex(E.Refusal,"actual_dependency_image"):
                    Q.observe_dependencies(provider.commands,"compose","linux/arm64",context["DW_PUBLISHED_COMPOSE_PROJECT"],provider.binding)
                provider.commands.wrong_qemu_digest=False
                for attribute,value,reason in (("database_config",INDEX,"actual_database_subject"),
                    ("database_owner","foreign","actual_database_subject")):
                    old=getattr(provider.commands,attribute);setattr(provider.commands,attribute,value)
                    with self.subTest(attribute=attribute),self.assertRaisesRegex(E.Refusal,reason):
                        Q.observe_image(provider,"compose",image,CID,"linux/amd64")
                    setattr(provider.commands,attribute,old)
                for field,value in (("MYSQL_VERSION","8.0"),("QUALIFICATION_QEMU_IMAGE","tonistiigi/binfmt:latest")):
                    with self.subTest(field=field),patch.dict(os.environ,{field:value}),self.assertRaisesRegex(E.Refusal,"qualification_dependency_configuration"):
                        Q.admit(provider,"compose",image)

    def test_manual_runner_has_no_builder_and_admits_before_dependency_steps(self):
        corpus=(ROOT/E.WORKFLOWS["corpus"]).read_text()
        builder=corpus.split("      - name: Set up Docker Buildx\n",1)[1].split("      - name:",1)[0]
        self.assertIn("if: github.event_name == 'pull_request'",builder)
        self.assertLess(corpus.index("name: Admit published qualification subject"),corpus.index("name: Pull the pinned images"))
        compose=(ROOT/E.WORKFLOWS["compose"]).read_text()
        self.assertNotIn("docker/setup-buildx-action@",compose)
        self.assertLess(compose.index("name: Admit published qualification subject"),compose.index("name: Set up QEMU"))
        binding=json.loads((ROOT/E.SUBJECT_PATH).read_text())
        self.assertIn(binding["mysql"]["requested_image"].removeprefix("mysql:"),compose)
        self.assertIn(binding["qemu"]["requested_image"],compose)


class ProducerTests(unittest.TestCase):
    def test_cleanup_removes_only_observed_owned_objects_and_checks_absence(self):
        commands = ResourceCommands()
        result = Q.cleanup(commands, "compose", SCOPE)
        self.assertEqual(result, {"api_ok": True, "verified_absent": True,
                                  "container_count": 0, "network_count": 0, "volume_count": 0})
        self.assertEqual(commands.removed, [("container", {CID}), ("network", {NID}), ("volume", {VOLUME})])
        self.assertGreaterEqual(sum(args[2] == "ls" for args in commands.reads), 8)

    def test_engine_anonymous_marker_requires_owned_mount_and_no_foreign_labels(self):
        for role in ("compose", "corpus"):
            commands = ResourceCommands()
            commands.volume_owner = {"com.docker.volume.anonymous": ""}
            self.assertIs(Q.cleanup(commands, role, SCOPE)["verified_absent"], True)
            for labels in ({"com.docker.volume.anonymous": "", "owner": "foreign"},
                           {"com.docker.volume.anonymous": "not-the-engine-marker"}):
                commands = ResourceCommands()
                commands.volume_owner = labels
                with self.subTest(role=role, labels=labels), self.assertRaisesRegex(E.Refusal, "cleanup_volume_owner"):
                    Q.cleanup(commands, role, SCOPE)
                self.assertEqual(commands.removed, [])

    def test_foreign_network_never_authorizes_any_removal(self):
        for role in ("compose", "corpus"):
            commands = ResourceCommands()
            commands.network_owner = "foreign"
            with self.subTest(role=role), self.assertRaisesRegex(E.Refusal, "cleanup_network_ownership"):
                Q.cleanup(commands, role, SCOPE)
            self.assertEqual(commands.removed, [])

    def test_cli_resource_and_file_paths_are_closed_before_any_sink(self):
        commands = ResourceCommands()
        for identity in ("--format={{.Config.Env}}", "../private", "server\n--format=env", "x/y"):
            with self.subTest(identity=identity), self.assertRaisesRegex(E.Refusal, "docker_resource_identity"):
                Q.inspect(commands, "container", identity)
        self.assertEqual(commands.reads, [])
        for path in ("../private", "/tmp/private", "qualification-state/../private", "qualification-state/other.json"):
            candidate = Path(path)
            with self.subTest(path=path), self.assertRaisesRegex(E.Refusal, "qualification_path_not_allowed"):
                Q.state_path(candidate)
        with tempfile.TemporaryDirectory() as directory, patch.object(Q, "ROOT", Path(directory)):
            target = Path(directory) / "qualification-state"
            target.symlink_to(Path(directory), target_is_directory=True)
            with self.assertRaisesRegex(E.Refusal, "qualification_path_symlink"):
                Q.state_path(Path("qualification-state/image.json"))

    def test_cleanup_unavailable_is_unknown_and_foreign_resources_are_not_removed(self):
        for field, value in (("fail_list", True), ("owner", "another-project"),
                             ("volume_owner", {"com.docker.compose.project": "foreign"})):
            commands = ResourceCommands()
            setattr(commands, field, value)
            with self.subTest(field=field), self.assertRaises(E.Refusal):
                Q.cleanup(commands, "compose", SCOPE)
            self.assertEqual(commands.removed, [])

    def test_cleanup_remaining_resource_is_failure(self):
        for role in ("corpus", "compose"):
            commands = ResourceCommands()
            commands.leak = True
            with self.subTest(role=role), self.assertRaisesRegex(E.Refusal, "cleanup_not_absent"):
                Q.cleanup(commands, role, SCOPE)

    def test_corpus_unavailable_inventory_is_unknown_with_zero_removals(self):
        commands = ResourceCommands()
        commands.fail_list = True
        with self.assertRaisesRegex(E.Refusal, "fake_api_unavailable"):
            Q.cleanup(commands, "corpus", SCOPE)
        self.assertEqual(commands.removed, [])

    def test_corpus_prefix_attachment_and_missing_label_never_authorize_removal(self):
        for name, owner in (("/foreign-owner", "foreign"), ("/" + SCOPE + "-rust", "foreign"),
                             ("/" + SCOPE + "-rust", None)):
            commands = ResourceCommands()
            commands.container_name, commands.corpus_owner = name, owner
            with self.subTest(name=name, owner=owner), self.assertRaisesRegex(E.Refusal, "cleanup_container_owner"):
                Q.cleanup(commands, "corpus", SCOPE)
            self.assertEqual(commands.removed, [])

    def test_corpus_labelled_attached_or_standalone_helpers_are_owned(self):
        for attached in (True, False):
            commands = ResourceCommands()
            commands.container_name = "/anonymous-helper"
            if not attached:
                commands.networks.clear()
            result = Q.cleanup(commands, "corpus", SCOPE)
            self.assertIs(result["verified_absent"], True)
            self.assertIn(("container", {CID}), commands.removed)

    def test_diagnostic_context_does_not_query_provider_or_docker(self):
        provider = ProducerProvider()
        with patch.dict(os.environ, CONTEXT, clear=True):
            for field, value in (("GITHUB_EVENT_NAME", "pull_request"),
                                 ("GITHUB_SHA", "unknown"), ("GITHUB_REF", "refs/heads/main")):
                with patch.dict(os.environ, {field: value}):
                    result = Q.observe_image(provider, "compose", E.GHCR_IMAGE + "@" + INDEX, CID, "linux/amd64")
                    self.assertIs(result["eligible"], False)
        self.assertEqual(provider.commands.reads, [])

    def test_observed_image_and_finalized_receipt_bind_to_consumer(self):
        provider = ProducerProvider()
        with patch.dict(os.environ, CONTEXT, clear=True):
            image = E.GHCR_IMAGE + "@" + INDEX
            identity = Q.observe_image(provider, "compose", image, CID, "linux/amd64")
            observations = {key: True for key in E.COMPOSE_OBSERVATIONS}
            observations["production_default_token_refused"] = None
            cleanup = Q.cleanup(ResourceCommands(), "compose", SCOPE)
            record = Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                                observations, cleanup, "success", "success")
            E.bind_receipt(record, "compose", {"workflow_blob": "3" * 40}, provider.run, provider.job,
                           {"source_commit": SOURCE, "tag": "3.2.0", "index_digest": INDEX},
                           "linux/amd64", "local", provider.subject)
            self.assertEqual(set(record), E.RECEIPT_KEYS)
            for field, replacement in (("api_ok", False), ("verified_absent", False),
                                       ("container_count", False), ("volume_count", 1)):
                invalid = {**cleanup, field: replacement}
                with self.subTest(cleanup=field), self.assertRaises(E.Refusal):
                    Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                               observations, invalid, "success", "success")
            for body, clean in (("failure", "success"), ("success", "failure"), ("cancelled", "success")):
                with self.subTest(body=body, clean=clean), self.assertRaises(E.Refusal):
                    Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                               observations, cleanup, body, clean)
            provider.run["run_attempt"] = 2
            with self.assertRaises(E.Refusal):
                Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                           observations, cleanup, "success", "success")

    def test_actual_config_id_cannot_be_substituted_with_index(self):
        provider = ProducerProvider()
        provider.subject["image_config_digest"] = INDEX
        with patch.dict(os.environ, CONTEXT, clear=True), self.assertRaisesRegex(E.Refusal, "actual_container_image"):
            Q.observe_image(provider, "compose", E.GHCR_IMAGE + "@" + INDEX, CID, "linux/amd64")


class ReadCommands:
    def __init__(self, result):
        self.result, self.calls = result, []

    def run(self, argv, limit=E.JSON_LIMIT, env=None, accepted=(0,)):
        self.calls.append((argv, limit, env, accepted))
        return self.result


class TransportTests(unittest.TestCase):
    def test_unprivileged_plan_requires_exact_tag_and_accepted_main_source(self):
        value = {"release": {"tag": "3.2.0", "source_commit": SOURCE}}
        context = {**CONTEXT, "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/.github/workflows/native-alias-promotion.yml@refs/tags/3.2.0"}
        from unittest.mock import Mock
        provider = Mock()
        with patch.dict(os.environ, context, clear=True):
            for status in ("ahead", "identical"):
                provider.api.return_value = {"status": status, "base_commit": {"sha": SOURCE}}
                P.plan(value, provider)
            for comparison in ({"status": "behind", "base_commit": {"sha": SOURCE}},
                               {"status": "diverged", "base_commit": {"sha": SOURCE}},
                               {"status": "ahead", "base_commit": {"sha": "f" * 40}}, {}):
                provider.api.return_value = comparison
                with self.subTest(comparison=comparison), self.assertRaisesRegex(E.Refusal, "promotion_source_not_on_main"):
                    P.plan(value, provider)
            provider.reset_mock()
            with patch.dict(os.environ, {"PROMOTION_TAG": "3.2.1"}), self.assertRaisesRegex(E.Refusal, "promotion_dispatch_identity"):
                P.plan(value, provider)
            provider.api.assert_not_called()

    def test_api_only_targets_fixed_owning_repository_and_host(self):
        commands = ReadCommands(b"{}")
        provider = T.GitHubRegistryProvider(commands)
        provider.api("repos/" + E.REPOSITORY + "/actions/runs/301")
        self.assertEqual(commands.calls[0][0][:6], ["gh", "api", "--hostname", "github.com", "--method", "GET"])
        for path in ("https://foreign.invalid/", "repos/fork/server/actions/runs/301",
                     "repos/" + E.REPOSITORY + "/../secrets", "repos/" + E.REPOSITORY + "/runs\nanything"):
            with self.subTest(path=path), self.assertRaises(E.Refusal):
                provider.api(path)
        self.assertEqual(len(commands.calls), 1)

    def test_registry_subjects_bind_platform_child_config_and_source(self):
        class Registry(T.GitHubRegistryProvider):
            def __init__(self):
                self.members = {
                    INDEX: {"manifests": [
                        {"platform": {"os": "linux", "architecture": "amd64"}, "digest": CHILD},
                        {"platform": {"os": "linux", "architecture": "arm64"}, "digest": "sha256:" + "d" * 64}]},
                    CHILD: {"config": {"digest": CONFIG}},
                    "sha256:" + "d" * 64: {"config": {"digest": "sha256:" + "e" * 64}},
                }
                labels = {"org.opencontainers.image.revision": SOURCE, "dev.durable-workflow.release.tag": "3.2.0"}
                self.configs = {CHILD: {"os": "linux", "architecture": "amd64", "config": {"Labels": dict(labels)}},
                                "sha256:" + "d" * 64: {"os": "linux", "architecture": "arm64", "config": {"Labels": dict(labels)}}}

            def registry_json(self, image, digest, image_config=False):
                return (self.configs if image_config else self.members)[digest]

        registry = Registry()
        self.assertEqual(registry.index_subjects(E.GHCR_IMAGE, INDEX, SOURCE, "3.2.0")["linux/amd64"],
                         {"child_manifest_digest": CHILD, "image_config_digest": CONFIG})
        for fault in ("platform", "source", "release", "config", "extra_member"):
            registry = Registry()
            if fault == "platform": registry.configs[CHILD]["architecture"] = "arm64"
            elif fault == "source": registry.configs[CHILD]["config"]["Labels"]["org.opencontainers.image.revision"] = "f" * 40
            elif fault == "release": registry.configs[CHILD]["config"]["Labels"]["dev.durable-workflow.release.tag"] = "3.1.0"
            elif fault == "config": registry.members[CHILD]["config"]["digest"] = "not-a-config"
            else: registry.members[INDEX]["manifests"].append(copy.deepcopy(registry.members[INDEX]["manifests"][0]))
            with self.subTest(fault=fault), self.assertRaises(E.Refusal):
                registry.index_subjects(E.GHCR_IMAGE, INDEX, SOURCE, "3.2.0")

    def test_registry_cli_rejects_option_or_argument_injection_before_any_command(self):
        commands = ReadCommands(b"{}")
        provider = T.GitHubRegistryProvider(commands)
        for image, digest in (("--format=env", INDEX), ("foreign.invalid/server", INDEX),
                              (E.GHCR_IMAGE, "--format=env"), (E.GHCR_IMAGE, INDEX + "\n--format=env"),
                              (E.GHCR_IMAGE, "sha256:" + "a" * 63)):
            with self.subTest(image=image, digest=digest), self.assertRaisesRegex(E.Refusal, "registry_subject"):
                provider.registry_json(image, digest)
        self.assertEqual(commands.calls, [])

    def test_release_absence_requires_authoritative_http_404(self):
        for status, body, expected in (("404 Not Found", {"message": "Not Found"}, False),
                                       ("200 OK", {"id": 42, "tag_name": "3.2.0"}, True)):
            commands = ReadCommands(("HTTP/2.0 " + status + "\r\nContent-Type: application/json\r\n\r\n" + json.dumps(body)).encode())
            self.assertIs(T.GitHubRegistryProvider(commands).release_exists("3.2.0"), expected)
        for response in (b"unauthorized", b"HTTP/2.0 503 Error\r\n\r\n{}",
                         b"HTTP/2.0 404 Not Found\r\nContent-Type: application/json\r\n\r\n{}"):
            provider = T.GitHubRegistryProvider(ReadCommands(response))
            with self.subTest(response=response), self.assertRaises(E.Refusal):
                provider.release_exists("3.2.0")

    def test_commands_bound_output_time_and_sanitize_errors(self):
        for code, limit, seconds in (("print('x'*10000)", 10, 1),
                                     ("import time; time.sleep(1)", 100, .03),
                                     ("import sys; sys.stderr.write('private-marker'); sys.exit(1)", 100, 1)):
            commands = T.Commands(seconds)
            with self.subTest(code=code), self.assertRaises(E.Refusal) as caught:
                commands.run([sys.executable, "-c", code], limit)
            self.assertNotIn("private-marker", str(caught.exception))
        self.assertEqual(T.Commands().run([sys.executable, "-c", "print('bounded')"]), b"bounded\n")

    def test_promotion_dispatch_cannot_be_a_main_or_foreign_workflow(self):
        value = {"release": {"tag": "3.2.0", "source_commit": SOURCE}}
        context = {**CONTEXT, "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/.github/workflows/native-alias-promotion.yml@refs/tags/3.2.0"}
        with patch.dict(os.environ, context, clear=True):
            P.dispatch(value)
            for field, replacement in (("GITHUB_REF", "refs/heads/main"), ("GITHUB_SHA", "2" * 40),
                                       ("GITHUB_WORKFLOW_REF", "foreign/workflow")):
                with patch.dict(os.environ, {field: replacement}), self.assertRaises(E.Refusal):
                    P.dispatch(value)

    def test_protected_entry_refusals_leave_zero_write_ledger(self):
        context = {**CONTEXT, "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/.github/workflows/native-alias-promotion.yml@refs/tags/3.2.0"}
        for fault in ("legacy_release", "attempt", "workflow_blob", "archive"):
            provider = FakeProvider()
            provider.release_exists = lambda tag: fault == "legacy_release"
            if fault == "attempt":
                provider.runs[302]["run_attempt"] = 2
            elif fault == "workflow_blob":
                provider.selected["corpus"]["workflow_blob"] = "f" * 40
            elif fault == "archive":
                provider.archives[501] += b"extra"
            with patch.dict(os.environ, context, clear=True), self.subTest(fault=fault):
                with self.assertRaises(E.Refusal):
                    P.qualify(provider.selected, provider, "promote")


class WorkflowContracts(unittest.TestCase):
    def test_only_separate_protected_job_may_move_native_aliases(self):
        publisher = (ROOT / ".github/workflows/native-release.yml").read_text()
        promotion = (ROOT / ".github/workflows/native-alias-promotion.yml").read_text()
        self.assertNotIn("\n  aliases:", publisher)
        self.assertNotIn("native-release-rolling-tags.sh", publisher)
        readonly, protected = promotion.split("\n  promote:\n", 1)
        self.assertNotIn(": write", readonly)
        self.assertNotIn("secrets.DOCKERHUB", readonly)
        self.assertIn("environment: dockerhub-native", protected)
        self.assertIn("group: durable-workflow-server-rolling-aliases", protected)
        self.assertIn("packages: write", protected)
        self.assertNotIn("contents: write", promotion)
        self.assertIn("native_alias_promote.py verify", readonly)
        self.assertIn("native_alias_promote.py promote", protected)
        for name, steps in E.RELEASE_JOBS.items():
            self.assertIn("name: " + name, publisher)
            for step in steps:
                self.assertIn("name: " + step, publisher)

    def test_receipt_authority_follows_body_and_cleanup_in_existing_jobs(self):
        for role in ("corpus", "compose"):
            source = (ROOT / E.WORKFLOWS[role]).read_text()
            steps = E.QUALIFICATION_STEPS[role]
            positions = [source.index("name: " + step) for step in steps]
            self.assertEqual(positions, sorted(positions))
            self.assertIn("QUALIFICATION_BODY_OUTCOME: ${{ steps.body.outcome }}", source)
            self.assertIn("QUALIFICATION_CLEANUP_OUTCOME: ${{ steps.cleanup.outcome }}", source)
            self.assertIn("promotion-receipt/receipt.json", source)
            self.assertIn("if-no-files-found: error", source)

    def test_workflow_strict_cleanup_failure_has_no_fallback_removal(self):
        # Execute the actual workflow block AND helper against an inert Docker
        # provider that presents a foreign exact-name/attached collision. Both
        # helper broadening and a raw workflow fallback would produce removals.
        with tempfile.TemporaryDirectory() as directory:
            scratch = Path(directory)
            scripts = scratch / "scripts/ci"
            scripts.mkdir(parents=True)
            for name in ("native_qualification.py", "native_alias_evidence.py", "native_alias_transport.py"):
                shutil.copyfile(ROOT / "scripts/ci" / name, scripts / name)
            docker = scratch / "docker"
            docker.write_text("#!" + sys.executable + "\n" + textwrap.dedent('''
                import json, os, sys
                from pathlib import Path
                args = sys.argv[1:]
                with Path(os.environ["FAKE_LEDGER"]).open("a") as log:
                    log.write(json.dumps(args) + "\\n")
                cid, nid, scope = "d" * 64, "e" * 64, os.environ["RESOURCE_SCOPE"]
                resource, operation = args[:2]
                if operation == "ls":
                    print(cid if resource == "container" else nid if resource == "network" else "")
                elif operation == "inspect":
                    value = ({"Id": nid, "Name": scope, "Labels": {"dev.durable-workflow.qualification.scope": scope},
                              "Containers": {cid: {}}} if resource == "network" else
                             {"Id": cid, "Name": "/" + scope + "-rust", "Mounts": [],
                              "Config": {"Labels": {"owner": "foreign", "com.docker.compose.project": "foreign"}},
                              "NetworkSettings": {"Networks": {scope: {}}}})
                    print(json.dumps([value]))
                elif operation == "rm" or resource == "rm":
                    pass
                else:
                    raise SystemExit(71)
            '''))
            docker.chmod(0o755)
            for role in ("corpus", "compose"):
                source = (ROOT / E.WORKFLOWS[role]).read_text()
                block = source.split("      - name: Verify owned qualification cleanup\n", 1)[1]
                block = block.split("      - name:", 1)[0]
                body = textwrap.dedent(block.split("        run: |\n", 1)[1])
                ledger = scratch / (role + "-ledger")
                result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", body],
                                        env={"PATH": directory + ":" + os.environ["PATH"],
                                             "RESOURCE_SCOPE": SCOPE, "DW_PUBLISHED_COMPOSE_PROJECT": SCOPE,
                                             "FAKE_LEDGER": str(ledger)}, cwd=scratch,
                                        capture_output=True, timeout=5, check=False)
                self.assertNotEqual(result.returncode, 0, role)
                calls = [json.loads(line) for line in ledger.read_text().splitlines()]
                self.assertTrue(calls)
                self.assertFalse(any("rm" in call for call in calls), calls)
                self.assertFalse((scratch / "qualification-state/cleanup.json").exists())
                self.assertNotIn("docker ", body)
                self.assertNotIn("||", body)


if __name__ == "__main__":
    unittest.main()
