"""Check the shared producer seed, not native catalog/upgrade acceptance."""
import hashlib
import json
from pathlib import Path
import sqlite3
import unittest

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = json.loads((ROOT / "resources/platform-protocol-specs/command-key-legacy-v1-vectors.json").read_text())


class LegacyFixtureTest(unittest.TestCase):
    def test_tokens_are_the_reference_producer_utf8_sha256_tokens(self):
        for kind in ("signal", "update"):
            self.assertEqual(FIXTURE["keys"]["tokens"][kind],
                             kind + "-v1:" + hashlib.sha256(FIXTURE["keys"]["original"].encode()).hexdigest())

    def test_seed_mutations_preserve_every_other_row_and_column(self):
        for kind in ("signal", "update"):
            with self.subTest(kind=kind), sqlite3.connect(":memory:") as db:
                # Only the fixture's two statement effects are under test.
                # This minimal table is not a native database seed/catalog.
                db.executescript("""
                    CREATE TABLE workflow_commands(id TEXT PRIMARY KEY, request_id TEXT UNIQUE, payload BLOB, workflow_run_id TEXT);
                    CREATE TABLE dw_command_intents(command_id TEXT PRIMARY KEY, intent_digest TEXT);
                    CREATE TABLE workflow_history_events(id TEXT PRIMARY KEY, payload BLOB);
                """)
                db.execute("INSERT INTO workflow_commands VALUES (?,?,?,?)",
                           ("selected", FIXTURE["keys"]["tokens"][kind], b"original", "original-run"))
                db.execute("INSERT INTO workflow_commands VALUES ('other','other-key',X'00ff','other-run')")
                db.executemany("INSERT INTO dw_command_intents VALUES (?,?)", [("selected", "digest"), ("other", "kept")])
                db.execute("INSERT INTO workflow_history_events VALUES ('history',X'ff00')")
                db.commit()
                before_other = db.execute("SELECT * FROM workflow_commands WHERE id='other'").fetchall()
                before_history = db.execute("SELECT * FROM workflow_history_events").fetchall()
                with db:
                    for statement in FIXTURE["synthetic_legacy_shape"]["statements"]:
                        self.assertEqual(db.execute(statement, {"command_id": "selected", "legacy_request_id": "legacy-key"}).rowcount, 1)
                self.assertEqual(db.execute("SELECT * FROM workflow_commands WHERE id='selected'").fetchone(),
                                 ("selected", "legacy-key", b"original", "original-run"))
                self.assertEqual(db.execute("SELECT * FROM dw_command_intents").fetchall(), [("other", "kept")])
                self.assertEqual(db.execute("SELECT * FROM workflow_commands WHERE id='other'").fetchall(), before_other)
                self.assertEqual(db.execute("SELECT * FROM workflow_history_events").fetchall(), before_history)
                # A failed second statement must roll back the intent deletion.
                db.execute("INSERT INTO dw_command_intents VALUES ('selected','restored')")
                db.commit()
                with self.assertRaises(sqlite3.IntegrityError):
                    with db:
                        for statement in FIXTURE["synthetic_legacy_shape"]["statements"]:
                            db.execute(statement, {"command_id": "selected", "legacy_request_id": "other-key"})
                self.assertEqual(db.execute("SELECT intent_digest FROM dw_command_intents WHERE command_id='selected'").fetchone(), ("restored",))
                self.assertEqual(db.execute("SELECT request_id FROM workflow_commands WHERE id='selected'").fetchone(), ("legacy-key",))

    def test_reference_cases_keep_distinct_authority(self):
        self.assertEqual(FIXTURE["schema"], "durable-workflow.command-key-legacy-fixture.v1")
        self.assertEqual({c["shape"] for c in FIXTURE["ambiguity"]}, {"two-rows", "legacy-token", "modern-raw"})
        self.assertEqual(FIXTURE["ambiguity_expected"]["retryable"], False)
        self.assertEqual(FIXTURE["legacy_readback"]["expected"]["intent_verified"], False)
        self.assertEqual(FIXTURE["physical_upgrade"]["schemas"],
                         {"sqlite": [12, 13], "postgresql": [13, 14], "mariadb": [13, 14], "mysql": [14, 15]})
        self.assertEqual(FIXTURE["case_only"]["mysql_mariadb_default_ci"]["status"], 409)
        self.assertEqual(FIXTURE["case_only"]["sqlite_postgresql_binary"]["zero_write_claim"], False)


if __name__ == "__main__":
    unittest.main()
