#!/usr/bin/env python3
"""Normalize the PHP SDK's declared replay matrix without hiding early exits."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any


REQUIRED_PHP_REPLAY_SCENARIOS = (
    "php_completed_history_activity_replay",
    "php_completed_history_signal_update_replay",
    "php_completed_history_wait_condition_replay",
    "php_completed_history_version_marker_replay",
    "php_completed_history_saga_compensation_replay",
    "php_worker_restart_completed_query",
    "php_worker_restart_activity_state",
    "php_worker_restart_signal_update_state",
    "php_worker_restart_wait_condition_state",
    "php_worker_restart_version_marker_state",
    "php_worker_restart_saga_compensation_state",
    "php_code_divergence_refusal",
    "php_in_flight_signal_restart_timing",
)


def _valid_executed_scenario(scenario_id: str, evidence: Any) -> bool:
    if not isinstance(evidence, dict):
        return False
    runtime_cell = evidence.get("runtime_cell")
    observed = evidence.get("observed_outputs")

    return (
        evidence.get("scenario_id") == scenario_id
        and evidence.get("status") in {"pass", "fail"}
        and evidence.get("executed_runtime_cell") is True
        and isinstance(runtime_cell, dict)
        and runtime_cell.get("executed") is True
        and bool(runtime_cell.get("cell_id"))
        and isinstance(observed, dict)
        and observed.get("runtime_cell_executed") is True
    )


def expand_php_replay_scenarios(
    source: dict[str, Any],
) -> tuple[dict[str, dict[str, Any]], dict[str, Any]]:
    """Return one honest cell for every declared PHP replay scenario."""

    raw = source.get("replay_scenario_results")
    raw = raw if isinstance(raw, dict) else {}
    results = {
        scenario_id: dict(raw[scenario_id])
        for scenario_id in REQUIRED_PHP_REPLAY_SCENARIOS
        if _valid_executed_scenario(scenario_id, raw.get(scenario_id))
    }
    reported = list(results)
    missing = [
        scenario_id
        for scenario_id in REQUIRED_PHP_REPLAY_SCENARIOS
        if scenario_id not in results
    ]
    status = "runner_blocked" if source.get("runner_blocked") is True else "fail"
    findings = [
        dict(finding)
        for finding in source.get("findings") or []
        if isinstance(finding, dict)
    ]
    failure_summaries = [
        str(finding.get("summary"))
        for finding in findings
        if finding.get("summary")
    ]
    for scenario_id in missing:
        results[scenario_id] = {
            "scenario_id": scenario_id,
            "status": status,
            "executed_runtime_cell": False,
            "runtime_cell": {
                "cell_id": None,
                "executed": False,
                "reason": "worker_exited_before_replay_matrix_cell",
            },
            "observed_outputs": {
                "runtime_cell_executed": False,
                "replay_matrix_complete": False,
                "source_outcome": source.get("outcome"),
                "worker_startup": source.get("worker_startup"),
                "failure_summaries": failure_summaries,
            },
            "linked_findings": findings,
        }

    ordered = {
        scenario_id: results[scenario_id]
        for scenario_id in REQUIRED_PHP_REPLAY_SCENARIOS
    }
    contract = {
        "declared_scenarios": list(REQUIRED_PHP_REPLAY_SCENARIOS),
        "reported_executed_scenarios": reported,
        "missing_executed_scenarios": missing,
        "all_declared_scenarios_emitted": set(ordered) == set(REQUIRED_PHP_REPLAY_SCENARIOS),
        "all_declared_scenarios_executed": missing == [],
    }

    return ordered, contract


def main() -> int:
    if len(sys.argv) != 3:
        print(f"usage: {Path(sys.argv[0]).name} <source-result.json> <expanded-result.json>", file=sys.stderr)
        return 2

    source = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    if not isinstance(source, dict):
        raise ValueError("PHP SDK source result must be a JSON object.")
    scenarios, contract = expand_php_replay_scenarios(source)
    Path(sys.argv[2]).write_text(
        json.dumps({"scenario_results": scenarios, "scenario_contract": contract}, indent=2) + "\n",
        encoding="utf-8",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
