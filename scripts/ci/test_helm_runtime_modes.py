#!/usr/bin/env python3
"""Check what the chart renders in native and PHP mode, and what it refuses (Server #946)."""

from pathlib import Path
import json
import subprocess
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]
CHART = ROOT / "k8s/helm/durable-workflow"
NATIVE = CHART / "ci/native-inline-secrets-values.yaml"
PHP = CHART / "ci/inline-secrets-values.yaml"
PHP_ONLY_COMPONENTS = {"worker", "scheduler", "migration"}


def chart_app_version() -> str:
    return yaml.safe_load((CHART / "Chart.yaml").read_text())["appVersion"]


def helm(*options: str, values: Path | None = NATIVE) -> subprocess.CompletedProcess:
    arguments = ["helm", "template", "runtime-check", str(CHART), "--namespace", "runtime-check"]
    if values is not None:
        arguments += ["--values", str(values)]
    return subprocess.run([*arguments, *options], capture_output=True, text=True)


def render(*options: str, values: Path | None = NATIVE) -> list[dict]:
    result = helm(*options, values=values)
    if result.returncode != 0:
        raise AssertionError(result.stderr)
    return [item for item in yaml.safe_load_all(result.stdout) if item]


class UniqueKeyLoader(yaml.SafeLoader):
    """A YAML loader that refuses a mapping with a repeated key.

    Kubernetes and Helm decoding keep the later of two equal keys, so a user
    value emitted after a chart value would silently replace it.
    """

    def construct_mapping(self, node, deep=False):
        seen = set()
        for key_node, _ in node.value:
            key = self.construct_object(key_node, deep=deep)
            if key in seen:
                raise AssertionError(f"duplicate key {key!r} at {key_node.start_mark}")
            seen.add(key)
        return super().construct_mapping(node, deep=deep)


def render_unique(*options: str, values: Path | None = NATIVE) -> list[dict]:
    result = helm(*options, values=values)
    if result.returncode != 0:
        raise AssertionError(result.stderr)
    return [item for item in yaml.load_all(result.stdout, Loader=UniqueKeyLoader) if item]


def component(resource: dict) -> str:
    return resource["metadata"].get("labels", {}).get("app.kubernetes.io/component", "")


def named(resources: list[dict], kind: str, suffix: str) -> dict:
    return next(
        item for item in resources
        if item["kind"] == kind and item["metadata"]["name"].endswith(suffix)
    )


