#!/usr/bin/env python3
"""Compact receipt for one Trivy scan of a native image platform digest (#802).

Reads Trivy's JSON report and `trivy version` JSON and writes a small,
metadata-only receipt: the scanned digest, scanner and database identity,
every HIGH/CRITICAL row with its fix status, and a fingerprint of those rows.
The full report is not kept.

A row is actionable when Trivy reports a fixed version for it (status
`fixed`). Actionable rows hold promotion: the script exits 1. Unfixed
distribution rows are inventory for Security's reachability disposition on
server#802, not a failure.

Usage: native_scan_receipt.py VERSION_JSON IMAGE PLATFORM SCANNER_IMAGE < REPORT
(the Trivy report on standard input, `trivy version --format json` as text)
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
from typing import Any

SCHEMA = "durable-workflow.native-image-scan/v1"
SEVERITIES = ("CRITICAL", "HIGH")
DIGEST_REF = re.compile(r"[a-z0-9./-]+@sha256:[0-9a-f]{64}\Z")


def rows(report: dict[str, Any]) -> list[dict[str, Any]]:
    found = []
    for result in report.get("Results") or []:
        for vulnerability in result.get("Vulnerabilities") or []:
            severity = vulnerability.get("Severity")
            if severity not in SEVERITIES:
                continue
            fixed = vulnerability.get("FixedVersion") or None
            status = vulnerability.get("Status") or ("fixed" if fixed else "unknown")
            found.append({
                "id": vulnerability["VulnerabilityID"],
                "severity": severity,
                "package": vulnerability["PkgName"],
                "installed": vulnerability.get("InstalledVersion"),
                "fixed": fixed,
                "status": status,
                # os-pkgs: distribution packages; lang-pkgs: Cargo and other
                # language dependencies.
                "class": result.get("Class"),
                "actionable": fixed is not None and status == "fixed",
            })
    found.sort(key=lambda row: (SEVERITIES.index(row["severity"]), row["id"], row["package"], row["installed"] or ""))
    return found


def receipt(report: dict[str, Any], version: dict[str, Any], image: str, platform: str, scanner: str) -> dict[str, Any]:
    if not DIGEST_REF.match(image) or not DIGEST_REF.match(scanner):
        raise SystemExit("image and scanner must be named by digest")
    digests = (report.get("Metadata") or {}).get("RepoDigests") or []
    if image not in digests:
        raise SystemExit(f"report does not name the scanned digest {image}")
    found = rows(report)
    database = version.get("VulnerabilityDB") or {}
    normalized = json.dumps([{key: row[key] for key in ("id", "package", "installed", "fixed", "status")} for row in found],
                            separators=(",", ":"), sort_keys=True)
    return {
        "schema": SCHEMA,
        "image": image,
        "platform": platform,
        "os": (report.get("Metadata") or {}).get("OS"),
        "scanned_at": report.get("CreatedAt"),
        "scanner": {"name": "trivy", "version": version.get("Version"), "image": scanner},
        "database": {"version": database.get("Version"), "updated_at": database.get("UpdatedAt"),
                     "downloaded_at": database.get("DownloadedAt")},
        "counts": {
            "critical": sum(row["severity"] == "CRITICAL" for row in found),
            "high": sum(row["severity"] == "HIGH" for row in found),
            "advisories": len({row["id"] for row in found}),
            "actionable": sum(row["actionable"] for row in found),
        },
        "fingerprint": "sha256:" + hashlib.sha256(normalized.encode()).hexdigest(),
        "findings": found,
    }


def main(argv: list[str]) -> int:
    if len(argv) != 5:
        print(__doc__, file=sys.stderr)
        return 2
    _, version_text, image, platform, scanner = argv
    report = json.load(sys.stdin)
    version = json.loads(version_text)
    result = receipt(report, version, image, platform, scanner)
    json.dump(result, sys.stdout, indent=1)
    sys.stdout.write("\n")
    counts = result["counts"]
    print(f"{platform}: {counts['critical']} critical, {counts['high']} high, {counts['actionable']} with a fixed version",
          file=sys.stderr)
    for row in result["findings"]:
        if row["actionable"]:
            print(f"actionable: {row['id']} {row['package']} {row['installed']} -> {row['fixed']} ({row['severity']})",
                  file=sys.stderr)
    return 1 if counts["actionable"] else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
