#!/usr/bin/env python3
"""Causal controls for the job-scoped SDK Composer cache (Server #1093).

The real helper runs against a fake run-composer.sh that models the two
container installs from their mounts, so these controls need no Docker or
network. The actual cold-versus-cache install identity is shown by the
profile jobs themselves, which compare every installed vendor file.
"""
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
HELPER = ROOT / "scripts/conformance/server-parity/sdk-composer-cache.sh"
PROFILES = [ROOT / "scripts/conformance/server-parity/sdk-direction-profile.sh",
            ROOT / "scripts/conformance/server-parity/range-repair-profile.sh"]
FALLBACK = ('bash scripts/ci/run-composer.sh --name "$resource-sdk" "${limits[@]}" -v "$state/sdk:/sdk" -w /sdk -- \\\n'
            '  install --no-interaction --no-progress --no-cache > "$evidence/sdk-install.txt" 2>&1\n')

# Models a locked install. A warm run (writable cache) "downloads" each dist
# into the cache; a cache-backed run (read-only cache, no network) extracts
# only what the cache holds. Every call is logged.
FAKE = r'''#!/usr/bin/env bash
set -euo pipefail
echo "$*" >> "$FAKE_LOG"
sdk= cache= ro= offline=
while (($#)) && [[ $1 != -- ]]; do
  case "$1" in
    -v) shift; src=${1%%:*}; rest=${1#*:}; dst=${rest%%:*}
        case "$dst" in /sdk) sdk=$src ;; /composer-cache) cache=$src; [[ $rest == *:ro ]] && ro=1 ;; esac ;;
    --network) shift; [[ $1 == none ]] && offline=1 ;;
  esac
  shift
done
if [[ -z $ro ]]; then
  [[ -z ${FAKE_WARM_FAIL:-} ]] || { echo "could not be downloaded (HTTP/2 504 )" >&2; exit 100; }
  mkdir -p "$cache/files/acme/lib" "$sdk/vendor/acme/lib" "$sdk/vendor/composer"
  printf 'dist-lib\n' > "$cache/files/acme/lib/1.zip"
  printf 'code-lib\n' > "$sdk/vendor/acme/lib/Lib.php"
  printf '{"packages":["acme/lib"]}\n' > "$sdk/vendor/composer/installed.json"
  exit 0
fi
[[ -n $offline ]] || { echo "cache-backed install must be offline" >&2; exit 9; }
[[ -z ${FAKE_INSTALL_FAIL:-} ]] || exit 1
test -f "$cache/files/acme/lib/1.zip" || exit 1
mkdir -p "$sdk/vendor/acme/lib" "$sdk/vendor/composer"
sed 's/dist-/code-/' "$cache/files/acme/lib/1.zip" > "$sdk/vendor/acme/lib/Lib.php"
printf '{"packages":["acme/lib"]}\n' > "$sdk/vendor/composer/installed.json"
[[ -z ${FAKE_DRIFT:-} ]] || printf 'extra\n' > "$sdk/vendor/acme/lib/Extra.php"
'''


