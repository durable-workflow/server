#!/usr/bin/env python3
"""Evidence manifest selection from three run IDs. No network, Docker or credentials."""
import copy
from pathlib import Path
import subprocess
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[3] / "scripts/ci"))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import native_alias_evidence as E
import native_alias_manifest as M
from native_alias_evidence_test import INDEX, RUNNER, RUNNER_REF, FakeProvider

ROOT = Path(__file__).resolve().parents[3]


def build(provider, tag="3.2.0"):
    return M.build(provider, tag, 301, 302, 303)


class ManifestSelectionTests(unittest.TestCase):
    def test_three_run_ids_select_the_exact_manifest_the_verifier_accepts(self):
        provider = FakeProvider()
        value = build(provider)
        self.assertEqual(value, provider.selected)
        self.assertEqual(E.manifest(E.canonical(value)), value)
        self.assertEqual(E.verify(value, provider)["qualified_artifact_ids"], [501, 502, 503, 504, 505])

    def test_selection_follows_the_current_attempt_and_its_jobs(self):
        provider = FakeProvider()
        provider.runs[303]["run_attempt"] = 2
        for job in provider.job_sets[303]:
            job.update(run_attempt=2, id=job["id"] + 10)
        provider.job_sets[303] += [{**copy.deepcopy(job), "run_attempt": 1, "id": job["id"] - 10}
                                   for job in provider.job_sets[303]]
        value = build(provider)
        self.assertEqual(value["compose"]["run_attempt"], 2)
        self.assertEqual(sorted(cell["job_id"] for cell in value["compose"]["cells"]), [3011, 3012, 3013, 3014])

    def test_runs_not_dispatched_from_the_release_tag_refuse(self):
        cases = [
            (lambda p: p.runs[302].update(head_branch=RUNNER_REF.removeprefix("refs/heads/"), head_sha=RUNNER),
             "selection_corpus_run"),
            (lambda p: p.runs[303].update(head_branch="main"), "selection_compose_run"),
            (lambda p: p.runs[301].update(head_branch="main"), "selection_release_run"),
            (lambda p: p.runs[302].update(head_sha=RUNNER), "selection_corpus_source"),
            (lambda p: p.runs[302].update(event="push"), "selection_corpus_run"),
            (lambda p: p.runs[303].update(conclusion="failure"), "selection_compose_run"),
            (lambda p: p.runs[303].update(status="in_progress"), "selection_compose_run"),
            (lambda p: p.runs[302].update(path=E.WORKFLOWS["compose"]), "selection_corpus_run"),
            (lambda p: p.runs[301].update(display_title="Native 3.2.0 at " + RUNNER), "selection_release_run"),
        ]
        for change, reason in cases:
            provider = FakeProvider()
            change(provider)
            with self.subTest(reason=reason), self.assertRaisesRegex(E.Refusal, reason):
                build(provider)

    def test_both_qualifiers_must_name_one_canonical_index(self):
        cases = [
            lambda p: p.runs[303].update(display_title="Published Compose " + E.GHCR_IMAGE + "@sha256:" + "0" * 64),
            lambda p: p.runs[302].update(display_title="Native image parity " + E.GHCR_IMAGE + ":3.2.0"),
            lambda p: p.runs[303].update(display_title="Published Compose durableworkflow/server@" + INDEX),
        ]
        for change in cases:
            provider = FakeProvider()
            change(provider)
            with self.subTest(change=change), self.assertRaisesRegex(E.Refusal, "selection_qualified_image"):
                build(provider)
        provider = FakeProvider()
        provider.runs[302]["display_title"] = "Published Compose " + E.GHCR_IMAGE + "@" + INDEX
        with self.assertRaisesRegex(E.Refusal, "selection_corpus_source"):
            build(provider)

    def test_receipt_artifacts_are_exact_never_the_newest_of_several(self):
        cases = [
            (lambda p: p.metadata.pop(503), "selection_artifact_ambiguous"),
            (lambda p: p.metadata.__setitem__(599, {**copy.deepcopy(p.metadata[501]), "id": 599}),
             "selection_artifact_ambiguous"),
            (lambda p: p.metadata[501].update(expired=True), "selection_artifact_identity"),
            (lambda p: p.metadata[502].update(digest="sha1:" + "0" * 40), "selection_artifact_identity"),
            (lambda p: p.metadata[502].update(id=True), "selection_artifact_identity"),
        ]
        for change, reason in cases:
            provider = FakeProvider()
            change(provider)
            with self.subTest(reason=reason), self.assertRaisesRegex(E.Refusal, reason):
                build(provider)

    def test_qualifier_jobs_and_steps_are_checked_before_selection(self):
        for change in (lambda p: p.job_sets[302][0]["steps"].pop(0),
                       lambda p: p.job_sets[303].pop(),
                       lambda p: p.job_sets[303][2]["steps"].pop()):
            provider = FakeProvider()
            change(provider)
            with self.subTest(change=change), self.assertRaises(E.Refusal):
                build(provider)

    def test_candidate_tags_and_coerced_run_ids_refuse_before_any_read(self):
        class NoReads(FakeProvider):
            def api(self, path):
                raise AssertionError("no provider read")
        for tag, runs in (("3.2.0-rc.1", (301, 302, 303)), ("latest", (301, 302, 303)),
                          ("3.2.0", (True, 302, 303)), ("3.2.0", (301, 0, 303))):
            with self.subTest(tag=tag, runs=runs), self.assertRaises(E.Refusal):
                M.build(NoReads(), tag, *runs)

    def test_cli_runs_isolated_and_prints_only_a_static_reason(self):
        script = str(ROOT / "scripts/ci/native_alias_manifest.py")
        result = subprocess.run([sys.executable, "-I", script, "--help"], capture_output=True, timeout=10, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        result = subprocess.run([sys.executable, "-I", script, "--tag", "3.2.0-rc.1", "--release-run", "1",
                                 "--corpus-run", "2", "--compose-run", "3"],
                                capture_output=True, timeout=10, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, b"")
        self.assertEqual(result.stderr, b"Native promotion evidence refused: final_release_required\n")


if __name__ == "__main__":
    unittest.main()
