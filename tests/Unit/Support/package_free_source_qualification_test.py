"""Closed child-command controls for the package-free source route."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
STEP = "      - name: Qualify the PHP APT source prerequisite without packages\n        run: |\n"
CHILD = "/usr/bin/env -i PATH=/usr/bin:/bin LC_ALL=C "
PYTHON = CHILD + "/usr/bin/python3 -I -S -B "
COMMANDS = [
    PYTHON + "--version",
    PYTHON + "tests/Unit/Support/package_free_source_qualification_test.py",
    PYTHON + "tests/Unit/Support/php_apt_test.py",
    PYTHON + "tests/Unit/Support/brotli_trust_test.py",
    PYTHON + "scripts/ci/verify-php-apt-source.py",
    CHILD + "/bin/sh -n docker/apt/require-fixed-apt.sh",
    CHILD + "/usr/bin/shellcheck docker/apt/require-fixed-apt.sh",
]


def require_closed_commands(source):
    if source.count(STEP) != 1:
        raise ValueError("source qualification step is missing or ambiguous")
    body = source.split(STEP, 1)[1].split("\n      - name: ", 1)[0].rstrip("\n")
    expected = "\n".join("          " + command for command in COMMANDS)
    if body != expected:
        raise ValueError("source qualification child commands changed")
    if "    timeout-minutes: 5\n" not in source or "          persist-credentials: false\n" not in source:
        raise ValueError("source qualification deadline or credential boundary changed")


class PackageFreeSourceQualificationTest(unittest.TestCase):
    def setUp(self):
        self.source = (ROOT / ".github/workflows/public-boundary.yml").read_text()

    def test_current_child_commands_are_literal_absolute_and_closed(self):
        require_closed_commands(self.source)
        self.assertEqual(sum("/usr/bin/python3 " in command for command in COMMANDS), 5)

    def test_missing_or_altered_python_flags_and_interpreter_refuse(self):
        for command in COMMANDS[:5]:
            for flag in ("-I", "-S", "-B"):
                for replacement in ("", "--wrong-flag"):
                    changed = command.replace(flag + " ", replacement + " ", 1)
                    with self.subTest(command=command, flag=flag, replacement=replacement), self.assertRaises(ValueError):
                        require_closed_commands(self.source.replace(command, changed, 1))
            changed = command.replace("/usr/bin/python3", "/usr/local/bin/python3", 1)
            with self.assertRaises(ValueError):
                require_closed_commands(self.source.replace(command, changed, 1))

    def test_extra_environment_commands_and_reordering_refuse(self):
        for changed in (self.source.replace(CHILD, CHILD + "PYTHONPATH=/unreviewed ", 1),
                        self.source.replace("-I -S -B", "-B -S -I", 1),
                        self.source.replace(COMMANDS[0], COMMANDS[0] + "\n          /bin/true", 1),
                        self.source.replace(COMMANDS[2], COMMANDS[3], 1)):
            with self.assertRaises(ValueError):
                require_closed_commands(changed)

    def test_missing_step_unbounded_job_or_persisted_credentials_refuse(self):
        for changed in (self.source.replace(STEP, ""), self.source.replace("timeout-minutes: 5", "timeout-minutes: 60"),
                        self.source.replace("persist-credentials: false", "persist-credentials: true")):
            with self.assertRaises(ValueError):
                require_closed_commands(changed)


if __name__ == "__main__":
    unittest.main()