class SdkComposerCacheTest(unittest.TestCase):
    def setUp(self):
        self.work = Path(tempfile.mkdtemp(prefix="sdk-composer-cache-"))
        (self.work / "scripts/ci").mkdir(parents=True)
        (self.work / "scripts/conformance/server-parity").mkdir(parents=True)
        shutil.copy(HELPER, self.work / "scripts/conformance/server-parity/sdk-composer-cache.sh")
        (self.work / "scripts/ci/run-composer.sh").write_text(FAKE)
        self.log = self.work / "calls.log"
        self.log.touch()
        self.lock = self.lock_dir("a")

    def tearDown(self):
        for path in self.work.rglob("*"):
            if not path.is_symlink():
                path.chmod(path.stat().st_mode | stat.S_IWUSR)
        shutil.rmtree(self.work)

    def lock_dir(self, name, lock="lock-a\n"):
        directory = self.work / f"state-{name}/sdk"
        directory.mkdir(parents=True)
        (directory / "composer.json").write_text('{"require":{"acme/lib":"1"}}\n')
        (directory / "composer.lock").write_text(lock)
        return directory

    def run_helper(self, *args, **env):
        return subprocess.run(["bash", "scripts/conformance/server-parity/sdk-composer-cache.sh", *map(str, args)],
                              cwd=self.work, env={"PATH": os.environ["PATH"], "FAKE_LOG": str(self.log), **env},
                              capture_output=True, text=True, timeout=60)

    def calls(self):
        return self.log.read_text().splitlines()

    def roots(self):
        return sorted(p for p in self.work.glob("parity-composer-cache-*") if not p.name.endswith(".lock"))

    def test_one_warm_serves_every_offline_read_only_install(self):
        for name in ("a", "b"):
            directory = self.lock if name == "a" else self.lock_dir("b")
            result = self.run_helper("install", directory, "--user=1000:1000")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual((directory / "vendor/acme/lib/Lib.php").read_text(), "code-lib\n")
        warm = [c for c in self.calls() if ":/composer-cache:ro" not in c]
        offline = [c for c in self.calls() if ":/composer-cache:ro" in c]
        self.assertEqual(len(warm), 1, "the same lock is downloaded once per job")
        self.assertEqual(len(offline), 2)
        self.assertTrue(all("--network none" in c and "--rm" in c for c in offline))
        root, = self.roots()
        for path in root.rglob("*"):
            self.assertFalse(path.stat().st_mode & 0o222, f"{path} must be read-only")

    def test_a_tampered_cache_is_never_used(self):
        self.assertEqual(self.run_helper("warm", self.lock).returncode, 0)
        root, = self.roots()
        dist = root / "cache/files/acme/lib/1.zip"
        dist.chmod(0o644)
        dist.write_text("dist-evil\n")
        before = len(self.calls())
        result = self.run_helper("install", self.lock)
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertEqual(len(self.calls()), before, "no install runs from an unverified cache")
        self.assertFalse((self.lock / "vendor").exists())

    def test_a_cache_for_another_lock_is_not_substituted(self):
        self.assertEqual(self.run_helper("warm", self.lock).returncode, 0)
        other = self.lock_dir("other", "lock-other\n")
        source, = self.roots()
        # Put lock A's verified cache where lock B's would live.
        self.run_helper("warm", other, FAKE_WARM_FAIL="1")
        target = source.with_name(source.name.replace(*self._key_pair(other)))
        if target.exists():
            shutil.rmtree(target)
        shutil.copytree(source, target, symlinks=True)
        result = self.run_helper("install", other)
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertFalse((other / "vendor").exists())

    def _key_pair(self, other):
        import hashlib
        digest = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
        return (digest(self.lock / "composer.lock"), digest(other / "composer.lock"))

    def test_a_symlinked_cache_root_is_refused(self):
        real = self.work / "elsewhere"
        self.assertEqual(self.run_helper("warm", self.lock).returncode, 0)
        root, = self.roots()
        root.rename(real)
        root.symlink_to(real)
        self.assertEqual(self.run_helper("install", self.lock).returncode, 3)

    def test_a_failed_warm_leaves_no_cache_and_falls_back(self):
        result = self.run_helper("install", self.lock, FAKE_WARM_FAIL="1")
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertEqual(self.roots(), [])
        self.assertEqual(list(self.work.glob("parity-composer-cache-*.tmp.*")), [])

    def test_a_failed_cache_backed_install_falls_back(self):
        self.assertEqual(self.run_helper("install", self.lock, FAKE_INSTALL_FAIL="1").returncode, 3)

    def test_a_drifting_install_is_refused_not_fallen_back(self):
        result = self.run_helper("install", self.lock, FAKE_DRIFT="1")
        self.assertEqual(result.returncode, 4, result.stderr)

    def test_profiles_keep_the_unchanged_authenticated_install_as_fallback(self):
        for profile in PROFILES:
            text = profile.read_text()
            self.assertIn('sdk-composer-cache.sh install "$state/sdk" "${limits[@]}"', text, profile.name)
            self.assertIn("  3)\n    rm -rf \"$state/sdk/vendor\"\n" + FALLBACK + "    ;;\n", text, profile.name)
            self.assertIn('  *) echo "Refusing the cache-backed SDK install', text, profile.name)
            # The existing pinned-lock check still follows every install.
            self.assertGreater(text.index("sha256sum -c > \"$evidence/sdk-lock.txt\""), text.index(FALLBACK))


if __name__ == "__main__":
    unittest.main()