class NativeModeTest(unittest.TestCase):
    def test_default_install_renders_one_native_server_and_nothing_php(self):
        # The fixture names no runtime and no image: the chart defaults.
        resources = render(
            "--set-string", "externalDatabase.host=db.example.internal",
            "--set-string", "externalDatabase.auth.username=workflow",
            "--set-string", "externalDatabase.auth.password=ci-only",
            "--set-string", "auth.authToken=ci-shared-token",
            values=None,
        )
        workloads = [item for item in resources if item["kind"] in {"Deployment", "Job", "CronJob"}]
        self.assertEqual([item["metadata"]["name"] for item in workloads], ["runtime-check-durable-workflow-server"])
        self.assertFalse({component(item) for item in resources} & PHP_ONLY_COMPONENTS)
        # One pod by default: more than one needs a shared payload claim. A
        # budget of minAvailable 1 on one pod would block every drain.
        self.assertEqual(workloads[0]["spec"]["replicas"], 1)
        self.assertFalse({"HorizontalPodAutoscaler", "PodDisruptionBudget"} & {item["kind"] for item in resources})

        server = workloads[0]
        self.assertEqual(server["metadata"]["annotations"]["workflows.durable-workflow.dev/runtime"], "native")
        pod = server["spec"]["template"]["spec"]
        container = pod["containers"][0]
        self.assertEqual(container["image"], f"docker.io/durableworkflow/server:{chart_app_version()}")
        self.assertNotIn("command", container)
        self.assertNotIn("args", container)
        self.assertEqual(container["ports"], [{"name": "http", "containerPort": 8080, "protocol": "TCP"}])
        # /api/health fails during a database outage, so liveness probes the
        # listener and only readiness checks the database.
        self.assertEqual(container["livenessProbe"]["tcpSocket"], {"port": "http"})
        self.assertNotIn("httpGet", container["livenessProbe"])
        self.assertEqual(container["readinessProbe"]["httpGet"]["path"], "/api/ready")
        # A worker poll may ask to be held for up to 60 seconds, after a
        # preStop sleep that lets routing move off the pod first (Server #608).
        self.assertEqual(pod["terminationGracePeriodSeconds"], 70)
        self.assertEqual(container["lifecycle"], {"preStop": {"exec": {"command": ["/bin/sleep", "5"]}}})
        self.assertEqual(pod["securityContext"]["runAsUser"], 1000)
        self.assertEqual(pod["securityContext"]["fsGroup"], 1000)
        self.assertIn({"name": "server-storage", "mountPath": "/app/storage"}, container["volumeMounts"])
        self.assertIn({"name": "server-storage", "emptyDir": {}}, pod["volumes"])
        self.assertEqual({item["name"] for item in container["env"]}, {"DB_USERNAME", "DB_PASSWORD"})
        # Native never calls the Kubernetes API, and its ephemeral storage
        # (including the payload emptyDir) is bounded.
        self.assertIs(pod["automountServiceAccountToken"], False)
        self.assertEqual(container["resources"]["limits"]["ephemeral-storage"], "2Gi")
        self.assertEqual(container["resources"]["requests"]["ephemeral-storage"], "128Mi")
        self.assertEqual(container["resources"]["limits"]["memory"], "512Mi")

        config = named(resources, "ConfigMap", "-config")["data"]
        for key in config:
            self.assertFalse(key.startswith(("REDIS_", "QUEUE_", "CACHE_", "APP_")), key)
        self.assertNotIn("DW_AUTH_BACKWARD_COMPATIBLE", config)
        self.assertNotIn("DW_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT", config)
        self.assertEqual(config["DW_AUTH_DRIVER"], "token")
        self.assertEqual(config["DB_CONNECTION"], "mysql")

    def test_server_options_shared_with_php_mode_apply(self):
        resources = render(values=CHART / "ci/native-ingress-and-hpa-values.yaml")
        kinds = {item["kind"] for item in resources}
        self.assertLessEqual({"Ingress", "HorizontalPodAutoscaler", "PodDisruptionBudget", "NetworkPolicy", "Service"}, kinds)
        server = named(resources, "Deployment", "-server")
        self.assertNotIn("replicas", server["spec"])
        self.assertNotIn("Job", {item["kind"] for item in resources})
        volume = next(item for item in server["spec"]["template"]["spec"]["volumes"] if item["name"] == "server-storage")
        self.assertEqual(volume, {"name": "server-storage", "persistentVolumeClaim": {"claimName": "durable-workflow-payloads"}})

    def test_schema_upgrade_hook_is_never_rendered_unchecked(self):
        """The hook needs live proof that every Server is stopped.

        helm template has no cluster, so an install render and an upgrade
        render whose predecessor cannot be seen must both refuse. The live
        refusals and the positive render are in
        scripts/helm-chart-native-kind-smoke.sh.
        """
        self.assertNotIn("Job", {item["kind"] for item in render()})

        install = helm("--set", "native.schemaUpgrade.enabled=true")
        self.assertNotEqual(install.returncode, 0, install.stdout[:200])
        self.assertIn("renders the schema-upgrade hook only during helm upgrade", install.stderr)
        self.assertIn("manual schema upgrade in the chart README", install.stderr)

        invisible = helm("--is-upgrade", "--set", "native.schemaUpgrade.enabled=true")
        self.assertNotEqual(invisible.returncode, 0, invisible.stdout[:200])
        self.assertIn("needs to see the live Deployment runtime-check-durable-workflow-server", invisible.stderr)

    def test_explicit_ephemeral_storage_and_token_settings_win(self):
        server = named(render(
            "--set-string", "server.resources.limits.ephemeral-storage=5Gi",
            "--set", "native.automountServiceAccountToken=true",
        ), "Deployment", "-server")
        pod = server["spec"]["template"]["spec"]
        self.assertIs(pod["automountServiceAccountToken"], True)
        self.assertEqual(pod["containers"][0]["resources"]["limits"]["ephemeral-storage"], "5Gi")

    def test_render_time_refusals_match_native_startup_refusals(self):
        cases = {
            "auth.authToken is required in native mode": ["--set-string", "auth.authToken="],
            "auth.principalTokens is not supported": ["--set-string", "auth.principalTokens=[]"],
            'config.auth.driver "none" is not supported': ["--set-string", "config.auth.driver=none"],
            "must exceed native.preStopSleepSeconds (5) plus DW_WORKER_POLL_TIMEOUT": [
                "--set-string", "config.extraEnv.DW_WORKER_POLL_TIMEOUT=66",
            ],
            "More than one native Server replica": ["--set", "server.replicaCount=2"],
            "native.storage.existingClaim: native keeps external payload objects": [
                "--set", "server.autoscaling.enabled=true",
            ],
            "does not run the native Server on SQLite": [
                "--set-string", "externalDatabase.connection=sqlite",
            ],
            "does not run the native Server on SQLite yet": [
                "--set-string", "config.extraEnv.DB_URL=sqlite:///app/database/database.sqlite",
            ],
            "reserved for native payload storage": [
                "--set-json", 'server.extraVolumes=[{"name":"server-storage","emptyDir":{}}]',
            ],
        }
        for message, options in cases.items():
            with self.subTest(message=message):
                result = helm(*options)
                self.assertNotEqual(result.returncode, 0, result.stdout[:200])
                self.assertIn(message, result.stderr)

        accepted = render(
            "--set-string", "config.extraEnv.DW_WORKER_POLL_TIMEOUT=70",
            "--set", "native.terminationGracePeriodSeconds=76",
        )
        server = named(accepted, "Deployment", "-server")
        self.assertEqual(server["spec"]["template"]["spec"]["terminationGracePeriodSeconds"], 76)
        without_sleep = render("--set", "native.preStopSleepSeconds=0")
        container = named(without_sleep, "Deployment", "-server")["spec"]["template"]["spec"]["containers"][0]
        self.assertNotIn("lifecycle", container)

    def test_a_shared_payload_claim_allows_more_than_one_replica(self):
        resources = render(
            "--set", "server.replicaCount=3",
            "--set-string", "native.storage.existingClaim=shared-payloads",
        )
        self.assertEqual(named(resources, "Deployment", "-server")["spec"]["replicas"], 3)
        self.assertIn("PodDisruptionBudget", {item["kind"] for item in resources})
        stopped = render("--set", "server.replicaCount=0")
        self.assertEqual(named(stopped, "Deployment", "-server")["spec"]["replicas"], 0)

    def test_existing_secret_satisfies_the_token_requirement(self):
        render("--set-string", "auth.authToken=", "--set-string", "auth.existingSecret=app-credentials")


