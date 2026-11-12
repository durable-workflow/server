"""Server #802: the native image scan receipt and its promotion rule."""
from __future__ import annotations

import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[3] / "scripts" / "ci"))

import native_scan_receipt as R  # noqa: E402

IMAGE = "ghcr.io/durable-workflow/server@sha256:" + "a" * 64
SCANNER = "docker.io/aquasec/trivy@sha256:" + "b" * 64
VERSION = {"Version": "0.76.1", "VulnerabilityDB": {"Version": 2, "UpdatedAt": "2026-11-11T19:04:54Z",
                                                    "DownloadedAt": "2026-11-12T01:41:40Z"}}


def vulnerability(id_: str, package: str, severity: str, status: str, fixed: str | None = None) -> dict:
    row = {"VulnerabilityID": id_, "PkgName": package, "InstalledVersion": "1.0", "Severity": severity, "Status": status}
    if fixed:
        row["FixedVersion"] = fixed
    return row


def report(*vulnerabilities: dict, lang: tuple[dict, ...] = ()) -> dict:
    return {"CreatedAt": "2026-11-12T01:42:00Z", "Metadata": {"RepoDigests": [IMAGE], "OS": {"Family": "debian", "Name": "12.15"}},
            "Results": [{"Target": IMAGE, "Class": "os-pkgs", "Vulnerabilities": list(vulnerabilities)},
                        {"Target": "durable-workflow-server", "Class": "lang-pkgs", "Vulnerabilities": list(lang)}]}


class ScanReceipt(unittest.TestCase):
    def test_unfixed_inventory_is_kept_and_does_not_hold(self) -> None:
        result = R.receipt(report(vulnerability("CVE-1", "zlib1g", "CRITICAL", "will_not_fix"),
                                  vulnerability("CVE-2", "libc6", "HIGH", "affected"),
                                  vulnerability("CVE-3", "bash", "MEDIUM", "fixed", "2.0")), VERSION, IMAGE, "linux/amd64", SCANNER)
        self.assertEqual(result["counts"], {"critical": 1, "high": 1, "advisories": 2, "actionable": 0})
        self.assertEqual([row["id"] for row in result["findings"]], ["CVE-1", "CVE-2"])
        self.assertEqual((result["scanner"]["version"], result["database"]["updated_at"]), ("0.76.1", "2026-11-11T19:04:54Z"))
        self.assertTrue(result["fingerprint"].startswith("sha256:"))

    def test_a_fixed_high_row_holds_and_names_its_class(self) -> None:
        result = R.receipt(report(vulnerability("CVE-4", "apt", "HIGH", "fixed", "2.6.2"),
                                  lang=(vulnerability("RUSTSEC-1", "hyper", "CRITICAL", "fixed", "1.1"),)),
                           VERSION, IMAGE, "linux/arm64", SCANNER)
        self.assertEqual(result["counts"]["actionable"], 2)
        self.assertEqual({row["package"]: row["class"] for row in result["findings"]}, {"apt": "os-pkgs", "hyper": "lang-pkgs"})

    def test_the_fingerprint_ignores_scan_time_and_follows_rows(self) -> None:
        rows = (vulnerability("CVE-2", "libc6", "HIGH", "affected"),)
        first = R.receipt(report(*rows), VERSION, IMAGE, "linux/amd64", SCANNER)
        later = report(*rows)
        later["CreatedAt"] = "2026-11-19T00:00:00Z"
        self.assertEqual(R.receipt(later, VERSION, IMAGE, "linux/amd64", SCANNER)["fingerprint"], first["fingerprint"])
        changed = R.receipt(report(vulnerability("CVE-2", "libc6", "HIGH", "fixed", "2.1")), VERSION, IMAGE, "linux/amd64", SCANNER)
        self.assertNotEqual(changed["fingerprint"], first["fingerprint"])

    def test_refuses_an_unpinned_image(self) -> None:
        unpinned = report()
        with self.assertRaises(SystemExit):
            R.receipt(unpinned, VERSION, "ghcr.io/durable-workflow/server:latest", "linux/amd64", SCANNER)

    def test_refuses_a_report_for_another_image(self) -> None:
        other = report()
        other["Metadata"]["RepoDigests"] = ["ghcr.io/durable-workflow/server@sha256:" + "c" * 64]
        with self.assertRaises(SystemExit):
            R.receipt(other, VERSION, IMAGE, "linux/amd64", SCANNER)


if __name__ == "__main__":
    unittest.main()
