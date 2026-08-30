from __future__ import annotations

import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from scripts.conformance.python_external_payload_evidence import (
    CLOUD_EVIDENCE_SCHEMA,
    REQUIRED_CAPABILITIES,
    REQUIRED_SCENARIOS,
    RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES,
    RUNTIME_EXTERNAL_PAYLOAD_REFERENCE_SCHEMA,
    cloud_evidence_handoff,
    failure_scope_for_phase,
    failure_scenario_results,
    summarize_runtime_reference,
)


ARTIFACT_VERSIONS = {
    "server": "2.0.0-rc.59",
    "cli": "2.0.0-rc.12",
    "sdk-python": "2.0.0-rc.42",
    "workflow": "2.0.0-alpha.85",
    "waterline": "2.0.0-alpha.61",
}


def passing_cloud_evidence() -> dict[str, object]:
    observations = {
        field: {"status": "pass"}
        for field in (
            "inline_round_trip",
            "externalized_round_trip",
            "cross_language_round_trip",
            "ordinary_runtime_credentials",
            "provider_setup_absent",
            "worker_replacement",
            "retained_history_replay_identity",
            "size_sha256_verification",
            "malformed_reference_rejection",
            "integrity_mismatch_rejection",
            "cleanup",
        )
    }
    return {
        "schema": CLOUD_EVIDENCE_SCHEMA,
        "version": 1,
        "evidence_id": "cloud-python-runtime-payload-20260830",
        "generated_at": "2026-08-30T09:00:00Z",
        "outcome": "pass",
        "runner_blocked": False,
        "artifact_versions": ARTIFACT_VERSIONS,
        "environment": {
            "kind": "isolated_managed_namespace",
            "namespace_isolated": True,
        },
        "source_policy": {
            "artifact_source": "published_artifacts",
            "local_product_sources_used": False,
        },
        "scenario_result": {
            "scenario_id": "runtime_external_payload_round_trips",
            "status": "pass",
            "observed_outputs": observations,
        },
    }


class PythonExternalPayloadEvidenceTest(unittest.TestCase):
    def write_evidence(self, value: object) -> Path:
        temporary = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        with temporary:
            json.dump(value, temporary)
        self.addCleanup(Path(temporary.name).unlink, missing_ok=True)
        return Path(temporary.name)

    def test_expanded_contract_is_schema_complete(self) -> None:
        self.assertIn("runtime_external_payload_round_trips", REQUIRED_SCENARIOS)
        self.assertEqual(
            set(RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES),
            set(REQUIRED_CAPABILITIES).intersection(
                RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES
            ),
        )

    def test_opaque_reference_summary_verifies_size_and_sha_without_retaining_id(
        self,
    ) -> None:
        payload = b"encoded external payload"
        reference = {
            "schema": RUNTIME_EXTERNAL_PAYLOAD_REFERENCE_SCHEMA,
            "reference_id": "ep_01ARZ3NDEKTSV4RRFFQ69G5FAV",
            "codec": "avro",
            "size_bytes": len(payload),
            "sha256": hashlib.sha256(payload).hexdigest(),
        }

        summary = summarize_runtime_reference(reference, expected_bytes=payload)

        self.assertNotIn("reference_id", summary)
        self.assertTrue(summary["opaque_reference"])
        self.assertFalse(summary["provider_specific_reference_exposed"])

    def test_reference_summary_rejects_malformed_and_integrity_mismatched_references(
        self,
    ) -> None:
        reference = {
            "schema": RUNTIME_EXTERNAL_PAYLOAD_REFERENCE_SCHEMA,
            "reference_id": "file:///provider/path",
            "codec": "avro",
            "size_bytes": 4,
            "sha256": "0" * 64,
        }
        with self.assertRaisesRegex(ValueError, "reference_id"):
            summarize_runtime_reference(reference)

        reference["reference_id"] = "ep_01ARZ3NDEKTSV4RRFFQ69G5FAV"
        with self.assertRaisesRegex(ValueError, "size"):
            summarize_runtime_reference(reference, expected_bytes=b"payload")

    def test_exact_tuple_cloud_handoff_passes(self) -> None:
        handoff = cloud_evidence_handoff(
            self.write_evidence(passing_cloud_evidence()),
            ARTIFACT_VERSIONS,
        )

        self.assertEqual("pass", handoff["status"])
        self.assertEqual("pass", handoff["capability"]["status"])
        self.assertEqual([], handoff["findings"])
        self.assertEqual(
            ARTIFACT_VERSIONS, handoff["isolated_cloud"]["artifact_versions"]
        )

    def test_missing_cloud_handoff_is_actionable_not_covered_evidence(self) -> None:
        handoff = cloud_evidence_handoff(None, ARTIFACT_VERSIONS)

        self.assertEqual("not_covered", handoff["status"])
        self.assertEqual("not_covered", handoff["capability"]["status"])
        self.assertEqual(
            "conformance_runner_coverage_gap",
            handoff["findings"][0]["type"],
        )
        self.assertIn("next_acceptance_criterion", handoff["findings"][0])

    def test_attempted_runtime_external_payload_failure_is_not_a_coverage_gap(
        self,
    ) -> None:
        finding = {"summary": "runtime external payload round trip failed"}

        results = failure_scenario_results("runtime_external_payload", finding)

        self.assertEqual(
            "fail", results["runtime_external_payload_round_trips"]["status"]
        )
        self.assertEqual(
            "not_covered",
            results["worker_restart_activity_and_signal_state"]["status"],
        )
        self.assertEqual(
            [finding],
            results["runtime_external_payload_round_trips"]["linked_findings"],
        )

    def test_namespace_cleanup_failure_is_an_attempted_runtime_payload_failure(
        self,
    ) -> None:
        finding = {"summary": "namespace deletion count was incomplete"}

        for phase in (
            "namespace_cleanup",
            "namespace_cleanup_deletion_count_verification",
        ):
            failure_scope = failure_scope_for_phase(phase)
            results = failure_scenario_results(failure_scope, finding)

            self.assertEqual("runtime_external_payload", failure_scope)
            self.assertEqual(
                "fail",
                results["runtime_external_payload_round_trips"]["status"],
            )
            self.assertNotEqual(
                "not_covered",
                results["runtime_external_payload_round_trips"]["status"],
            )

    def test_wrong_tuple_and_sensitive_cloud_fields_fail_closed(self) -> None:
        evidence = passing_cloud_evidence()
        evidence["artifact_versions"] = {
            **ARTIFACT_VERSIONS,
            "sdk-python": "2.0.0-rc.41",
        }
        evidence["provider_credentials"] = {"client_secret": "must-not-cross-handoff"}

        handoff = cloud_evidence_handoff(
            self.write_evidence(evidence), ARTIFACT_VERSIONS
        )

        self.assertEqual("fail", handoff["status"])
        failures = handoff["findings"][0]["failures"]
        self.assertIn("artifact_versions.sdk-python", failures)
        self.assertTrue(any(item.startswith("sensitive_field.") for item in failures))


if __name__ == "__main__":
    unittest.main()
