"""The Server image's Brotli decoders carry the CVE-2026-111497 fix.

Debian's security team fixed CVE-2026-111497 in libbrotli1 1.1.0-2+deb13u1
(trixie-security), so the image installs Debian's signed package over the
base image's 1.1.0-2+b7 instead of rebuilding it. These checks keep the
minimum version and the Dockerfile wiring as reviewed. The image-level check
is docker/brotli/verify-brotli.sh, which the build runs.

Run with: python3 -m unittest tests/Unit/Support/brotli_trust_test.py
"""

from __future__ import annotations

import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
BROTLI = ROOT / "docker" / "brotli"
MINIMUM = "1.1.0-2+deb13u1"


def debian_compare(left: str, right: str) -> int:
    """dpkg's version ordering, for versions without epochs."""

    def order(char: str) -> int:
        if char == "~":
            return -1
        if char.isalpha():
            return ord(char)
        return ord(char) + 256

    def part(a: str, b: str) -> int:
        while a or b:
            a_text = re.match(r"[^0-9]*", a).group()
            b_text = re.match(r"[^0-9]*", b).group()
            for index in range(max(len(a_text), len(b_text))):
                x = order(a_text[index]) if index < len(a_text) else 0
                y = order(b_text[index]) if index < len(b_text) else 0
                if x != y:
                    return -1 if x < y else 1
            a, b = a[len(a_text):], b[len(b_text):]
            a_digits = re.match(r"[0-9]*", a).group()
            b_digits = re.match(r"[0-9]*", b).group()
            if int(a_digits or 0) != int(b_digits or 0):
                return -1 if int(a_digits or 0) < int(b_digits or 0) else 1
            a, b = a[len(a_digits):], b[len(b_digits):]
        return 0

    left_upstream, _, left_revision = left.rpartition("-")
    right_upstream, _, right_revision = right.rpartition("-")
    return part(left_upstream, right_upstream) or part(left_revision, right_revision)


def stages(dockerfile: str) -> dict[str, str]:
    """Each named stage's text, from its FROM line to the next."""
    parts = re.split(r"(?m)^(?=FROM )", dockerfile)
    named = {}
    for text in parts:
        match = re.match(r"FROM (\S+)(?: AS (\S+))?", text)
        if match and match.group(2):
            named[match.group(2)] = text
    return named


def dockerfile_problems(dockerfile: str) -> list[str]:
    problems = []
    named = stages(dockerfile)
    base = named.get("base", "")
    if "brotli" in named or "server-brotli-deb" in dockerfile or "server-brotli/build.sh" in dockerfile:
        problems.append("the image must take Debian's fixed libbrotli1, not a rebuild")
    trust = base.find("server-verify-trust system")
    install = base.find("apt-get install")
    package = base.find("\n    libbrotli1 \\\n", install)
    if install < 0 or package < 0 or base.find("&& ", install, package) >= 0:
        problems.append("the runtime's package list must take libbrotli1")
    if trust < 0 or install < 0 or trust > install:
        problems.append("the runtime must check its CA store before it downloads")
    final = base.find("server-verify-trust final")
    if final < 0 or base.find("server-verify-brotli", final) < 0:
        problems.append("the runtime must verify its decoders before production downloads")
    last_package_step = dockerfile.rfind("apt-get autoremove")
    if last_package_step < 0 or dockerfile.find("server-verify-brotli", last_package_step) < 0:
        problems.append("the image must verify its decoders after its last package change")
    source = named.get("phpredis-source", "")
    floor = source.find('apk add --no-cache --upgrade "brotli-libs>=1.2.1-r0"')
    if floor < 0 or floor > source.find("RUN git clone"):
        problems.append("the Alpine stage must take the fixed brotli-libs before it clones")
    return problems


