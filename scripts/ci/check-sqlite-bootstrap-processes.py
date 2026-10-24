#!/usr/bin/env python3
"""Run inside the selected PHP image, without network or mounted databases."""

import os
import pwd
import sqlite3
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path


class SqliteBootstrapProcesses(unittest.TestCase):
    def setUp(self):
        self.assertEqual(os.geteuid(), 0, "the image control starts as root")
        self.runtime = pwd.getpwnam("www-data")
        self.temporary = tempfile.TemporaryDirectory(prefix="dw-sqlite-bootstrap-")
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        os.chown(self.directory, self.runtime.pw_uid, self.runtime.pw_gid)
        self.directory.chmod(0o775)
        self.database = self.directory / "custom" / "workflow.sqlite"

    def helper(self, database=None, *, user=None, overrides=None, mask=None):
        environment = os.environ.copy()
        environment.update(
            DB_CONNECTION="sqlite",
            DB_DATABASE=str(database or self.database),
            APACHE_RUN_USER="www-data",
            APACHE_RUN_GROUP="www-data",
        )
        for name, value in (overrides or {}).items():
            if value is None:
                environment.pop(name, None)
            else:
                environment[name] = value
        command = ["/bin/sh", "/usr/local/bin/server-ensure-sqlite"]
        if user:
            command = ["runuser", "--user", user, "--", *command]
        return subprocess.run(
            command,
            env=environment,
            capture_output=True,
            text=True,
            timeout=8,
            preexec_fn=(lambda: os.umask(mask)) if mask is not None else None,
        )

    def assert_runtime_write(self, path, uid=None, gid=None):
        command = [
            "setpriv", "--reuid", str(uid or self.runtime.pw_uid),
            "--regid", str(gid or self.runtime.pw_gid), "--clear-groups", "--",
            "php", "-r",
            '$db = new PDO("sqlite:".$argv[1]); '
            '$db->exec("CREATE TABLE IF NOT EXISTS bootstrap_control (value INTEGER)"); '
            '$db->exec("INSERT INTO bootstrap_control VALUES (1)"); '
            'echo $db->query("SELECT COUNT(*) FROM bootstrap_control")->fetchColumn();',
            str(path),
        ]
        result = subprocess.run(command, capture_output=True, text=True, timeout=8)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertGreater(int(result.stdout), 0)

    @staticmethod
    def fingerprint(path):
        metadata = path.stat()
        return path.read_bytes(), metadata.st_uid, metadata.st_gid, stat.S_IMODE(metadata.st_mode)

    def test_root_creates_nested_database_for_the_http_identity(self):
        result = self.helper(overrides={"APACHE_RUN_USER": None, "APACHE_RUN_GROUP": None})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.database.stat().st_uid, self.runtime.pw_uid)
        self.assertEqual(self.database.parent.stat().st_uid, self.runtime.pw_uid)
        self.assert_runtime_write(self.database)

    def test_nonroot_creation_and_repeated_calls_preserve_the_database(self):
        result = self.helper(user="www-data")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assert_runtime_write(self.database)
        before = self.fingerprint(self.database)
        self.assertEqual(self.helper().returncode, 0)
        self.assertEqual(self.fingerprint(self.database), before)

    def test_writable_existing_operator_file_keeps_bytes_owner_and_mode(self):
        self.database.parent.mkdir()
        self.database.parent.chmod(0o775)
        os.chown(self.database.parent, self.runtime.pw_uid, self.runtime.pw_gid)
        with sqlite3.connect(self.database) as connection:
            connection.execute("CREATE TABLE retained (value TEXT)")
            connection.execute("INSERT INTO retained VALUES ('operator data')")
        os.chown(self.database, 0, self.runtime.pw_gid)
        self.database.chmod(0o660)
        before = self.fingerprint(self.database)
        result = self.helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.fingerprint(self.database), before)
        self.assert_runtime_write(self.database)

    def test_incompatible_existing_file_refuses_without_repairing_permissions(self):
        self.database.parent.mkdir()
        self.database.write_bytes(b"operator-owned bytes")
        self.database.chmod(0o600)
        before = self.fingerprint(self.database)
        result = self.helper()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SQLite", result.stderr)
        self.assertIn(str(self.runtime.pw_uid), result.stderr)
        self.assertEqual(self.fingerprint(self.database), before)

    def test_unwritable_parent_refuses_without_changing_it(self):
        self.directory.chmod(0o555)
        before = self.directory.stat()
        result = self.helper(self.directory / "unwritable.sqlite")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SQLite", result.stderr)
        self.assertEqual(stat.S_IMODE(self.directory.stat().st_mode), 0o555)
        self.assertEqual(self.directory.stat().st_uid, before.st_uid)
        self.assertFalse((self.directory / "unwritable.sqlite").exists())

    def test_directory_is_not_a_database_file(self):
        result = self.helper(self.directory)
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(self.directory.is_dir())

    def test_existing_symlink_and_target_are_unchanged(self):
        target = self.directory / "existing.sqlite"
        target.touch()
        os.chown(target, self.runtime.pw_uid, self.runtime.pw_gid)
        link = self.directory / "linked.sqlite"
        link.symlink_to(target)
        before = self.fingerprint(target)
        self.assertEqual(self.helper(link).returncode, 0)
        self.assertTrue(link.is_symlink())
        self.assertEqual(self.fingerprint(target), before)

    def test_symlink_uses_the_writable_target_parent_for_sqlite_journals(self):
        target = self.directory / "target.sqlite"
        target.touch()
        os.chown(target, self.runtime.pw_uid, self.runtime.pw_gid)
        links = self.directory / "readonly-links"
        links.mkdir()
        link = links / "database.sqlite"
        link.symlink_to(target)
        links.chmod(0o555)
        before = self.fingerprint(target)
        result = self.helper(link)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(link.is_symlink())
        self.assertEqual(self.fingerprint(target), before)
        self.assert_runtime_write(link)
        self.directory.chmod(0o555)
        before = self.fingerprint(target)
        self.assertNotEqual(self.helper(link).returncode, 0)
        self.assertEqual(self.fingerprint(target), before)

    def test_configured_named_identity_creates_its_own_file(self):
        user = pwd.getpwnam("nobody")
        os.chown(self.directory, user.pw_uid, user.pw_gid)
        result = self.helper(overrides={"APACHE_RUN_USER": "nobody", "APACHE_RUN_GROUP": str(user.pw_gid)})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.database.stat().st_uid, user.pw_uid)
        self.assertEqual(self.database.stat().st_gid, user.pw_gid)
        self.assert_runtime_write(self.database, user.pw_uid, user.pw_gid)

    def test_numeric_apache_identity_does_not_need_a_passwd_entry(self):
        identity = 64001
        with self.assertRaises(KeyError):
            pwd.getpwuid(identity)
        os.chown(self.directory, identity, identity)
        result = self.helper(overrides={"APACHE_RUN_USER": f"#{identity}", "APACHE_RUN_GROUP": f"#{identity}"})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.database.stat().st_uid, identity)
        self.assertEqual(self.database.stat().st_gid, identity)
        self.assert_runtime_write(self.database, identity, identity)

    def test_root_http_identity_is_refused_before_creation(self):
        result = self.helper(overrides={"APACHE_RUN_USER": "root"})
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.database.exists())

    def test_new_file_respects_the_callers_private_umask(self):
        result = self.helper(mask=0o077)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(stat.S_IMODE(self.database.stat().st_mode), 0o600)
        self.assert_runtime_write(self.database)

    def test_concurrent_startup_never_truncates_an_existing_database(self):
        environment = os.environ.copy()
        environment.update(DB_CONNECTION="sqlite", DB_DATABASE=str(self.database))
        for created in (False, True):
            before = self.fingerprint(self.database) if created else None
            processes = [subprocess.Popen(
                ["/bin/sh", "/usr/local/bin/server-ensure-sqlite"],
                env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            ) for _ in range(8)]
            for process in processes:
                _, error = process.communicate(timeout=8)
                self.assertEqual(process.returncode, 0, error.decode())
            self.assertEqual(self.database.stat().st_uid, self.runtime.pw_uid)
            if created:
                self.assertEqual(self.fingerprint(self.database), before)
            else:
                self.assert_runtime_write(self.database)

    def test_root_wal_opener_preserves_runtime_writes_and_sqlite_locking(self):
        self.assertEqual(self.helper().returncode, 0)
        with sqlite3.connect(self.database) as connection:
            self.assertEqual(connection.execute("PRAGMA journal_mode=WAL").fetchone()[0], "wal")
            connection.execute("CREATE TABLE bootstrap_control (value INTEGER)")
            connection.execute("INSERT INTO bootstrap_control VALUES (7)")
            connection.commit()
            self.assert_runtime_write(self.database)
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM bootstrap_control").fetchone()[0], 2)
            connection.execute("BEGIN IMMEDIATE")
            self.assertEqual(self.helper().returncode, 0)
            result = subprocess.run([
                "setpriv", "--reuid", str(self.runtime.pw_uid),
                "--regid", str(self.runtime.pw_gid), "--clear-groups", "--",
                "php", "-r", '$db = new PDO("sqlite:".$argv[1]); '
                '$db->exec("PRAGMA busy_timeout=25"); '
                '$db->exec("INSERT INTO bootstrap_control VALUES (2)");',
                str(self.database),
            ], capture_output=True, text=True, timeout=8)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("database is locked", result.stderr)
            connection.rollback()
            self.assert_runtime_write(self.database)
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM bootstrap_control").fetchone()[0], 3)

    def test_non_sqlite_and_memory_do_not_create_a_database(self):
        self.assertEqual(self.helper(overrides={"DB_CONNECTION": "pgsql"}).returncode, 0)
        self.assertEqual(self.helper(overrides={"DB_DATABASE": ":memory:"}).returncode, 0)
        self.assertFalse(self.database.exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)
