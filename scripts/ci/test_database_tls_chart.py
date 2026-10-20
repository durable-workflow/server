#!/usr/bin/env python3
"""Check CA delivery to every role, including the pre-install bootstrap hook."""

from pathlib import Path
import subprocess
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]
CHART = ROOT / "k8s/helm/durable-workflow"
CA_PATH = "/etc/durable-workflow/database-tls/ca.pem"


def render(*options: str) -> list[dict]:
    output = subprocess.run(
        ["helm", "template", "tls-check", str(CHART), "--namespace", "tls-check",
         "--values", str(CHART / "ci/database-tls-values.yaml"), *options],
        check=True, capture_output=True, text=True,
    )
    return [item for item in yaml.safe_load_all(output.stdout) if item]


def pod_spec(resource: dict) -> dict | None:
    kind = resource["kind"]
    if kind in {"Deployment", "Job"}:
        return resource["spec"]["template"]["spec"]
    if kind == "CronJob":
        return resource["spec"]["jobTemplate"]["spec"]["template"]["spec"]
    return None


class DatabaseTlsChartTest(unittest.TestCase):
    def test_ca_reaches_all_four_roles_in_hook_and_regular_bootstrap(self):
        for hooks in ["true", "false"]:
            with self.subTest(hooks=hooks):
                resources = render("--set", f"bootstrap.useHelmHooks={hooks}")
                config = next(item for item in resources if item["kind"] == "ConfigMap")["data"]
                self.assertEqual(config["DB_SSLMODE"], "verify-full")
                self.assertEqual(config["DB_SSLROOTCERT"], CA_PATH)
                roles = set()
                for resource in resources:
                    pod = pod_spec(resource)
                    if pod is None:
                        continue
                    container = pod["containers"][0]
                    roles.add(container["name"])
                    volume = next(item for item in pod["volumes"] if item["name"] == "database-tls")
                    self.assertEqual(volume["secret"], {
                        "secretName": "database-ca", "items": [{"key": "trusted.pem", "path": "ca.pem"}],
                    })
                    mount = next(item for item in container["volumeMounts"] if item["name"] == "database-tls")
                    self.assertTrue(mount["readOnly"])
                    self.assertEqual(mount["mountPath"] + "/ca.pem", CA_PATH)
                    if container["name"] == "migrate" and hooks == "true":
                        environment = {item["name"]: item.get("value") for item in container["env"]}
                        self.assertEqual(environment["DB_SSLMODE"], "verify-full")
                        self.assertEqual(environment["DB_SSLROOTCERT"], CA_PATH)
                self.assertEqual(roles, {"server", "worker", "migrate", "scheduler"})

    def test_existing_volumes_and_mounts_survive_with_or_without_ca(self):
        for ca in ["database-ca", ""]:
            resources = render(
                "--set", f"externalDatabase.tls.existingSecret={ca}",
                "--set-json", 'server.extraVolumes=[{"name":"application-data","emptyDir":{}}]',
                "--set", "server.extraVolumeMounts[0].name=application-data",
                "--set", "server.extraVolumeMounts[0].mountPath=/application-data",
            )
            server = next(item for item in resources if item["kind"] == "Deployment" and item["metadata"]["name"].endswith("-server"))
            pod = pod_spec(server)
            self.assertIsNotNone(pod)
            self.assertEqual({item["name"] for item in pod["volumes"]}, {"application-data"} | ({"database-tls"} if ca else set()))
            self.assertEqual({item["name"] for item in pod["containers"][0]["volumeMounts"]}, {item["name"] for item in pod["volumes"]})
            if not ca:
                config = next(item for item in resources if item["kind"] == "ConfigMap")["data"]
                self.assertNotIn("DB_SSLROOTCERT", config)

    def test_ca_volume_name_and_mount_path_cannot_be_shadowed(self):
        for setting in [
            "server.extraVolumes[0].name=database-tls",
            "worker.extraVolumeMounts[0].mountPath=/etc/durable-workflow/database-tls",
        ]:
            with self.subTest(setting=setting):
                result = subprocess.run(
                    ["helm", "template", "tls-check", str(CHART), "--values",
                     str(CHART / "ci/database-tls-values.yaml"), "--set", setting],
                    capture_output=True, text=True,
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("reserved for database TLS", result.stderr)


if __name__ == "__main__":
    unittest.main()