class BrotliTrustTest(unittest.TestCase):
    def test_the_minimum_is_debians_security_fix(self) -> None:
        self.assertEqual((BROTLI / "version").read_text().strip(), MINIMUM)
        self.assertGreater(debian_compare(MINIMUM, "1.1.0-2+b7"), 0, "above the base image's package")
        self.assertGreater(debian_compare(MINIMUM, "1.1.0-2+b7+dw1"), 0, "above the retired rebuild")
        self.assertLess(debian_compare(MINIMUM, "1.1.0-2+deb13u2"), 0, "a later Debian fix still passes")
        self.assertEqual(debian_compare("1.0~rc1-1", "1.0-1"), -1)
        verify = (BROTLI / "verify-brotli.sh").read_text()
        self.assertIn('dpkg --compare-versions "$installed" ge "$minimum"', verify)

    def test_no_rebuild_remains(self) -> None:
        for name in ("build.sh", "source.sha256", "CVE-2026-111497.patch"):
            self.assertFalse((BROTLI / name).exists(), name)

    def test_the_check_identifies_the_fix_without_a_malformed_stream(self) -> None:
        check = (BROTLI / "brotli-check.py").read_text()
        self.assertIn('BrotliDecoderErrorString(-17)', check)
        self.assertIn('b"_ERROR_FORMAT_BLOCK_SWITCH"', check)
        verify = (BROTLI / "verify-brotli.sh").read_text()
        for required in ("dpkg-query -W -f '${Version}' libbrotli1", "dpkg -S \"$library\"", "libbrotli1:*)",
                         'sh "$here/decoder-inventory.sh" / "$library"', "ldd ",
                         "{22: [23, 4], 24: [22, 1], 26: [12, 1]}", "brotli-check.py"):
            self.assertIn(required, verify)
        inventory = (BROTLI / "decoder-inventory.sh").read_text()
        for required in ("\\( -type f -o -type l \\) -name 'libbrotlidec*'", 'readlink -f "$path"'):
            self.assertIn(required, inventory)

    def test_every_decoder_path_must_resolve_to_the_reviewed_library(self) -> None:
        def inventory(root: Path, library: Path) -> subprocess.CompletedProcess:
            return subprocess.run(["sh", str(BROTLI / "decoder-inventory.sh"), str(root), str(library)],
                                  capture_output=True, text=True, check=False)

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lib = root / "usr" / "lib"
            lib.mkdir(parents=True)
            library = lib / "libbrotlidec.so.1.1.0"
            library.write_bytes(b"reviewed decoder")
            os.symlink("libbrotlidec.so.1.1.0", lib / "libbrotlidec.so.1")
            self.assertEqual(inventory(root, library).returncode, 0, "the package's own file and link")
            stray = root / "opt" / "app" / "libbrotlidec.so.1"
            stray.parent.mkdir(parents=True)
            os.symlink(library, stray)
            self.assertEqual(inventory(root, library).returncode, 0, "another link to the reviewed library")
            other = lib / "libdecoder-other.so.1"
            other.write_bytes(b"another decoder")
            for label, make in [
                ("a link to a differently named decoder", lambda: os.symlink(other, stray)),
                ("a dangling link", lambda: os.symlink(root / "missing" / "libbrotlidec.so.1", stray)),
                ("a separate copy", lambda: stray.write_bytes(b"reviewed decoder")),
            ]:
                with self.subTest(label):
                    stray.unlink()
                    make()
                    result = inventory(root, library)
                    self.assertNotEqual(result.returncode, 0, label)
                    self.assertIn(str(stray), result.stderr)
            stray.unlink()
            self.assertNotEqual(inventory(root, root / "usr" / "lib" / "libbrotlidec.so.1").returncode, 0,
                                "the expected library must be the resolved file, not a link")

    def test_the_dockerfile_installs_and_verifies_the_fix(self) -> None:
        self.assertEqual(dockerfile_problems((ROOT / "Dockerfile").read_text()), [])

    def test_unwired_dockerfiles_fail(self) -> None:
        dockerfile = (ROOT / "Dockerfile").read_text()
        rebuild = ("FROM php@sha256:" + "0" * 64 + " AS brotli\nRUN sh /usr/src/server-brotli/build.sh\n\n"
                   "# The fixed official Apache runtime")
        for label, mutated in [
            ("no install", dockerfile.replace("    libbrotli1 \\\n", "")),
            ("install before the trust check", dockerfile.replace(
                "    && server-verify-trust system \\\n    && apt-get update", "    && apt-get update", 1)),
            ("a rebuild stage", dockerfile.replace("# The fixed official Apache runtime", rebuild, 1)),
            ("no runtime check", dockerfile.replace("RUN server-verify-trust final \\\n    && server-verify-brotli\n",
                                                    "RUN server-verify-trust final\n")),
            ("no final check", dockerfile.replace("    && rm -rf /var/lib/apt/lists/* \\\n    && server-verify-brotli\n",
                                                  "    && rm -rf /var/lib/apt/lists/*\n")),
            ("floor after clone", dockerfile.replace('RUN apk add --no-cache --upgrade "brotli-libs>=1.2.1-r0" \\\n'
                                                     '    && apk info -e "brotli-libs>=1.2.1-r0"\n\n', "")),
        ]:
            with self.subTest(label):
                self.assertNotEqual(mutated, dockerfile, label)
                self.assertNotEqual(dockerfile_problems(mutated), [], label)

if __name__ == "__main__":
    unittest.main()
