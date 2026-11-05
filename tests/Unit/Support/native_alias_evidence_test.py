#!/usr/bin/env python3
"""Pure provider/receipt countercontrols. No network, Docker or credentials."""
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import stat
import struct
import subprocess
import sys
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("native_alias_evidence", ROOT / "scripts/ci/native_alias_evidence.py")
E = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(E)
SOURCE = "1" * 40
INDEX = "sha256:" + "a" * 64


def packed(value, name="receipt.json", mode=stat.S_IFREG | 0o644, extra=None):
    target = io.BytesIO()
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        entry = zipfile.ZipInfo(name, date_time=(2026, 11, 5, 0, 0, 0))
        entry.external_attr = mode << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, value if type(value) is bytes else
                         (json.dumps(value, sort_keys=True, ensure_ascii=False,
                                     separators=(",", ":")) + "\n").encode())
        if extra is not None:
            archive.writestr(extra, b"extra")
    return target.getvalue()


class FakeProvider:
    def __init__(self):
        self.runs, self.workflows, self.job_sets, self.metadata, self.archives = {}, {}, {}, {}, {}
        self.run_reads, self.artifact_reads = {}, {}
        self.on_final_run = self.on_final_artifact = None
        self.writes = []
        self.subjects = {
            "linux/amd64": {"child_manifest_digest": "sha256:" + "b" * 64,
                            "image_config_digest": "sha256:" + "c" * 64},
            "linux/arm64": {"child_manifest_digest": "sha256:" + "d" * 64,
                            "image_config_digest": "sha256:" + "e" * 64},
        }
        self.selected = {
            "schema": "durable-workflow.native-alias-promotion-evidence.v1",
            "release": {"tag": "3.2.0", "source_commit": SOURCE, "index_digest": INDEX,
                        "run_id": 301, "run_attempt": 1},
            "corpus": {"run_id": 302, "run_attempt": 1, "runner_commit": SOURCE,
                       "workflow_blob": "2" * 40, "artifact_id": 501, "artifact_digest": None},
            "compose": {"run_id": 303, "run_attempt": 1, "runner_commit": SOURCE,
                        "workflow_blob": "3" * 40, "cells": []},
        }
        owner = {"id": E.REPOSITORY_ID, "full_name": E.REPOSITORY}
        for n, role in enumerate(("release", "corpus", "compose"), 1):
            selection = self.selected[role]
            workflow_id = 10 + n
            self.workflows[E.WORKFLOWS[role].split("/")[-1]] = {
                "id": workflow_id, "path": E.WORKFLOWS[role]}
            title = ("Native 3.2.0 at " + SOURCE if role == "release" else
                     ("Native image parity " if role == "corpus" else "Published Compose ")
                     + E.GHCR_IMAGE + "@" + INDEX)
            self.runs[selection["run_id"]] = {
                "id": selection["run_id"], "repository": owner, "head_repository": owner,
                "run_attempt": 1, "status": "completed", "conclusion": "success",
                "event": "workflow_dispatch", "head_sha": SOURCE, "head_branch": "3.2.0",
                "path": E.WORKFLOWS[role], "workflow_id": workflow_id, "display_title": title,
                "run_started_at": "2026-11-05T00:00:00Z" if role == "release" else "2026-11-05T00:02:00Z",
                "updated_at": "2026-11-05T00:01:00Z" if role == "release" else "2026-11-05T00:04:00Z",
            }
            if role == "release":
                names = E.RELEASE_JOBS
            elif role == "corpus":
                names = {E.CORPUS_JOB: E.QUALIFICATION_STEPS[role]}
            else:
                names = {platform + " " + profile + " published image": E.QUALIFICATION_STEPS[role]
                         for platform in E.PLATFORMS for profile in E.PROFILES}
            self.job_sets[selection["run_id"]] = [
                {"id": n * 1000 + i, "run_id": selection["run_id"], "run_attempt": 1,
                 "head_sha": SOURCE, "name": name, "status": "completed", "conclusion": "success",
                 "completed_at": "2026-11-05T00:00:59Z" if role == "release" else "2026-11-05T00:03:59Z",
                 "steps": [{"name": step, "status": "completed", "conclusion": "success"}
                           for step in critical]}
                for i, (name, critical) in enumerate(names.items(), 1)]
        corpus = self.make_receipt("corpus", "linux/amd64", None, 2001)
        self.set_artifact(501, corpus, "native-corpus-promotion-receipt", 302)
        self.selected["corpus"]["artifact_digest"] = self.metadata[501]["digest"]
        for i, (platform, profile) in enumerate(
                ((p, r) for p in E.PLATFORMS for r in E.PROFILES), 1):
            artifact_id = 501 + i
            record = self.make_receipt("compose", platform, profile, 3000 + i)
            self.set_artifact(artifact_id, record,
                              "native-compose-promotion-receipt-" + platform.split("/")[1] + "-" + profile, 303)
            self.selected["compose"]["cells"].append({
                "platform": platform, "profile": profile, "job_id": 3000 + i,
                "artifact_id": artifact_id, "artifact_digest": self.metadata[artifact_id]["digest"]})

    def make_receipt(self, role, platform, profile, job_id):
        subject = self.subjects[platform]
        observations = ({k: 0 for k in E.CORPUS_OBSERVATIONS} if role == "corpus" else
                        {k: True for k in E.COMPOSE_OBSERVATIONS})
        if role == "compose" and profile == "local":
            observations["production_default_token_refused"] = None
        return {
            "schema": "durable-workflow.native-qualification-receipt.v1", "kind": role,
            "eligible": True, "repository": E.REPOSITORY, "repository_id": E.REPOSITORY_ID,
            "run_id": 302 if role == "corpus" else 303, "run_attempt": 1, "job_id": job_id,
            "runner_commit": SOURCE, "workflow_path": E.WORKFLOWS[role],
            "workflow_blob": "2" * 40 if role == "corpus" else "3" * 40,
            "requested_image": E.GHCR_IMAGE + "@" + INDEX, "index_digest": INDEX,
            "platform": platform, "profile": profile,
            "child_manifest_digest": subject["child_manifest_digest"],
            "image_config_digest": subject["image_config_digest"],
            "actual_image_config_digest": subject["image_config_digest"],
            "container_image_architecture": platform.split("/")[1], "container_image_os": "linux",
            "image_source_commit": SOURCE, "image_release_tag": "3.2.0",
            "observations": observations,
            "cleanup": {"api_ok": True, "verified_absent": True,
                        "container_count": 0, "network_count": 0, "volume_count": 0},
        }

    def set_artifact(self, artifact_id, record, name=None, run=None, data=None):
        if artifact_id in self.metadata:
            name = self.metadata[artifact_id]["name"] if name is None else name
            run = self.metadata[artifact_id]["workflow_run"]["id"] if run is None else run
        data = packed(record) if data is None else data
        self.archives[artifact_id] = data
        self.metadata[artifact_id] = {
            "id": artifact_id, "name": name, "expired": False,
            "size_in_bytes": len(data), "digest": "sha256:" + hashlib.sha256(data).hexdigest(),
            "workflow_run": {"id": run, "repository_id": E.REPOSITORY_ID,
                             "head_repository_id": E.REPOSITORY_ID, "head_sha": SOURCE},
            "created_at": "2026-11-05T00:03:00Z",
        }
        if artifact_id == 501:
            self.selected["corpus"]["artifact_digest"] = self.metadata[artifact_id]["digest"]
        else:
            for cell in self.selected["compose"]["cells"]:
                if cell["artifact_id"] == artifact_id:
                    cell["artifact_digest"] = self.metadata[artifact_id]["digest"]

    def mutate_receipt(self, artifact_id, change):
        value = E.archive_receipt(self.archives[artifact_id])
        change(value)
        self.set_artifact(artifact_id, value)

    def api(self, path):
        prefix = "repos/" + E.REPOSITORY + "/actions/"
        if not path.startswith(prefix):
            raise AssertionError("unexpected provider path")
        suffix = path[len(prefix):]
        if suffix.startswith("workflows/"):
            return copy.deepcopy(self.workflows[suffix.split("/")[-1]])
        if suffix.startswith("artifacts/"):
            identity = int(suffix.split("/")[-1]);self.artifact_reads[identity] = self.artifact_reads.get(identity, 0) + 1
            value = copy.deepcopy(self.metadata[identity])
            if self.on_final_artifact and self.artifact_reads[identity] > 1:
                self.on_final_artifact(value)
            return value
        identity = int(suffix.split("/")[1])
        if "/jobs?" in suffix:
            return {"total_count": len(self.job_sets[identity]), "jobs": copy.deepcopy(self.job_sets[identity])}
        self.run_reads[identity] = self.run_reads.get(identity, 0) + 1
        value = copy.deepcopy(self.runs[identity])
        if self.on_final_run and self.run_reads[identity] > 1:
            self.on_final_run(value)
        return value

    def archive(self, artifact_id):
        return self.archives[artifact_id]

    def workflow_blob(self, commit, path):
        if commit != SOURCE:
            raise E.Refusal("unapproved_source")
        return "2" * 40 if path.endswith("native-image-parity.yml") else "3" * 40

    def publication_subjects(self, release):
        if release["index_digest"] != INDEX:
            raise E.Refusal("unattested_index")
        return copy.deepcopy(self.subjects)

    def promote(self):
        proof = E.verify(self.selected, self)
        self.writes.append(proof["release"]["index_digest"])


