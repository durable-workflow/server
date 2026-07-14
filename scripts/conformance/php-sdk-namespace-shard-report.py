from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def focused_finding(
    raw: dict[str, Any],
    scenario_id: str,
    required_assertions: list[str],
    runner_blocked: bool,
) -> dict[str, Any]:
    classification = str(raw.get("classification") or "")
    owner = str(
        raw.get("owning_surface")
        or raw.get("owner")
        or ("conformance_harness" if runner_blocked else "sdk-php")
    )
    stage = str(raw.get("failure_stage") or "namespace_assertions")
    observed = str(
        raw.get("observed_behavior")
        or raw.get("summary")
        or raw.get("message")
        or f"The released PHP SDK namespace probe failed during {stage}."
    )
    finding: dict[str, Any] = {
        "scenario_id": scenario_id,
        "owning_surface": owner,
        "observed_behavior": observed,
        "expected_behavior": str(
            raw.get("expected_behavior")
            or f"The published PHP namespace probe satisfies {', '.join(required_assertions)}."
        ),
        "next_acceptance_criterion": str(
            raw.get("next_acceptance_criterion")
            or "Correct the classified namespace failure and rerun namespaces conformance."
        ),
        "priority": str(raw.get("priority") or "P1"),
    }
    if classification:
        finding["classification"] = classification
    if stage:
        finding["failure_stage"] = stage
    if isinstance(raw.get("diagnostic"), dict):
        diagnostic = dict(raw["diagnostic"])
        diagnostic_path = str(diagnostic.get("path") or "")
        if diagnostic_path and "/" not in diagnostic_path:
            diagnostic["path"] = f"sdk-php-namespace-probe/{diagnostic_path}"
        finding["diagnostic"] = diagnostic
    return finding


def build_report(
    pins: dict[str, Any],
    probe: dict[str, Any],
    sidecar: dict[str, Any],
    started_at: str,
    suite_version: int,
) -> dict[str, Any]:
    finished = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    versions = {
        key: pins[key]
        for key in ["server", "cli", "workflow", "workflow-php", "sdk-php", "sdk-python", "waterline"]
    }
    observed = (
        sidecar.get("scenario_results", {})
        .get("php_sdk_lifecycle_surface", {})
        .get("observed_outputs", {})
    )
    if not isinstance(observed, dict):
        observed = {}
    assertions = observed.get("scenario_assertions", probe.get("assertions", {}))
    if not isinstance(assertions, dict):
        assertions = {}
    runner_blocked = bool(probe.get("runner_blocked"))
    probe_findings = probe.get("findings", []) if isinstance(probe.get("findings"), list) else []

    scenario_requirements = {
        "namespace_create_update_describe_and_list": ["namespace_lifecycle"],
        "sdk_namespace_selection_parity": ["namespace_selection"],
        "php_worker_task_queue_namespace_isolation": [
            "worker_namespace_registration",
            "namespace_worker_execution",
            "distinct_client_worker_processes",
        ],
    }
    scenario_results = []
    findings = []
    for scenario_id, required in scenario_requirements.items():
        passed = all(assertions.get(name) is True for name in required)
        status = "pass" if passed else ("runner_blocked" if runner_blocked else "fail")
        linked = []
        if status != "pass":
            raw_findings = [item for item in probe_findings if isinstance(item, dict)]
            if not raw_findings:
                raw_findings = [{
                    "owning_surface": "conformance_harness" if runner_blocked else "sdk-php",
                    "observed_behavior": (
                        "The released PHP SDK namespace probe did not satisfy "
                        + ", ".join(required)
                        + "."
                    ),
                }]
            linked = [
                focused_finding(raw, scenario_id, required, runner_blocked)
                for raw in raw_findings
            ]
            findings.extend(linked)
        scenario_results.append({
            "scenario_id": scenario_id,
            "status": status,
            "observed_outputs": {
                "required_assertions": {name: assertions.get(name) for name in required},
                "artifact_version": observed.get("artifact_version"),
                "artifact_source": observed.get("artifact_source"),
                "client_processes": observed.get("client_processes", []),
                "worker_processes": observed.get("worker_processes", []),
                "namespace_evidence": observed.get("namespace_evidence", {}),
                "local_product_source_checkouts_used": False,
            },
            "linked_findings": linked,
        })

    return {
        "schema": "durable-workflow.v2.namespace-runtime.result",
        "schema_version": 1,
        "suite_version": suite_version,
        "coverage_scope": "sdk-php-namespace-shard",
        "outcome": "pass" if all(row["status"] == "pass" for row in scenario_results) else "fail",
        "runner_blocked": runner_blocked,
        "started_at": started_at,
        "finished_at": finished,
        "generated_at": finished,
        "artifact_versions": versions,
        "artifact_sources": pins.get("artifact_sources", {}),
        "runtime_matrix": {
            "claimed_targets": ["sdk-php"],
            "covered_scenarios": list(scenario_requirements),
            "client_paths": ["sdk-php"],
        },
        "scenario_results": scenario_results,
        "sdk_php_namespace_surface": observed,
        "findings": findings,
        "finding_links": {
            row["scenario_id"]: row["linked_findings"]
            for row in scenario_results
            if row["linked_findings"]
        },
    }


def main() -> None:
    if len(sys.argv) != 7:
        raise SystemExit(
            "usage: php-sdk-namespace-shard-report.py <pins> <probe> <sidecar> <output> <started-at> <suite-version>"
        )
    pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    probe = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    sidecar = json.loads(Path(sys.argv[3]).read_text(encoding="utf-8"))
    report = build_report(pins, probe, sidecar, sys.argv[5], int(sys.argv[6]))
    Path(sys.argv[4]).write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
