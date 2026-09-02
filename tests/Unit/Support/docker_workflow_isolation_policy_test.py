from __future__ import annotations

import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
POLICY = ROOT / "scripts" / "ci" / "check-docker-workflow-isolation.py"


class DockerWorkflowIsolationPolicyTest(unittest.TestCase):
    def run_policy(
        self, workflow: str, scripts: dict[str, str] | None = None
    ) -> subprocess.CompletedProcess[str]:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            workflow_path = root / ".github" / "workflows" / "docker.yml"
            workflow_path.parent.mkdir(parents=True)
            workflow_path.write_text(textwrap.dedent(workflow), encoding="utf-8")

            for relative_path, source in (scripts or {}).items():
                script_path = root / relative_path
                script_path.parent.mkdir(parents=True, exist_ok=True)
                script_path.write_text(textwrap.dedent(source), encoding="utf-8")

            return subprocess.run(
                ["python3", str(POLICY), "--root", str(root)],
                check=False,
                capture_output=True,
                text=True,
            )

    def test_accepts_job_scoped_compose_and_container_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  NETWORK: network-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  VOLUME: volume-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  PORT: "0"
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - run: docker network create "$NETWORK"
                  - run: docker volume create "$VOLUME"
                  - run: docker run -d --name "$CONTAINER" image
                  - run: docker run --rm --publish 127.0.0.1::8080 image
                  - run: docker run --rm --publish "127.0.0.1:$PORT:8080" image
                  - if: always()
                    run: |
                      docker rm -f "$CONTAINER" || true
                      docker network rm "$NETWORK" || true
                      docker volume rm "$VOLUME" || true
                      docker compose -p "$PROJECT" down -v || true
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_unscoped_compose_commands(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker compose up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("docker compose must set -p/--project-name", result.stderr)

    def test_rejects_compose_without_explicit_dynamic_port_policy(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Compose jobs must explicitly use Docker-assigned or disabled host ports",
            result.stderr,
        )

    def test_rejects_literal_compose_project_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p shared up -d
                  - if: always()
                    run: docker compose -p "$SCOPE" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker compose project must use a scoped variable",
            result.stderr,
        )

    def test_rejects_fixed_host_container_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker run -d --name shared-helper image
                  - if: always()
                    run: docker rm -f shared-helper || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("docker run --name must use a scoped variable", result.stderr)

    def test_referenced_docker_scripts_must_include_job_identity(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: scripts/smoke.sh
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """,
            {
                "scripts/smoke.sh": """
                    #!/usr/bin/env bash
                    project="smoke-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
                    docker compose -p "$project" up -d
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("Docker resource scope is missing GITHUB_JOB", result.stderr)

    def test_remote_controller_stops_transitive_docker_resource_attribution(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              soak:
                steps:
                  - run: scripts/controller.sh
            """,
            {
                "scripts/controller.sh": """
                    #!/usr/bin/env bash
                    # docker-workflow-isolation: transitive-scripts-run-remotely
                    ssh perf@example.test 'scripts/soak.sh'
                """,
                "scripts/soak.sh": """
                    #!/usr/bin/env bash
                    docker compose -p fixed up -d
                """,
            },
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_fixed_published_host_ports(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: |
                      cat > docker-compose.override.yml <<'EOF'
                      services:
                        server:
                          ports:
                            - "18080:8080"
                      EOF
                      docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Compose published host ports must be dynamic",
            result.stderr,
        )

    def test_rejects_fixed_docker_publish_without_container_name(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker run --rm --publish 127.0.0.1:18080:8080 image
                  - if: always()
                    run: true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("Docker published host ports must be dynamic", result.stderr)

    def test_rejects_fixed_docker_network_create_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker network create shared-network
                  - if: always()
                    run: docker network rm shared-network || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker network create name must use a scoped variable",
            result.stderr,
        )

    def test_rejects_fixed_docker_volume_create_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker volume create shared-volume
                  - if: always()
                    run: docker volume rm shared-volume || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker volume create name must use a scoped variable",
            result.stderr,
        )

    def test_rejects_product_owner_variable_backed_counterexample(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  QUALIFICATION_SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      NETWORK=shared-network
                      PORT=18080
                      SCOPE="$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT-$GITHUB_JOB"
                      docker network create "$NETWORK"
                      docker run --rm --publish "127.0.0.1:$PORT:8080" image
                  - if: always()
                    run: true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker network create name must use a scoped variable",
            result.stderr,
        )
        self.assertIn("Docker published host ports must be dynamic", result.stderr)

    def test_rejects_command_effective_fixed_port_override(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  PORT: "0"
                steps:
                  - run: |
                      PORT=18080
                      docker run --rm --publish "127.0.0.1:$PORT:8080" image
                      PORT=0
                  - if: always()
                    run: true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("Docker published host ports must be dynamic", result.stderr)

    def test_rejects_generated_compose_long_syntax_fixed_port(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: |
                      cat > docker-compose.override.yml <<'EOF'
                      services:
                        server:
                          ports:
                            - target: 8080
                              published: 18080
                      EOF
                      docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Compose published host ports must be dynamic",
            result.stderr,
        )

    def test_rejects_fixed_docker_resources_in_bash_invoked_helper(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: bash scripts/docker-helper.sh
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper image
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker run --name must use a scoped variable",
            result.stderr,
        )

    def test_scans_literal_helpers_behind_static_launchers(self) -> None:
        for command in (
            "command ./scripts/docker-helper.sh",
            "exec ./scripts/docker-helper.sh",
            "env TRACE=1 ./scripts/docker-helper.sh",
            "nohup bash scripts/docker-helper.sh",
            "time bash scripts/docker-helper.sh",
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: {command}
                          - if: always()
                            run: true
                    """,
                    {
                        "scripts/docker-helper.sh": """
                            #!/usr/bin/env bash
                            docker run -d --name shared-helper image
                        """
                    },
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "docker run --name must use a scoped variable",
                    result.stderr,
                )
                self.assertNotIn(
                    "helper invocation must use a literal repository-relative path",
                    result.stderr,
                )

    def test_rejects_literal_helper_behind_unclassified_launcher(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: timeout 30 ./scripts/docker-helper.sh
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper image
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_rejects_fixed_docker_resources_in_sourced_helper(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER=./scripts/docker-helper.sh
                      set -e; source "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_rejects_direct_variable_invoked_docker_helper(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER=./scripts/docker-helper.sh
                      "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_rejects_command_prefixed_variable_invoked_docker_helper(self) -> None:
        for command in (
            'command "$HELPER"',
            'command -- "$HELPER"',
            'if ! command "$HELPER"; then true; fi',
            'TRACE=1 command "$HELPER"',
            'exec "$HELPER"',
            'env TRACE=1 "$HELPER"',
            'nohup "$HELPER"',
            'time "$HELPER"',
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              HELPER=./scripts/docker-helper.sh
                              {command}
                          - if: always()
                            run: true
                    """,
                    {
                        "scripts/docker-helper.sh": """
                            #!/usr/bin/env bash
                            docker run -d --name shared-helper \
                              --publish 127.0.0.1:18080:8080 image
                            docker network create shared-network
                            docker volume create shared-volume
                        """
                    },
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "helper invocation must use a literal repository-relative path",
                    result.stderr,
                )

    def test_rejects_variable_invoked_docker_helper_behind_any_launcher(self) -> None:
        for command in (
            'timeout 30 "$HELPER"',
            'nice "$HELPER"',
            'stdbuf -oL "$HELPER"',
            'sudo "$HELPER"',
            'unlisted-launcher --opaque "$HELPER"',
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              HELPER=./scripts/docker-helper.sh
                              {command}
                          - if: always()
                            run: true
                    """,
                    {
                        "scripts/docker-helper.sh": """
                            #!/usr/bin/env bash
                            docker run -d --name shared-helper \
                              --publish 127.0.0.1:18080:8080 image
                            docker network create shared-network
                            docker volume create shared-volume
                        """
                    },
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "helper invocation must use a literal repository-relative path",
                    result.stderr,
                )

    def test_rejects_assembled_variable_helper_behind_unknown_launcher(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER_ROOT=.
                      HELPER="$HELPER_ROOT/scripts/docker-helper.sh"
                      unlisted-launcher --opaque "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_rejects_fragment_assembled_helper_argument(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER_DIR=./scripts
                      HELPER_NAME=docker-helper.sh
                      HELPER="$HELPER_DIR/$HELPER_NAME"
                      unlisted-launcher --opaque "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "unclassified command unlisted-launcher cannot safely consume",
            result.stderr,
        )

    def test_rejects_command_substitution_argument_to_unclassified_command(
        self,
    ) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      unlisted-launcher --opaque "$(printf %s dynamic-target)"
                      docker run -d --name "$CONTAINER" image
                  - if: always()
                    run: docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "unclassified command unlisted-launcher cannot safely consume",
            result.stderr,
        )

    def test_rejects_all_dynamic_argument_forms_to_unclassified_commands(
        self,
    ) -> None:
        for command in (
            'unlisted-launcher --opaque "$TARGET"',
            'unlisted-launcher --opaque "${TARGETS[0]}"',
            "unlisted-launcher --opaque <(printf %s dynamic-target)",
            "unlisted-launcher --opaque `printf %s dynamic-target`",
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          CONTAINER: helper-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              {command}
                              docker run -d --name "$CONTAINER" image
                          - if: always()
                            run: docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
                    """
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "unclassified command unlisted-launcher cannot safely consume",
                    result.stderr,
                )

    def test_accepts_worker_openapi_evolution_with_bounded_base_refs(self) -> None:
        result = self.run_policy(
            """
            name: Candidate
            on: pull_request
            jobs:
              structural:
                steps:
                  - env:
                      OPENAPI_BASE_REF: ${{ github.event.pull_request.base.sha }}
                    run: php scripts/ci/check-worker-openapi-evolution.php "$OPENAPI_BASE_REF"
                  - env:
                      CORPUS_BASE_REF: ${{ github.event.pull_request.base.sha }}
                    run: php scripts/ci/check-worker-openapi-evolution.php "$CORPUS_BASE_REF"
            """,
            {
                "scripts/ci/check-worker-openapi-evolution.php": """
                    <?php
                """
            },
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_unapproved_dynamic_php_script_invocations(self) -> None:
        for command, scripts in (
            (
                'php scripts/ci/unclassified.php "$OPENAPI_BASE_REF"',
                {"scripts/ci/unclassified.php": "<?php"},
            ),
            (
                'php scripts/ci/check-worker-openapi-evolution.php "$OTHER_REF"',
                {"scripts/ci/check-worker-openapi-evolution.php": "<?php"},
            ),
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Candidate
                    on: pull_request
                    jobs:
                      structural:
                        steps:
                          - run: {command}
                    """,
                    scripts,
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "unclassified command php cannot safely consume",
                    result.stderr,
                )

    def test_rejects_dynamic_helper_as_docker_run_command(
        self,
    ) -> None:
        for command in (
            'docker run example/image "$HELPER"',
            'docker run --entrypoint "$HELPER" example/image',
            'docker run --entrypoint="$HELPER" example/image',
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              HELPER_DIR=./scripts
                              HELPER_NAME=docker-helper.sh
                              HELPER="$HELPER_DIR/$HELPER_NAME"
                              {command}
                          - if: always()
                            run: true
                    """,
                    {
                        "scripts/docker-helper.sh": """
                            #!/usr/bin/env bash
                            docker run -d --name shared-helper \
                              --publish 127.0.0.1:18080:8080 image
                            docker network create shared-network
                            docker volume create shared-volume
                        """
                    },
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "unclassified command docker cannot safely consume",
                    result.stderr,
                )

    def test_rejects_dynamic_helm_post_renderer(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER_DIR=./scripts
                      HELPER_NAME=docker-helper.sh
                      HELPER="$HELPER_DIR/$HELPER_NAME"
                      helm template chart --post-renderer "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "unclassified command helm cannot safely consume",
            result.stderr,
        )

    def test_accepts_modeled_helm_release_data_arguments(self) -> None:
        result = self.run_policy(
            """
            name: Helm release
            on: workflow_dispatch
            jobs:
              publish:
                steps:
                  - run: |
                      printf '%s' "$TOKEN" |
                        helm registry login ghcr.io \
                          --username "$ACTOR" --password-stdin
                      helm push "$CHART_PACKAGE" "$OCI_PARENT"
                      helm registry logout ghcr.io
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_fixed_docker_resources_behind_command_launcher(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      command docker run -d --name shared-helper \
                        --publish 127.0.0.1:18080:8080 image
                      command docker network create shared-network
                      command docker volume create shared-volume
                  - if: always()
                    run: true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker run --name must use a scoped variable",
            result.stderr,
        )
        self.assertIn(
            "Docker published host ports must be dynamic",
            result.stderr,
        )
        self.assertIn(
            "docker network create name must use a scoped variable",
            result.stderr,
        )
        self.assertIn(
            "docker volume create name must use a scoped variable",
            result.stderr,
        )

    def test_accepts_scoped_docker_resources_behind_command_launcher(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      command docker run -d --name "$CONTAINER" \
                        --publish 127.0.0.1::8080 image
                  - if: always()
                    run: command docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_scans_resources_behind_supported_docker_global_options(self) -> None:
        global_options = {
            "short context separated": "-c default",
            "short context equals": "-c=default",
            "long context separated": "--context default",
            "long context equals": "--context=default",
            "short host separated": "-H unix:///var/run/docker.sock",
            "short host equals": "-H=unix:///var/run/docker.sock",
            "long host separated": "--host unix:///var/run/docker.sock",
            "long host equals": "--host=unix:///var/run/docker.sock",
            "config separated": "--config /tmp/docker",
            "config equals": "--config=/tmp/docker",
            "short log level separated": "-l info",
            "short log level equals": "-l=info",
            "long log level separated": "--log-level info",
            "long log level equals": "--log-level=info",
            "TLS CA certificate separated": "--tlscacert /tmp/ca.pem",
            "TLS CA certificate equals": "--tlscacert=/tmp/ca.pem",
            "TLS certificate separated": "--tlscert /tmp/cert.pem",
            "TLS certificate equals": "--tlscert=/tmp/cert.pem",
            "TLS key separated": "--tlskey /tmp/key.pem",
            "TLS key equals": "--tlskey=/tmp/key.pem",
            "short debug flag": "-D",
            "long debug flag": "--debug",
            "TLS flag": "--tls",
            "TLS verify flag": "--tlsverify",
        }

        for label, global_option in global_options.items():
            with self.subTest(label=label):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: docker {global_option} network create shared-network
                          - if: always()
                            run: true
                    """
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "docker network create name must use a scoped variable",
                    result.stderr,
                )

    def test_rejects_fixed_resources_behind_combined_global_options(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      docker --context default run -d \
                        --name shared-direct-helper \
                        --publish 127.0.0.1:18080:8080 image
                      command docker -D --context default \
                        --host=unix:///var/run/docker.sock --tls -- \
                        volume create shared-volume
                  - if: always()
                    run: true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker run --name must use a scoped variable",
            result.stderr,
        )
        self.assertIn(
            "Docker published host ports must be dynamic",
            result.stderr,
        )
        self.assertIn(
            "docker volume create name must use a scoped variable",
            result.stderr,
        )

    def test_rejects_unknown_or_incomplete_docker_global_options(self) -> None:
        commands = {
            "unknown option": "docker --namespace shared version",
            "missing value": "docker --context",
        }

        for label, command in commands.items():
            with self.subTest(label=label):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      inspect:
                        steps:
                          - run: {command}
                    """
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn("Docker global option", result.stderr)

    def test_rejects_destructive_commands_behind_docker_global_options(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker run -d --name "$CONTAINER" image
                  - run: |
                      docker --context default rm -f shared-container
                      docker --host unix:///var/run/docker.sock network rm shared-network
                      docker --config=/tmp/docker volume rm shared-volume
                      docker --debug system prune --force
                      docker -c=default compose -p "$PROJECT" down -v || true
                  - if: always()
                    run: docker rm -f "$CONTAINER" || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker container removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "Docker network removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "Docker volume removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "global Docker prune cannot prove job ownership",
            result.stderr,
        )
        self.assertIn(
            "Docker container removal must run in an if: always() step",
            result.stderr,
        )
        self.assertIn(
            "Docker network removal must run in an if: always() step",
            result.stderr,
        )
        self.assertIn(
            "Docker volume removal must run in an if: always() step",
            result.stderr,
        )
        self.assertIn(
            "Docker Compose down must run in an if: always() step",
            result.stderr,
        )

    def test_accepts_scoped_resources_with_docker_global_options(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  NETWORK: network-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  VOLUME: volume-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: |
                      docker -D -c default -H=unix:///var/run/docker.sock \
                        --config /tmp/docker -l=info \
                        --tlscacert /tmp/ca.pem --tlscert=/tmp/cert.pem \
                        --tlskey /tmp/key.pem --tls --tlsverify -- \
                        run -d --name "$CONTAINER" \
                        --publish 127.0.0.1::8080 image
                      docker --debug --context=default \
                        --host unix:///var/run/docker.sock --config=/tmp/docker \
                        --log-level info --tlscacert=/tmp/ca.pem \
                        --tlscert /tmp/cert.pem --tlskey=/tmp/key.pem \
                        network create "$NETWORK"
                      command docker --context default -- volume create "$VOLUME"
                      docker -H=unix:///var/run/docker.sock kill "$CONTAINER"
                      docker --context=default compose -p "$PROJECT" up -d
                  - if: always()
                    run: |
                      docker --context default rm -f "$CONTAINER" || true
                      docker --host=unix:///var/run/docker.sock \
                        network rm "$NETWORK" || true
                      docker --config /tmp/docker volume rm "$VOLUME" || true
                      docker -c default -- compose -p "$PROJECT" down -v || true
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_docker_bin_global_option_bypasses_in_helper(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: scripts/docker-helper.sh
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker_bin=docker
                    "$docker_bin" --context default rm -f shared-container
                    "$docker_bin" --namespace shared version
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker container removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "unrecognized Docker global option --namespace",
            result.stderr,
        )

    def test_accepts_scoped_docker_bin_helper_with_global_options(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: scripts/docker-helper.sh
                  - if: always()
                    run: |
                      docker --context default rm -f "$CONTAINER" || true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    : "${GITHUB_RUN_ID:?}${GITHUB_RUN_ATTEMPT:?}${GITHUB_JOB:?}"
                    docker_bin=docker
                    "$docker_bin" --context=default run -d \
                      --name "$CONTAINER" image
                """
            },
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_docker_bin_helper_cleanup_outside_always_step(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: scripts/docker-helper.sh
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    : "${GITHUB_RUN_ID:?}${GITHUB_RUN_ATTEMPT:?}${GITHUB_JOB:?}"
                    docker_bin=docker
                    "$docker_bin" --context default rm -f "$CONTAINER" || true
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "scripts/docker-helper.sh: Docker container removal must run in "
            "an if: always() step",
            result.stderr,
        )

    def test_accepts_docker_bin_helper_cleanup_in_always_step(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - if: always()
                    run: scripts/docker-helper.sh
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    : "${GITHUB_RUN_ID:?}${GITHUB_RUN_ATTEMPT:?}${GITHUB_JOB:?}"
                    docker_bin=docker
                    "$docker_bin" --context default rm -f "$CONTAINER" || true
                """
            },
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_fixed_docker_resources_behind_normalized_commands(self) -> None:
        commands = {
            "builtin command": "builtin command docker",
            "nested transparent launchers": (
                "builtin command builtin exec env nohup time docker"
            ),
            "alias bypass": r"\docker",
            "relative executable": "./docker",
            "parent-relative executable": "../bin/docker",
            "path-qualified executable": "/usr/bin/docker",
        }

        for label, command in commands.items():
            with self.subTest(label=label):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              {command} run -d --name shared-helper \
                                --publish 127.0.0.1:18080:8080 image
                              {command} network create shared-network
                              {command} volume create shared-volume
                          - if: always()
                            run: true
                    """
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "docker run --name must use a scoped variable",
                    result.stderr,
                )
                self.assertIn(
                    "Docker published host ports must be dynamic",
                    result.stderr,
                )
                self.assertIn(
                    "docker network create name must use a scoped variable",
                    result.stderr,
                )
                self.assertIn(
                    "docker volume create name must use a scoped variable",
                    result.stderr,
                )

    def test_rejects_literal_docker_behind_unclassified_launchers(self) -> None:
        commands = {
            "external wrapper": "/usr/bin/env docker",
            "path-qualified wrapped executable": "timeout 30 /usr/bin/docker",
            "arbitrary launcher": "custom-launcher docker",
            "relative executable argument": "custom-launcher ./docker",
            "parent-relative executable argument": "custom-launcher ../bin/docker",
        }

        for label, command in commands.items():
            with self.subTest(label=label):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              {command} run -d --name shared-helper \
                                --publish 127.0.0.1:18080:8080 image
                              {command} network create shared-network
                              {command} volume create shared-volume
                          - if: always()
                            run: true
                    """
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    f"unclassified command {command.split()[0]} cannot safely consume",
                    result.stderr,
                )

    def test_accepts_data_commands_that_print_docker(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              inspect:
                steps:
                  - run: |
                      echo docker
                      printf '%s\\n' ./docker ../bin/docker
                      printf '%s\\n' /usr/bin/docker
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_accepts_scoped_path_qualified_docker_resources(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  NETWORK: network-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  VOLUME: volume-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      /usr/bin/docker run -d --name "$CONTAINER" \
                        --publish 127.0.0.1::8080 image
                      /usr/bin/docker network create "$NETWORK"
                      /usr/bin/docker volume create "$VOLUME"
                  - if: always()
                    run: |
                      /usr/bin/docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
                      /usr/bin/docker network rm "$NETWORK" >/dev/null 2>&1 || true
                      /usr/bin/docker volume rm "$VOLUME" >/dev/null 2>&1 || true
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_accepts_literal_awk_program_with_dynamic_input(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      awk '{print $1}' "$INPUT"
                      docker run -d --name "$CONTAINER" image
                  - if: always()
                    run: docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_awk_program_that_delegates_dynamic_helper(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER_DIR=./scripts
                      HELPER_NAME=docker-helper.sh
                      HELPER="$HELPER_DIR/$HELPER_NAME"
                      awk -v cmd="$HELPER" 'BEGIN { system(cmd) }'
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "unclassified command awk cannot safely consume",
            result.stderr,
        )

    def test_rejects_awk_program_with_command_pipe(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      awk -v cmd="$COMMAND" 'BEGIN { cmd | getline output }'
                      docker run -d --name "$CONTAINER" image
                  - if: always()
                    run: docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "unclassified command awk cannot safely consume",
            result.stderr,
        )

    def test_rejects_dynamic_awk_program(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      awk "$AWK_PROGRAM" input.txt
                      docker run -d --name "$CONTAINER" image
                  - if: always()
                    run: docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "unclassified command awk cannot safely consume",
            result.stderr,
        )

    def test_rejects_command_substitution_helper_assignment(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPER="$(printf %s ./scripts/docker-helper.sh)"
                      unlisted-launcher --opaque "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_rejects_printf_v_helper_assignment(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      printf -v HELPER %s ./scripts/docker-helper.sh
                      unlisted-launcher --opaque "$HELPER"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_rejects_array_helper_assignment(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      HELPERS=(./scripts/docker-helper.sh)
                      unlisted-launcher --opaque "${HELPERS[0]}"
                  - if: always()
                    run: true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name shared-helper \
                      --publish 127.0.0.1:18080:8080 image
                    docker network create shared-network
                    docker volume create shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "helper invocation must use a literal repository-relative path",
            result.stderr,
        )

    def test_accepts_literal_script_output_assignment(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      image="$(scripts/ci/select-image.sh)"
                      docker run -d --name "$CONTAINER" "$image"
                  - if: always()
                    run: docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
            """,
            {
                "scripts/ci/select-image.sh": """
                    #!/usr/bin/env bash
                    printf '%s\n' example/image:latest
                """
            },
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_eval_and_shell_command_string_indirection(self) -> None:
        for command in (
            'eval "$HELPER"',
            'bash -c "$HELPER"',
            'sh -lc "$HELPER"',
            'command bash -c "$HELPER"',
            'env -S "$HELPER"',
        ):
            with self.subTest(command=command):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          SCOPE: smoke-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              HELPER=./scripts/docker-helper.sh
                              {command}
                          - if: always()
                            run: true
                    """,
                    {
                        "scripts/docker-helper.sh": """
                            #!/usr/bin/env bash
                            docker run -d --name shared-helper image
                        """
                    },
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "helper invocation must use a literal repository-relative path",
                    result.stderr,
                )

    def test_rejects_global_network_and_volume_cleanup(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: |
                      docker ps -aq \
                        --filter "label=com.docker.compose.project=$PROJECT" \
                        | xargs -r docker rm -f >/dev/null 2>&1 || true
                      docker network ls -q \
                        | xargs -r docker network rm >/dev/null 2>&1 || true
                      docker volume ls -q \
                        | xargs -r docker volume rm -f >/dev/null 2>&1 || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker network cleanup must filter resources by its job-scoped "
            "Compose project",
            result.stderr,
        )
        self.assertIn(
            "Docker volume cleanup must filter resources by its job-scoped "
            "Compose project",
            result.stderr,
        )

    def test_rejects_global_cleanup_when_xargs_omits_short_r_option(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: |
                      docker compose -p "$PROJECT" down -v || true
                      docker network ls -q \
                        | xargs docker network rm >/dev/null 2>&1 || true
                      docker volume ls -q \
                        | xargs --no-run-if-empty docker volume rm -f >/dev/null 2>&1 || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker network cleanup must filter resources by its job-scoped "
            "Compose project",
            result.stderr,
        )
        self.assertIn(
            "Docker volume cleanup must filter resources by its job-scoped "
            "Compose project",
            result.stderr,
        )

    def test_rejects_global_docker_prune_cleanup(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: |
                      docker compose -p "$PROJECT" down -v || true
                      docker network prune --force
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "global Docker prune cannot prove job ownership",
            result.stderr,
        )

    def test_rejects_unscoped_removal_outside_always_step(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  NETWORK: network-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  VOLUME: volume-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      docker run -d --name "$CONTAINER" image
                      docker network create "$NETWORK"
                      docker volume create "$VOLUME"
                  - run: |
                      docker rm -f shared-container
                      docker network rm shared-network
                      docker volume rm shared-volume
                  - if: always()
                    run: |
                      docker rm -f "$CONTAINER" || true
                      docker network rm "$NETWORK" || true
                      docker volume rm "$VOLUME" || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker container removal must run in an if: always() step",
            result.stderr,
        )
        self.assertIn(
            "Docker network removal must run in an if: always() step",
            result.stderr,
        )
        self.assertIn(
            "Docker volume removal must run in an if: always() step",
            result.stderr,
        )
        self.assertIn(
            "Docker container removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "Docker network removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "Docker volume removal must target a job-scoped name",
            result.stderr,
        )

    def test_rejects_compose_down_outside_always_step(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: |
                      docker compose -p "$PROJECT" up -d
                      docker compose -p "$PROJECT" down -v || true
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker Compose down must run in an if: always() step",
            result.stderr,
        )

    def test_rejects_unscoped_removal_in_referenced_helper(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: scripts/docker-helper.sh
                  - if: always()
                    run: docker rm -f "$CONTAINER" || true
            """,
            {
                "scripts/docker-helper.sh": """
                    #!/usr/bin/env bash
                    docker run -d --name "$CONTAINER" image
                    docker rm -f shared-container
                    docker network rm shared-network
                    docker volume rm shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker container removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "Docker network removal must target a job-scoped name",
            result.stderr,
        )
        self.assertIn(
            "Docker volume removal must target a job-scoped name",
            result.stderr,
        )

    def test_rejects_unscoped_alternate_destructive_commands(self) -> None:
        commands = {
            "container rm": "docker container rm -f shared-container",
            "container remove": "docker container remove -f shared-container",
            "kill": "docker kill shared-container",
            "container stop": "docker container stop shared-container",
            "container prune": "docker container prune --force",
            "network prune": "docker network prune --force",
            "system prune": "docker system prune --force",
            "volume prune": "docker volume prune --force",
        }

        for label, command in commands.items():
            with self.subTest(label=label):
                result = self.run_policy(
                    f"""
                    name: Docker
                    on: workflow_dispatch
                    jobs:
                      smoke:
                        env:
                          CONTAINER: helper-${{{{ github.run_id }}}}-${{{{ github.run_attempt }}}}-${{{{ github.job }}}}
                        steps:
                          - run: |
                              docker run -d --name "$CONTAINER" image
                              {command}
                          - if: always()
                            run: docker rm -f "$CONTAINER" || true
                    """
                )

                self.assertNotEqual(0, result.returncode)
                if "prune" in label:
                    self.assertIn(
                        "global Docker prune cannot prove job ownership",
                        result.stderr,
                    )
                else:
                    operation = (
                        "removal"
                        if label in {"container rm", "container remove"}
                        else label.rsplit(maxsplit=1)[-1]
                    )
                    self.assertIn(
                        f"Docker container {operation} must target a job-scoped name",
                        result.stderr,
                    )

    def test_accepts_job_scoped_container_chaos_operation(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: |
                      docker run -d --name "$CONTAINER" image
                      docker kill "$CONTAINER"
                      docker container stop "$CONTAINER"
                  - if: always()
                    run: docker rm -f "$CONTAINER" || true
            """
        )

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_fixed_resource_values_hidden_behind_variables(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  SCOPE: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  PROJECT: shared-project
                  CONTAINER: shared-container
                  NETWORK: shared-network
                  VOLUME: shared-volume
                  SERVER_PORT: "0"
                steps:
                  - run: |
                      docker compose -p "$PROJECT" up -d
                      docker run -d --name "$CONTAINER" image
                      docker network create "$NETWORK"
                      docker volume create "$VOLUME"
                  - if: always()
                    run: |
                      docker rm -f "${SCOPE}-container" || true
                      docker network rm "${SCOPE}-network" || true
                      docker volume rm "${SCOPE}-volume" || true
                      docker compose -p "${SCOPE}-project" down -v || true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "docker compose project must use a scoped variable",
            result.stderr,
        )
        self.assertIn("docker run --name must use a scoped variable", result.stderr)
        self.assertIn(
            "docker network create name must use a scoped variable",
            result.stderr,
        )
        self.assertIn(
            "docker volume create name must use a scoped variable",
            result.stderr,
        )
        self.assertIn("needs matching harmless cleanup", result.stderr)

    def test_rejects_unrelated_always_step_as_cleanup(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: true
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            'compose resource "$PROJECT" needs matching harmless cleanup',
            result.stderr,
        )

    def test_rejects_cleanup_that_is_not_harmless_after_partial_setup(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: docker run -d --name "$CONTAINER" image
                  - if: always()
                    run: docker rm -f "$CONTAINER"
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker cleanup commands must remain harmless after partial setup",
            result.stderr,
        )

    def test_rejects_cleanup_only_step_that_is_not_harmless(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  CONTAINER: helper-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - if: always()
                    run: docker container rm -f "$CONTAINER"
            """
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Docker cleanup commands must remain harmless after partial setup",
            result.stderr,
        )

    def test_rejects_fixed_compose_resource_name_hidden_behind_variable(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  NETWORK: shared-network
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """,
            {
                "docker-compose.yml": """
                    services:
                      server:
                        image: example
                    networks:
                      default:
                        name: ${NETWORK}
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Compose network default name must be job-scoped",
            result.stderr,
        )

    def test_rejects_fixed_ports_in_default_compose_file_without_override(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """,
            {
                "docker-compose.yml": """
                    services:
                      server:
                        ports:
                          - "8080:8080"
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "service server publishes a fixed host port without a CI override",
            result.stderr,
        )

    def test_rejects_compose_port_variable_left_at_fixed_default(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  UNUSED_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" -f docker-compose.published.yml up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" -f docker-compose.published.yml down -v || true
            """,
            {
                "docker-compose.published.yml": """
                    services:
                      server:
                        ports:
                          - "${SERVER_PORT:-8080}:8080"
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "host port variable SERVER_PORT must be set to 0 by the workflow",
            result.stderr,
        )

    def test_rejects_fixed_compose_network_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """,
            {
                "docker-compose.yml": """
                    services:
                      server:
                        image: example
                    networks:
                      default:
                        name: shared-network
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Compose network default name must be job-scoped",
            result.stderr,
        )

    def test_rejects_fixed_compose_volume_names(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                  SERVER_PORT: "0"
                steps:
                  - run: docker compose -p "$PROJECT" up -d
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """,
            {
                "docker-compose.yml": """
                    services:
                      server:
                        image: example
                    volumes:
                      data:
                        name: shared-volume
                """
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Compose volume data name must be job-scoped",
            result.stderr,
        )

    def test_rejects_unscoped_compose_in_referenced_python(self) -> None:
        result = self.run_policy(
            """
            name: Docker
            on: workflow_dispatch
            jobs:
              smoke:
                env:
                  PROJECT: smoke-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}
                steps:
                  - run: scripts/smoke.sh
                  - if: always()
                    run: docker compose -p "$PROJECT" down -v || true
            """,
            {
                "scripts/smoke.sh": """
                    #!/usr/bin/env bash
                    project="smoke-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-${GITHUB_JOB}"
                    python3 smoke.py
                """,
                "scripts/smoke.py": """
                    import subprocess
                    subprocess.run(["docker", "compose", "up", "-d"], check=True)
                """,
            },
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "Python docker compose invocation must set -p/--project-name",
            result.stderr,
        )


if __name__ == "__main__":
    unittest.main()