class PhpModeTest(unittest.TestCase):
    def test_php_fixture_keeps_the_php_shape(self):
        resources = render(values=PHP)
        self.assertLessEqual(PHP_ONLY_COMPONENTS, {component(item) for item in resources})
        server = named(resources, "Deployment", "-server")
        self.assertNotIn("workflows.durable-workflow.dev/runtime", server["metadata"]["annotations"])
        self.assertIn("workflows.durable-workflow.dev/memo-payload-storage", server["metadata"]["annotations"])
        config = named(resources, "ConfigMap", "-config")["data"]
        self.assertEqual(config["APP_VERSION"], "2.11.8")
        self.assertEqual(config["QUEUE_CONNECTION"], "redis")
        self.assertNotIn("Job", {item["kind"] for item in resources if component(item) == "schema-upgrade"})

    def test_php_app_version_never_reports_the_native_chart_version(self):
        resources = render(
            "--set-string", "image.tag=php-custom",
            "--set-string", "image.memoPayloadStorage=dual-v1",
            values=PHP,
        )
        self.assertNotIn("APP_VERSION", named(resources, "ConfigMap", "-config")["data"])

    def test_php_default_replicas_and_probes_are_unchanged(self):
        resources = render(values=PHP)
        server = named(resources, "Deployment", "-server")
        self.assertEqual(server["spec"]["replicas"], 2)
        self.assertEqual(server["spec"]["template"]["spec"]["containers"][0]["livenessProbe"]["httpGet"]["path"], "/api/health")
        self.assertNotIn("terminationGracePeriodSeconds", server["spec"]["template"]["spec"])
        self.assertIn("PodDisruptionBudget", {item["kind"] for item in resources})

    def test_php_mode_refuses_sqlite(self):
        result = helm("--set-string", "externalDatabase.connection=sqlite", values=PHP)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("PHP mode does not run on SQLite", result.stderr)

    def test_php_mode_ignores_native_settings(self):
        resources = render("--set", "native.schemaUpgrade.enabled=true", values=PHP)
        self.assertFalse(any(component(item) == "schema-upgrade" for item in resources))


