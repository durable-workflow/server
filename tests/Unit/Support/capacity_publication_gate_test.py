import importlib.util
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
MODULE_PATH = ROOT / "scripts" / "ci" / "qualify_capacity_schema_publication.py"
SPEC = importlib.util.spec_from_file_location("capacity_publication_gate", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
GATE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GATE)


class CapacityPublicationGateTest(unittest.TestCase):
    BASE_REF = "a" * 40

    def test_initial_publication_defers_live_route_qualification(self) -> None:
        calls = []

        self.assertFalse(
            GATE.should_verify_publication(
                {
                    "GITHUB_EVENT_NAME": "pull_request",
                    "PUBLICATION_BASE_REF": self.BASE_REF,
                },
                lambda base_ref: calls.append(base_ref) or False,
            )
        )
        self.assertEqual([self.BASE_REF], calls)

    def test_later_change_requires_live_route_qualification(self) -> None:
        self.assertTrue(
            GATE.should_verify_publication(
                {
                    "GITHUB_EVENT_NAME": "push",
                    "PUBLICATION_BASE_REF": self.BASE_REF,
                },
                lambda _base_ref: True,
            )
        )

    def test_non_comparable_events_fail_closed_to_live_qualification(self) -> None:
        self.assertTrue(
            GATE.should_verify_publication(
                {"GITHUB_EVENT_NAME": "workflow_dispatch"},
                lambda _base_ref: self.fail("workflow dispatch must not inspect a base ref"),
            )
        )
        self.assertTrue(
            GATE.should_verify_publication(
                {
                    "GITHUB_EVENT_NAME": "push",
                    "PUBLICATION_BASE_REF": "0" * 40,
                },
                lambda _base_ref: self.fail("a zero push ref must not be inspected"),
            )
        )

    def test_dynamic_ref_shapes_are_rejected(self) -> None:
        for base_ref in (
            "",
            "HEAD",
            "A" * 40,
            "a" * 39,
            "a" * 40 + f":{GATE.PUBLICATION_PATH}",
        ):
            with self.subTest(base_ref=base_ref):
                with self.assertRaises(GATE.PublicationGateError):
                    GATE.should_verify_publication(
                        {
                            "GITHUB_EVENT_NAME": "pull_request",
                            "PUBLICATION_BASE_REF": base_ref,
                        }
                    )

    def test_gate_uses_a_fixed_repository_publication_path(self) -> None:
        self.assertEqual(
            "benchmarks/capacity/v1/schema-publication.json",
            GATE.PUBLICATION_PATH,
        )


if __name__ == "__main__":
    unittest.main()