class EvidenceTests(unittest.TestCase):
    def refused(self, change):
        provider = FakeProvider();change(provider)
        with self.assertRaises(E.Refusal):provider.promote()
        self.assertEqual([], provider.writes)

    def test_unwired_library_entrypoint_cannot_succeed_as_a_guard(self):
        result=subprocess.run([sys.executable,str(ROOT / "scripts/ci/native_alias_evidence.py")],
                              capture_output=True,timeout=2,check=False)
        self.assertNotEqual(0,result.returncode)
        self.assertIn(b"executable verification is not wired",result.stderr)

    def test_complete_exact_tuple_reaches_write_ledger(self):
        provider = FakeProvider();provider.promote();self.assertEqual([INDEX], provider.writes)

    def test_run_authority_refusals_leave_zero_writes(self):
        mutations = [
            lambda p: p.runs[302].update(event="pull_request"),
            lambda p: p.runs[302].update(head_sha="f" * 40),
            lambda p: p.runs[302].update(head_branch="main"),
            lambda p: p.runs[302].update(path=E.WORKFLOWS["compose"]),
            lambda p: p.runs[302].update(workflow_id=999),
            lambda p: p.runs[302].update(run_attempt=2),
            lambda p: p.runs[302].update(conclusion="failure"),
            lambda p: p.runs[302].update(status="in_progress"),
            lambda p: p.runs[302].update(display_title="Native image parity ghcr.io/durable-workflow/server:3.2.0"),
            lambda p: p.runs[302].update(run_started_at="2026-11-05T00:00:01Z"),
            lambda p: p.runs[302].update(head_repository={"id":999,"full_name":"fork/server"}),
        ]
        for i, change in enumerate(mutations):
            with self.subTest(i=i):self.refused(change)

    def test_required_job_and_step_refusals(self):
        def missing_record_step(p):
            p.job_sets[302][0]["steps"] = [s for s in p.job_sets[302][0]["steps"]
                                          if s["name"] != "Record and compare the corpus against the native image"]
        for change in [missing_record_step,
                       lambda p: p.job_sets[302][0].update(run_attempt=2),
                       lambda p: p.job_sets[302][0].update(conclusion="skipped"),
                       lambda p: p.job_sets[302][0]["steps"][0].update(conclusion="skipped"),
                       lambda p: p.job_sets[303].pop(),
                       lambda p: p.job_sets[303].append(copy.deepcopy(p.job_sets[303][0]))]:
            with self.subTest(change=change):self.refused(change)

    def test_manifest_refuses_coercion_unknown_members_and_cell_substitution(self):
        for change in [lambda p:p.selected["release"].update(run_id=True),
                       lambda p:p.selected["release"].update(tag="3.2.0-rc.1"),
                       lambda p:p.selected.update(repository="other/repo"),
                       lambda p:p.selected["compose"]["cells"].pop(),
                       lambda p:p.selected["compose"]["cells"].__setitem__(1,copy.deepcopy(p.selected["compose"]["cells"][0])),
                       lambda p:p.selected["corpus"].update(runner_commit="f" * 40),
                       lambda p:p.selected["corpus"].update(workflow_blob="f" * 40)]:
            with self.subTest(change=change):self.refused(change)

    def test_artifact_ownership_digest_expiry_and_attempt_window(self):
        for change in [lambda p:p.metadata[501].update(expired=True),
                       lambda p:p.metadata[501].update(name="another-artifact"),
                       lambda p:p.metadata[501]["workflow_run"].update(id=303),
                       lambda p:p.metadata[501].update(created_at="2026-11-05T00:00:01Z"),
                       lambda p:p.archives.__setitem__(501,p.archives[501]+b"altered")]:
            with self.subTest(change=change):self.refused(change)

    def test_receipt_negative_controls_observe_zero_writes(self):
        changes = [lambda v:v.update(eligible=False),
                   lambda v:v.update(schema="durable-workflow.native-qualification-diagnostic.v1"),
                   lambda v:v.update(run_attempt=2),lambda v:v.update(job_id=999),
                   lambda v:v.update(runner_commit="f"*40),lambda v:v.update(requested_image=E.GHCR_IMAGE+":3.2.0"),
                   lambda v:v.update(child_manifest_digest=INDEX),lambda v:v.update(actual_image_config_digest=INDEX),
                   lambda v:v.update(platform="linux/arm64"),lambda v:v.update(extra="refuse"),
                   lambda v:v["cleanup"].update(api_ok=False),lambda v:v["cleanup"].update(container_count=1),
                   lambda v:v["cleanup"].update(volume_count=False),lambda v:v["observations"].update(compare=False)]
        for i, change in enumerate(changes):
            with self.subTest(i=i):self.refused(lambda p:p.mutate_receipt(501,change))

    def test_production_default_token_refusal_is_required(self):
        self.refused(lambda p:p.mutate_receipt(503,lambda v:v["observations"].update(production_default_token_refused=False)))

    def test_current_attempt_and_artifact_are_rechecked(self):
        self.refused(lambda p:setattr(p,"on_final_run",lambda run:run.update(run_attempt=2)))
        self.refused(lambda p:setattr(p,"on_final_artifact",lambda artifact:artifact.update(expired=True)))
        self.refused(lambda p:setattr(p,"on_final_artifact",lambda artifact:artifact.update(digest="sha256:"+"f"*64)))
        self.refused(lambda p:setattr(p,"on_final_artifact",lambda artifact:artifact["workflow_run"].update(id=999)))

    def test_hostile_archive_members_and_canonical_json(self):
        record=FakeProvider().make_receipt("corpus","linux/amd64",None,2001)
        invalid = [packed(record,"../receipt.json"),packed(record,mode=stat.S_IFLNK|0o777),
                   packed(record,extra="extra.json"),packed(b'{"x":1,"x":2}\n'),
                   packed(b'{"x":1.0}\n'),packed(b'{"x":"\\ud800"}\n'),packed(b' {"x":1}\n'),
                   packed(b'{"padding":"'+b'a'*40000+b'"}\n'),b'prefix'+packed(record),packed(record)+b'trailing']
        for i, data in enumerate(invalid):
            with self.subTest(i=i),self.assertRaises(E.Refusal):E.archive_receipt(data)

    def test_streaming_zip_descriptor_is_supported_without_extraction(self):
        class NonSeekable(io.BytesIO):
            def seek(self, *_):raise io.UnsupportedOperation("stream")
        output=NonSeekable()
        with zipfile.ZipFile(output,"w",compression=zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("receipt.json",b'{"ok":true}\n')
        self.assertEqual({"ok":True},E.archive_receipt(output.getvalue()))

    def test_subject_type_and_cross_platform_identity_refusals(self):
        self.refused(lambda p:p.subjects["linux/amd64"].update(child_manifest_digest=[]))
        self.refused(lambda p:p.subjects.__setitem__("linux/arm64",copy.deepcopy(p.subjects["linux/amd64"])))

    def test_parser_rejects_duplicate_fields_nonfinite_and_noninteger_ids(self):
        for raw in [b'{"x":1,"x":2}',b'{"x":NaN}',b'{"x":1e0}',b'{"x":Infinity}']:
            with self.subTest(raw=raw),self.assertRaises(E.Refusal):E.strict_json(raw,8192)


if __name__ == "__main__":
    unittest.main()