class ImageRuntimeGuardTest(unittest.TestCase):
    def test_native_tags_are_refused_in_php_mode(self):
        for tag in ["3.0.0", "v3.0.0", "3.0.1", "3.1.0-rc.1", "3.0", "3", "10.0.0", "latest"]:
            with self.subTest(tag=tag):
                result = helm(
                    "--set-string", f"image.tag={tag}",
                    "--set-string", "image.memoPayloadStorage=dual-v1",
                    values=PHP,
                )
                self.assertNotEqual(result.returncode, 0, result.stdout[:200])
                self.assertIn(f"image.tag {tag} selects the native 3.x Server, but runtime is php", result.stderr)
                self.assertIn("durable-workflow/server#946", result.stderr)

    def test_php_tags_are_refused_in_native_mode(self):
        for tag in ["2.11.8", "2.0.0-rc.49", "2.11", "2", "v2.11.8"]:
            with self.subTest(tag=tag):
                result = helm("--set-string", f"image.tag={tag}")
                self.assertNotEqual(result.returncode, 0, result.stdout[:200])
                self.assertIn(f"image.tag {tag} selects the PHP 2.x Server, but runtime is native", result.stderr)

    def test_each_mode_accepts_its_own_tags_and_custom_tags(self):
        for tag in ["3.0.1", "3", "latest", "helm-smoke-1"]:
            with self.subTest(runtime="native", tag=tag):
                self.assertIn(f"durableworkflow/server:{tag}", helm("--set-string", f"image.tag={tag}").stdout)
        for tag in ["2.11.8", "2.0.0-rc.49", "helm-smoke-1"]:
            with self.subTest(runtime="php", tag=tag):
                result = helm(
                    "--set-string", f"image.tag={tag}",
                    "--set-string", "image.memoPayloadStorage=dual-v1",
                    values=PHP,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(f"durableworkflow/server:{tag}", result.stdout)

    def test_unknown_runtime_is_refused(self):
        result = helm("--set-string", "runtime=laravel")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("runtime", result.stderr)


class ReservedMetadataTest(unittest.TestCase):
    """User label and annotation maps cannot shadow the chart's authority keys."""

    RESERVED = [
        "workflows.durable-workflow.dev/runtime",
        "workflows.durable-workflow.dev/memo-payload-storage",
        "workflows.durable-workflow.dev/anything",
        "helm.sh/hook",
        "helm.sh/hook-weight",
        "helm.sh/hook-delete-policy",
    ]
    MAPS = [
        "commonAnnotations", "commonLabels",
        "server.podAnnotations", "server.podLabels",
        "server.service.annotations", "server.ingress.annotations",
        "worker.podAnnotations", "worker.podLabels",
        "scheduler.podAnnotations", "scheduler.podLabels",
        "bootstrap.podAnnotations", "bootstrap.podLabels",
        "serviceAccount.annotations",
    ]

    def test_every_user_map_refuses_every_reserved_key(self):
        for values in (NATIVE, PHP):
            for path in self.MAPS:
                for key in self.RESERVED:
                    with self.subTest(values=values.name, path=path, key=key):
                        result = helm("--set-json", f"{path}={json.dumps({key: 'forged'})}", values=values)
                        self.assertNotEqual(result.returncode, 0, result.stdout[:200])
                        self.assertIn(f'{path} sets the reserved key "{key}"', result.stderr)

    def test_security_examples_are_refused(self):
        # PHP release forging the native runtime marker, which the next
        # empty-runtime upgrade and the schema-upgrade gate trust.
        forged_runtime = helm(
            "--set-json", 'commonAnnotations={"workflows.durable-workflow.dev/runtime":"native"}', values=PHP,
        )
        self.assertNotEqual(forged_runtime.returncode, 0, forged_runtime.stdout[:200])
        self.assertIn("reserved key", forged_runtime.stderr)
        # A hook type override that would run schema-upgrade after the new
        # Server starts.
        forged_hook = helm("--set-json", 'commonAnnotations={"helm.sh/hook":"post-upgrade"}')
        self.assertNotEqual(forged_hook.returncode, 0, forged_hook.stdout[:200])
        self.assertIn("reserved key", forged_hook.stderr)

    def test_ordinary_user_metadata_renders_without_duplicate_keys(self):
        user = [
            "--set-json", 'commonAnnotations={"team":"payments","argocd.argoproj.io/sync-wave":"7"}',
            "--set-json", 'commonLabels={"tier":"core","app.kubernetes.io/name":"forged","app.kubernetes.io/component":"forged"}',
            "--set-json", 'server.podAnnotations={"pod":"server","checksum/config":"forged"}',
            "--set-json", 'server.podLabels={"lane":"api","app.kubernetes.io/component":"worker"}',
            "--set-json", 'worker.podAnnotations={"pod":"worker"}',
            "--set-json", 'scheduler.podLabels={"lane":"cron"}',
            "--set-json", 'bootstrap.podAnnotations={"pod":"bootstrap"}',
        ]
        for values in (NATIVE, PHP):
            with self.subTest(values=values.name):
                resources = render_unique(*user, values=values)
                server = named(resources, "Deployment", "-server")
                annotations = server["metadata"]["annotations"]
                self.assertEqual(annotations["team"], "payments")
                # A chart key wins over the same user key.
                self.assertEqual(annotations["argocd.argoproj.io/sync-wave"], "0")
                labels = server["metadata"]["labels"]
                self.assertEqual(labels["tier"], "core")
                self.assertEqual(labels["app.kubernetes.io/name"], "durable-workflow")
                self.assertEqual(labels["app.kubernetes.io/component"], "server")
                template = server["spec"]["template"]["metadata"]
                self.assertEqual(template["labels"]["lane"], "api")
                self.assertEqual(template["labels"]["app.kubernetes.io/component"], "server")
                self.assertEqual(template["annotations"]["pod"], "server")
                self.assertNotEqual(template["annotations"]["checksum/config"], "forged")
                if values == NATIVE:
                    self.assertEqual(annotations["workflows.durable-workflow.dev/runtime"], "native")
                else:
                    self.assertNotIn("workflows.durable-workflow.dev/runtime", annotations)
                    self.assertEqual(annotations["workflows.durable-workflow.dev/memo-payload-storage"], "dual-v1")
                    migrate = named(resources, "Job", "-migrate")
                    self.assertEqual(migrate["metadata"]["annotations"]["helm.sh/hook"], "pre-install,pre-upgrade")
                    self.assertEqual(migrate["spec"]["template"]["metadata"]["annotations"], {"pod": "bootstrap"})


class UpgradeRuntimeSelectionTest(unittest.TestCase):
    """helm template cannot see live objects, so an upgrade without a runtime refuses."""

    def test_an_upgrade_without_a_visible_native_release_must_choose(self):
        native_without_runtime = helm("--is-upgrade", "--set-string", "runtime=")
        self.assertNotEqual(native_without_runtime.returncode, 0)
        self.assertIn("Set runtime=native or runtime=php explicitly", native_without_runtime.stderr)

        for runtime, values in [("native", NATIVE), ("php", PHP)]:
            with self.subTest(runtime=runtime):
                result = helm("--is-upgrade", "--set-string", f"runtime={runtime}", values=values)
                self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
