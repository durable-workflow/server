import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/perf/run-vultr-soak.sh"


class DisposableRunnerCleanupTest(unittest.TestCase):
    def run_cleanup(self, responses, expected_code=0):
        source = SCRIPT.read_text()
        cleanup = "destroy_instance() {\n" + source.split(
            "destroy_instance() {\n", 1
        )[1].split("\ncleanup() {", 1)[0]
        harness = r'''
set -Eeuo pipefail
INSTANCE_ID=fixture-owned-instance
log() { printf '%s\n' "$*"; }
sleep() { printf 'sleep %s\n' "$1" >> "$CALLS"; }
api() {
  local method status result
  read -r method status result < "$RESPONSES"
  tail -n +2 "$RESPONSES" > "$RESPONSES.next"
  mv "$RESPONSES.next" "$RESPONSES"
  printf '%s %s\n' "$1" "$2" >> "$CALLS"
  [[ "$1" == "$method" && "$2" == /instances/fixture-owned-instance ]] || return 99
  printf '%s' "$status"
  return "$result"
}
'''
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            sequence = root / "responses"
            sequence.write_text("".join(f"{m} {s} {c}\n" for m, s, c in responses))
            calls = root / "calls"
            summary = root / "summary"
            result = subprocess.run(
                ["bash", "-c", harness + cleanup + "\ndestroy_instance\n"],
                env={**os.environ, "RESPONSES": str(sequence), "CALLS": str(calls),
                     "GITHUB_STEP_SUMMARY": str(summary)},
                text=True, capture_output=True, timeout=5,
            )
            self.assertEqual(expected_code, result.returncode, result.stdout + result.stderr)
            self.assertEqual("", sequence.read_text())
            if expected_code:
                self.assertIn("not been confirmed absent", summary.read_text())
                self.assertNotIn("Verified Vultr", result.stdout)
            else:
                self.assertFalse(summary.exists())
                self.assertIn("Verified Vultr", result.stdout)
            return calls.read_text()

    def test_transient_delete_failure_retries_same_identity(self):
        calls = self.run_cleanup([
            ("DELETE", 502, 0), ("GET", 200, 0),
            ("DELETE", 204, 0), ("GET", 404, 0),
        ])
        self.assertEqual(2, calls.count("DELETE /instances/fixture-owned-instance"))
        self.assertEqual(1, calls.count("sleep 5"))

    def test_lost_delete_acknowledgement_reconciles_absence(self):
        calls = self.run_cleanup([("DELETE", "000", 28), ("GET", 404, 0)])
        self.assertNotIn("sleep", calls)

    def test_already_absent_is_success(self):
        self.run_cleanup([("DELETE", 404, 0), ("GET", 404, 0)])

    def test_accepted_delete_is_not_proof_of_absence(self):
        self.run_cleanup([
            ("DELETE", 204, 0), ("GET", 200, 0),
            ("DELETE", 404, 0), ("GET", 404, 0),
        ])

    def test_missing_read_response_does_not_prove_absence(self):
        self.run_cleanup([
            ("DELETE", 204, 0), ("GET", "000", 28),
            ("DELETE", 404, 0), ("GET", 404, 0),
        ])

    def test_exhausted_attempts_fail_visibly(self):
        calls = self.run_cleanup([("DELETE", 502, 0), ("GET", 200, 0)] * 4, 1)
        self.assertEqual(4, calls.count("DELETE /instances/fixture-owned-instance"))
        self.assertEqual(3, calls.count("sleep 5"))

    def test_permission_failure_does_not_retry(self):
        calls = self.run_cleanup([("DELETE", 403, 0), ("GET", 200, 0)], 1)
        self.assertNotIn("sleep", calls)


if __name__ == "__main__":
    unittest.main()
