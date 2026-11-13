#!/usr/bin/env python3
"""Every networked APT path in a tracked Dockerfile runs on a proved fixed APT
or with APT retries off.

CVE-2026-109518 (durable-workflow/.github#295): a vulnerable APT can follow a
redirect on a retried package download and install a swapped package as root.
In each build stage, the first networked APT command must come after either
the reviewed fixed-floor proof (docker/apt/require-fixed-apt.sh) or a line
that writes Acquire::Retries "0", which removes the retry precondition.
"""

from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
APT = re.compile(r'\bapt(?:-get)?\s+(?:-\S+\s+)*(?:update|install|upgrade|dist-upgrade|full-upgrade)\b')
GUARDS = ('require-fixed-apt.sh', 'Acquire::Retries "0"')
STAGE = re.compile(r'^\s*FROM\s', re.IGNORECASE | re.MULTILINE)


def dockerfiles():
    tracked = subprocess.run(['git', 'ls-files'], cwd=ROOT, capture_output=True, text=True, check=True).stdout.split()
    for path in tracked:
        name = path.rsplit('/', 1)[-1].lower()
        if name.startswith('dockerfile') or name.endswith('.dockerfile'):
            yield path


def unprotected(text):
    """Stages whose first networked APT command has no guard before it."""
    for index, stage in enumerate(STAGE.split(text)):
        found = APT.search(stage)
        if found is None:
            continue
        before = stage[:found.start()]
        if not any(guard in before for guard in GUARDS):
            yield index, found.group(0)


class AptNoRetriesTest(unittest.TestCase):
    def test_every_networked_apt_stage_is_guarded_first(self):
        found = [f'{path}: stage {stage}: {command}'
                 for path in dockerfiles()
                 for stage, command in unprotected((ROOT / path).read_text(encoding='utf-8'))]
        self.assertEqual([], found)

    def test_checker_refuses_a_late_or_missing_guard(self):
        retries = "printf 'Acquire::Retries \"0\";\\n' > /etc/apt/apt.conf.d/99-no-retries"
        self.assertEqual([], list(unprotected(f'FROM a\nRUN {retries} \\\n    && apt-get update\n')))
        self.assertEqual([], list(unprotected('FROM a\nRUN sh require-fixed-apt.sh && apt-get install -y x\n')))
        self.assertEqual(1, len(list(unprotected(f'FROM a\nRUN apt-get update && {retries}\n'))))
        self.assertEqual(1, len(list(unprotected('FROM a\nRUN apt-get install -y --no-install-recommends x\n'))))
        # A guard in one stage does not cover the next stage.
        self.assertEqual(1, len(list(unprotected(f'FROM a\nRUN {retries} && apt-get update\nFROM b\nRUN apt upgrade\n'))))


if __name__ == '__main__':
    unittest.main()
