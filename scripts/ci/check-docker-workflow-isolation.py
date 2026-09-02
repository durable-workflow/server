#!/usr/bin/env python3
"""Reject Actions Docker resources that are not scoped to one job execution."""

from __future__ import annotations

import argparse
import re
import shlex
import sys
import textwrap
from dataclasses import dataclass
from pathlib import Path


WORKFLOW_SCOPE_PARTS = (
    "${{ github.run_id }}",
    "${{ github.run_attempt }}",
    "${{ github.job }}",
)
SCRIPT_SCOPE_PARTS = ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT", "GITHUB_JOB")
REMOTE_SCRIPT_BOUNDARY = (
    "docker-workflow-isolation: transitive-scripts-run-remotely"
)
SHELL_COMMAND_PREFIX = r"""
    (?:^|[;&|({])\s*(?:-\s+)?(?:run:\s*)?
    (?:
        (?:if|elif|while|until|then|do)\s+
        |!\s+
        |[A-Za-z_][A-Za-z0-9_]*=[^\s;&|()]*\s+
    )*
"""
WORKFLOW_SCRIPT_REFERENCE = re.compile(
    SHELL_COMMAND_PREFIX
    + r"""
    (?:
        (?:bash|sh|python3?)\s+(?:-[^\s]+\s+)*
        |(?:source|\.)\s+
        |(?:\./)?
    )
    ["']?(?:\./)?((?:[\w.-]+/)*[\w.-]+\.(?:py|sh))["']?
    (?=\s|[;&|)]|$)""",
    re.MULTILINE | re.VERBOSE,
)
WORKFLOW_LOCAL_SCRIPT_TOKEN = re.compile(
    r"""(?<![\w./-])(?P<path>(?:\./)?(?:[\w.-]+/)*[\w.-]+\.(?:py|sh))\b"""
)
WORKFLOW_EMBEDDED_SCRIPT_TOKEN = re.compile(
    r"""(?<![\w.-])(?P<path>scripts/[\w./-]+\.(?:py|sh))\b"""
)
LOCAL_SCRIPT_REFERENCE = re.compile(
    r"""(?<![\w.-])(?P<path>(?:scripts/[\w./-]+|[\w.-]+)\.(?:py|sh))\b"""
)
LITERAL_SCRIPT_PATH = re.compile(
    r"""^(?:\./)?(?P<path>(?:[\w.-]+/)*[\w.-]+\.(?:py|sh))$"""
)
WORKFLOW_SCRIPT_INVOCATION = re.compile(
    SHELL_COMMAND_PREFIX
    + r"""
    (?P<program>bash|sh|python3?|source|\.|command|exec|env|nohup|time)
    (?=\s)(?P<arguments>[^;&|]*)""",
    re.MULTILINE | re.VERBOSE,
)
WORKFLOW_COMMAND_INVOCATION = re.compile(
    SHELL_COMMAND_PREFIX
    + r"""
    (?P<program>"[^"\n]+"|'[^'\n]+'|[^\s;&|()\n]+)
    (?P<arguments>[^;&|\n]*)""",
    re.MULTILINE | re.VERBOSE,
)
MODELED_SCRIPT_DATA_INVOCATION = re.compile(
    SHELL_COMMAND_PREFIX
    + r"""
    python3?\s+-m\s+(?:py_compile|unittest)\b
    (?P<arguments>[^;&|]*)""",
    re.MULTILINE | re.VERBOSE,
)
VARIABLE_COMMAND_INVOCATION = re.compile(
    SHELL_COMMAND_PREFIX
    + r"""
    (?P<target>
        ["']?\$(?:\{[A-Za-z_][A-Za-z0-9_]*(?:\[[^}]+\])?\}|[A-Za-z_][A-Za-z0-9_]*)["']?
    )
    (?=\s|[;&|]|$)""",
    re.MULTILINE | re.VERBOSE,
)
EVAL_INVOCATION = re.compile(
    SHELL_COMMAND_PREFIX + r"""eval(?=\s|$)""",
    re.MULTILINE | re.VERBOSE,
)
NON_LAUNCHING_DATA_COMMANDS = frozenset(
    {
        ":",
        "[",
        "[[",
        "basename",
        "cat",
        "chmod",
        "curl",
        "echo",
        "export",
        "kill",
        "mkdir",
        "printf",
        "read",
        "tail",
        "test",
    }
)
SHELL_GRAMMAR_WORDS = frozenset(
    {
        "case",
        "do",
        "done",
        "elif",
        "else",
        "esac",
        "fi",
        "for",
        "if",
        "in",
        "select",
        "then",
        "until",
        "while",
    }
)
SHELL_EXPANSION = re.compile(r"""(?<!\\)(?:`|[<>]\(|\$(?:\{|\(|[A-Za-z_0-9@*#?!-]))""")
COMPOSE_COMMAND = re.compile(r"(?:^|run:\s*|[;&|!(`])\s*docker\s+compose\b")
DOCKER_RUN_COMMAND = re.compile(
    r"""(?:(?:^|run:\s*|[;&|!(`])\s*docker|["']?\$docker_bin["']?)\s+run\b"""
)
DOCKER_RESOURCE_CREATE_COMMAND = re.compile(
    r"""(?:(?:^|run:\s*|[;&|!(`])\s*docker|["']?\$docker_bin["']?)\s+(?P<kind>network|volume)\s+create\b"""
)
PROJECT_FLAG = re.compile(
    r"""(?:^|\s)(?:-p|--project-name)(?:=|\s+)(?P<project>"[^"]+"|'[^']+'|[^\s\\]+)"""
)
PYTHON_COMPOSE_COMMAND = re.compile(r"""["']docker["']\s*,\s*["']compose["']""")
PYTHON_PROJECT_VALUE = re.compile(
    r"""["']docker["']\s*,\s*["']compose["']\s*,\s*["'](?:-p|--project-name)["']\s*,\s*(?P<project>[^,\]\n]+)"""
)
PYTHON_COMPOSE_PROJECT_ENV = re.compile(
    r"""["']--compose-project["'][^\n]*os\.environ\.get\(\s*["'](?P<variable>[A-Za-z_][A-Za-z0-9_]*)["']"""
)
CONTAINER_NAME = re.compile(r"""--name(?:=|\s+)(?P<name>"[^"]+"|'[^']+'|[^\s\\]+)""")
FIXED_PORT_ASSIGNMENT = re.compile(
    r"""^\s+(?:port|SERVER_PORT|[A-Z][A-Z0-9_]*_PORT):\s*["']?[1-9]\d*["']?\s*$""",
    re.MULTILINE,
)
DOCKER_PUBLISH = re.compile(
    r"""(?:^|\s)(?:--publish|-p)(?:=|\s+)(?P<publish>"[^"]+"|'[^']+'|[^\s\\]+)"""
)
FIXED_COMPOSE_PORT = re.compile(r"""-\s*["']?[1-9]\d*:[1-9]\d*(?:/tcp)?["']?""")
DYNAMIC_PORT_EVIDENCE = re.compile(
    r"""(?:^\s+(?:SERVER_PORT|[A-Z][A-Z0-9_]*_PORT):\s*["']?0["']?\s*$|-\s*["']?0:[1-9]\d*(?:/tcp)?["']?|ports:\s*!override\s*\[\]|SERVER_PORT_MAPPING=["'][1-9]\d*["'])""",
    re.MULTILINE,
)
COMPOSE_FILE_NAME = re.compile(
    r"""(?<![\w.-])(docker-compose(?:\.[A-Za-z0-9_-]+)?\.ya?ml)\b"""
)
COMPOSE_VARIABLE_PORT = re.compile(
    r"""^-\s*["']?\${(?P<variable>[A-Z][A-Z0-9_]*)(?::-[^}]*)?}:[1-9]\d*(?:/tcp)?["']?$"""
)
COMPOSE_FIXED_PORT = re.compile(
    r"""^-\s*["']?(?:(?:127\.0\.0\.1|0\.0\.0\.0):)?[1-9]\d*:[1-9]\d*(?:/tcp)?["']?$"""
)
COMPOSE_FIXED_LONG_PORT = re.compile(r"""^published:\s*["']?[1-9]\d*["']?$""")
COMPOSE_VARIABLE_LONG_PORT = re.compile(
    r"""^published:\s*["']?\${(?P<variable>[A-Z][A-Z0-9_]*)(?::-[^}]*)?}["']?$"""
)
VARIABLE_REFERENCE = re.compile(
    r"""\$(?:{(?P<braced>[A-Za-z_][A-Za-z0-9_]*)(?::[-+?][^}]*)?}|(?P<plain>[A-Za-z_][A-Za-z0-9_]*))"""
)
PYTHON_ENV_REFERENCE = re.compile(
    r"""(?:os\.environ(?:\.get)?\(\s*|os\.environ\[)\s*["'](?P<variable>[A-Za-z_][A-Za-z0-9_]*)["']"""
)
COMPOSE_OPERATION = re.compile(
    r"""(?:^|\s)(?:build|config|cp|create|down|events|exec|images|kill|logs|ls|pause|port|ps|publish|pull|push|restart|rm|run|scale|start|stop|top|unpause|up|version|wait|watch)(?:\s|$)"""
)
COMPOSE_CREATE_OPERATION = re.compile(
    r"""(?:^|\s)(?:create|restart|run|start|up)(?:\s|$)"""
)
COMPOSE_DOWN_OPERATION = re.compile(r"""(?:^|\s)down(?:\s|$)""")
DOCKER_CONTAINER_DESTRUCTIVE_COMMAND = re.compile(
    r"""(?:(?:^|run:\s*|[;&|!(`])\s*docker|["']?\$docker_bin["']?)
    \s+(?:container\s+)?(?P<operation>rm|remove|kill|stop)\b""",
    re.VERBOSE,
)
DOCKER_RESOURCE_DESTRUCTIVE_COMMAND = re.compile(
    r"""(?:(?:^|run:\s*|[;&|!(`])\s*docker|["']?\$docker_bin["']?)
    \s+(?P<kind>network|volume)\s+(?P<operation>rm|remove)\b""",
    re.VERBOSE,
)
DOCKER_GLOBAL_PRUNE_COMMAND = re.compile(
    r"""(?:(?:^|run:\s*|[;&|!(`])\s*docker|["']?\$docker_bin["']?)
    \s+(?:system|container|network|volume)\s+prune\b""",
    re.VERBOSE,
)
DOCKER_GLOBAL_OPTIONS_WITH_VALUES = frozenset(
    {
        "-c",
        "--context",
        "-H",
        "--host",
        "--config",
        "-l",
        "--log-level",
        "--tlscacert",
        "--tlscert",
        "--tlskey",
    }
)
DOCKER_GLOBAL_FLAG_OPTIONS = frozenset(
    {
        "-D",
        "--debug",
        "--tls",
        "--tlsverify",
        "-h",
        "--help",
        "-v",
        "--version",
    }
)
COMPOSE_PROJECT_LABEL = re.compile(
    r"""com\.docker\.compose\.project=(?P<project>"[^"]+"|'[^']+'|[^\s"']+)"""
)
XARGS_DOCKER_REMOVE = re.compile(
    r"""\bxargs\b[^|;&\n]*?\bdocker
    \s+(?:(?P<kind>network|volume)\s+)?rm\b""",
    re.VERBOSE,
)
COMPOSE_PORT_DISCOVERY = re.compile(
    r"""\$\([^)]*(?:(?:docker\s+)?compose|["']?\$docker_bin["']?)\b
    [^)]*\bport\b[^)]*\)""",
    re.VERBOSE,
)


@dataclass(frozen=True)
class OwnedResource:
    kind: str
    value: str


def logical_shell_lines(source: str) -> list[tuple[int, str]]:
    """Join backslash continuations while retaining the first source line."""

    commands: list[tuple[int, str]] = []
    pending = ""
    first_line = 0

    for line_number, raw_line in enumerate(source.splitlines(), start=1):
        stripped = raw_line.strip()
        if not pending and (not stripped or stripped.startswith("#")):
            continue

        if not pending:
            first_line = line_number
        pending = f"{pending} {stripped}".strip()

        if pending.endswith("\\"):
            pending = pending[:-1].rstrip()
            continue

        commands.append((first_line, pending))
        pending = ""

    if pending:
        commands.append((first_line, pending))

    return commands


def starts_inside_shell_quote(command: str, offset: int) -> bool:
    quote = ""
    escaped = False

    for character in command[:offset]:
        if escaped:
            escaped = False
            continue
        if character == "\\" and quote != "'":
            escaped = True
            continue
        if quote:
            if character == quote:
                quote = ""
            continue
        if character in {"'", '"'}:
            quote = character

    return bool(quote)


def compose_commands_use_default_file(source: str) -> bool:
    """Return whether an operational Compose command relies on the default file."""

    for _, command in resource_scan_commands(source):
        for match in COMPOSE_COMMAND.finditer(command):
            matched_prefix = command[match.start() : match.end()].lstrip()
            if starts_inside_shell_quote(
                command, match.start()
            ) and not matched_prefix.startswith(("(", "`")):
                continue

            arguments = command[match.end() :]
            if re.match(r"\s*(?:version|help)(?:\s|$)", arguments):
                continue
            if COMPOSE_OPERATION.search(arguments) and not re.search(
                r"(?:^|\s)(?:-f|--file)(?:=|\s)", arguments
            ):
                return True

    return False


def compose_port_contract(
    source: str,
) -> tuple[set[str], dict[str, set[str]], set[str]]:
    """Return fixed, variable-backed, and explicitly overridden port services."""

    fixed_services: set[str] = set()
    variable_services: dict[str, set[str]] = {}
    overridden_services: set[str] = set()
    parents: list[tuple[int, str]] = []
    port_indent: int | None = None
    port_service = ""

    for raw_line in source.splitlines():
        if not raw_line.strip() or raw_line.lstrip().startswith("#"):
            continue

        indent = len(raw_line) - len(raw_line.lstrip())
        stripped = raw_line.strip()

        while parents and parents[-1][0] >= indent:
            parents.pop()
        if port_indent is not None and indent <= port_indent:
            port_indent = None
            port_service = ""

        if port_indent is not None and port_service:
            variable_match = COMPOSE_VARIABLE_PORT.match(stripped)
            if variable_match:
                variable_services.setdefault(port_service, set()).add(
                    variable_match.group("variable")
                )
            elif long_variable_match := COMPOSE_VARIABLE_LONG_PORT.match(stripped):
                variable_services.setdefault(port_service, set()).add(
                    long_variable_match.group("variable")
                )
            elif COMPOSE_FIXED_PORT.match(stripped) or COMPOSE_FIXED_LONG_PORT.match(
                stripped
            ):
                fixed_services.add(port_service)

        key_match = re.match(r"^(?P<key>[A-Za-z0-9_-]+):(?P<value>.*)$", stripped)
        if key_match is None:
            continue

        key = key_match.group("key")
        value = key_match.group("value").strip()
        if key == "ports" and parents:
            port_indent = indent
            port_service = parents[-1][1]
            if value.startswith("!override"):
                overridden_services.add(port_service)
        parents.append((indent, key))

    return fixed_services, variable_services, overridden_services


def variable_assignments(source: str, variable: str) -> list[str]:
    """Return workflow, shell, and simple Python assignments for a variable."""

    matches = re.finditer(
        rf"""(?m)^\s*(?:(?:export|local|readonly)\s+)?{re.escape(variable)}
        \s*(?:=|:)\s*(?P<value>.+?)\s*$""",
        source,
        re.VERBOSE,
    )
    return [
        match.group("value")
        for match in matches
        if not match.group("value").rstrip().endswith("\\")
    ]


def referenced_variables(value: str, source: str = "") -> set[str]:
    references = {
        braced or plain
        for braced, plain in VARIABLE_REFERENCE.findall(value)
        if braced or plain
    }
    references.update(PYTHON_ENV_REFERENCE.findall(value))
    bare_identifier = re.fullmatch(r"\s*([A-Za-z_][A-Za-z0-9_]*)\s*", value)
    if (
        bare_identifier
        and source
        and variable_assignments(source, bare_identifier.group(1))
    ):
        references.add(bare_identifier.group(1))
    return references


def scope_parts_for_value(
    source: str,
    value: str,
    inspected: set[str] | None = None,
) -> set[int]:
    """Return job-identity components that transitively contribute to a value."""

    parts = {
        index
        for index, (workflow_part, script_part) in enumerate(
            zip(WORKFLOW_SCOPE_PARTS, SCRIPT_SCOPE_PARTS, strict=True)
        )
        if workflow_part in value or script_part in value
    }
    inspected = set() if inspected is None else inspected

    for variable in referenced_variables(value, source):
        if variable in SCRIPT_SCOPE_PARTS or variable in inspected:
            continue
        assignment_parts = [
            scope_parts_for_value(
                source,
                assignment,
                inspected | {variable},
            )
            for assignment in variable_assignments(source, variable)
            if assignment.strip().strip("\"'")
        ]
        if assignment_parts:
            parts.update(set.intersection(*assignment_parts))

    return parts


def value_is_job_scoped(source: str, value: str) -> bool:
    return scope_parts_for_value(source, value) == set(range(len(SCRIPT_SCOPE_PARTS)))


def value_resolves_to_zero(
    source: str,
    value: str,
    inspected: set[str] | None = None,
) -> bool:
    """Return whether a literal or referenced host-port value resolves to zero."""

    del inspected
    return port_value_state(value, port_variable_states(source)) == "dynamic"


def port_value_state(value: str, states: dict[str, str]) -> str:
    """Classify a shell/YAML port value as dynamic, fixed, or unproven."""

    value = value.strip().strip("\"'")
    if value == "0" or COMPOSE_PORT_DISCOVERY.search(value):
        return "dynamic"
    if re.fullmatch(r"[1-9]\d*", value):
        return "fixed"

    variable_match = re.fullmatch(
        r"""\$(?:{
        (?P<braced>[A-Za-z_][A-Za-z0-9_]*)
        (?:(?P<operator>:-|-)(?P<default>[^}]*))?
        }|(?P<plain>[A-Za-z_][A-Za-z0-9_]*))""",
        value,
        re.VERBOSE,
    )
    if variable_match is None:
        return "unknown"

    variable = variable_match.group("braced") or variable_match.group("plain")
    current = states.get(variable, "unknown")
    if current != "unknown" or variable_match.group("operator") is None:
        return current
    return port_value_state(variable_match.group("default") or "", states)


def port_variable_states(source: str) -> dict[str, str]:
    """Evaluate simple assignments in source order for command-effective ports."""

    states: dict[str, str] = {}
    assignment = re.compile(
        r"""^\s*(?:(?:export|local|readonly)\s+)?
        (?P<variable>[A-Za-z_][A-Za-z0-9_]*)
        \s*(?:=|:)\s*(?P<value>.+?)\s*$""",
        re.VERBOSE,
    )

    for raw_line in source.splitlines():
        match = assignment.match(raw_line)
        if match is None or match.group("value").rstrip().endswith("\\"):
            continue
        states[match.group("variable")] = port_value_state(
            match.group("value"),
            states,
        )

    return states


def variable_is_dynamic(source: str, variable: str) -> bool:
    return value_resolves_to_zero(source, f"${{{variable}}}")


def compose_global_resource_names(source: str) -> list[tuple[str, str, str]]:
    """Return explicit names that bypass Compose project scoping."""

    names: list[tuple[str, str, str]] = []
    section = ""
    resource = ""

    for raw_line in source.splitlines():
        if not raw_line.strip() or raw_line.lstrip().startswith("#"):
            continue

        indent = len(raw_line) - len(raw_line.lstrip())
        stripped = raw_line.strip()

        if indent == 0:
            section_match = re.match(r"^(networks|volumes):(?:\s*(?:#.*)?)?$", stripped)
            section = section_match.group(1) if section_match else ""
            resource = ""
            continue

        if not section:
            continue

        if indent == 2:
            resource_match = re.match(
                r"^(?P<resource>[A-Za-z0-9_.-]+):(?P<value>.*)$", stripped
            )
            if resource_match is None:
                resource = ""
                continue
            resource = resource_match.group("resource")
            inline_name = re.search(
                r"(?:^|[{,]\s*)name:\s*(?P<name>[^,}]+)",
                resource_match.group("value"),
            )
            if inline_name:
                names.append((section, resource, inline_name.group("name").strip()))
            continue

        if resource and indent > 2:
            name_match = re.match(r"^name:\s*(?P<name>[^#]+?)(?:\s+#.*)?$", stripped)
            if name_match:
                names.append((section, resource, name_match.group("name").strip()))

    return names


def compose_resource_name_is_scoped(source: str, value: str) -> bool:
    return value_is_job_scoped(source, value.strip("\"'"))


def inspect_compose_files(
    root: Path,
    resource_source: str,
    resolution_sources: list[str],
) -> list[str]:
    """Reject unscoped host resources inherited from repository Compose files."""

    violations: list[str] = []
    file_names = set(COMPOSE_FILE_NAME.findall(resource_source))
    if compose_commands_use_default_file(resource_source):
        file_names.add("docker-compose.yml")

    _, _, overridden_services = compose_port_contract(resource_source)

    for file_name in sorted(file_names):
        compose_file = root / file_name
        if not compose_file.is_file():
            continue

        compose_source = compose_file.read_text(encoding="utf-8")
        fixed_services, variable_services, _ = compose_port_contract(compose_source)
        for service in sorted(fixed_services - overridden_services):
            violations.append(
                f"{compose_file}: service {service} publishes a fixed host port "
                "without a CI override"
            )

        for service, variables in sorted(variable_services.items()):
            if service in overridden_services:
                continue
            for variable in sorted(variables):
                if not resolution_sources or not all(
                    variable_is_dynamic(resolution_source, variable)
                    for resolution_source in resolution_sources
                ):
                    violations.append(
                        f"{compose_file}: service {service} host port variable "
                        f"{variable} must be set to 0 by the workflow"
                    )

        for kind, resource, value in compose_global_resource_names(compose_source):
            if not compose_resource_name_is_scoped(resource_source, value):
                violations.append(
                    f"{compose_file}: Compose {kind[:-1]} {resource} name must be "
                    "job-scoped or left to Compose project scoping"
                )

    return violations


def heredoc_bodies(source: str) -> list[str]:
    """Return shell heredoc bodies so generated Compose inputs are inspected."""

    bodies: list[str] = []
    lines = source.splitlines()
    index = 0

    while index < len(lines):
        start = re.search(
            r"""<<-?\s*\\?["']?(?P<delimiter>[A-Za-z_][A-Za-z0-9_]*)["']?""",
            lines[index],
        )
        if start is None:
            index += 1
            continue

        delimiter = start.group("delimiter")
        body: list[str] = []
        index += 1
        while index < len(lines):
            if lines[index].strip() == delimiter:
                break
            body.append(lines[index])
            index += 1

        if body:
            bodies.append(textwrap.dedent("\n".join(body)))
        index += 1

    return bodies


def source_without_heredoc_bodies(source: str) -> str:
    """Return shell source with generated file contents removed."""

    retained: list[str] = []
    delimiter = ""

    for line in source.splitlines():
        if delimiter:
            if line.strip() == delimiter:
                delimiter = ""
            continue

        retained.append(line)
        start = re.search(
            r"""<<-?\s*\\?["']?(?P<delimiter>[A-Za-z_][A-Za-z0-9_]*)["']?""",
            line,
        )
        if start is not None:
            delimiter = start.group("delimiter")

    return "\n".join(retained)


def inspect_generated_compose(
    path: Path,
    source: str,
    resolution_sources: list[str],
) -> list[str]:
    """Reject unsafe resources in Compose YAML generated by a workflow or helper."""

    violations: list[str] = []
    candidates = [
        body
        for body in heredoc_bodies(source)
        if re.search(r"(?m)^services:\s*$", body)
    ]

    for compose_source in candidates:
        fixed_services, variable_services, _ = compose_port_contract(compose_source)
        for service in sorted(fixed_services):
            violations.append(
                f"{path}: generated Compose service {service} publishes a fixed "
                "host port"
            )

        for service, variables in sorted(variable_services.items()):
            for variable in sorted(variables):
                if not resolution_sources or not all(
                    variable_is_dynamic(resolution_source, variable)
                    for resolution_source in resolution_sources
                ):
                    violations.append(
                        f"{path}: generated Compose service {service} host port "
                        f"variable {variable} must resolve to 0"
                    )

        for kind, resource, value in compose_global_resource_names(compose_source):
            if not resolution_sources or not all(
                compose_resource_name_is_scoped(resolution_source, value)
                for resolution_source in resolution_sources
            ):
                violations.append(
                    f"{path}: generated Compose {kind[:-1]} {resource} name must "
                    "derive from github.run_id, github.run_attempt, and job identity"
                )

    return violations


def docker_publish_is_dynamic(
    source: str,
    publish: str,
    inspected: set[str] | None = None,
) -> bool:
    """Return whether Docker is guaranteed to choose the published host port."""

    publish = publish.strip().strip("\"'")
    inspected = set() if inspected is None else inspected

    variable_match = re.fullmatch(
        r"""\$(?:{(?P<braced>[A-Za-z_][A-Za-z0-9_]*)(?::[-+?][^}]*)?}|(?P<plain>[A-Za-z_][A-Za-z0-9_]*))""",
        publish,
    )
    if variable_match:
        variable = variable_match.group("braced") or variable_match.group("plain")
        if variable in inspected:
            return False
        assignments = variable_assignments(source, variable)
        return bool(assignments) and all(
            docker_publish_is_dynamic(
                source,
                assignment,
                inspected | {variable},
            )
            for assignment in assignments
        )

    without_protocol = re.sub(r"/(?:tcp|udp|sctp)$", "", publish)
    parts = without_protocol.rsplit(":", maxsplit=2)
    if len(parts) < 2:
        return bool(re.fullmatch(r"[1-9]\d*", without_protocol))

    host_port = parts[-2]
    return host_port == "" or value_resolves_to_zero(source, host_port)


def created_resource_name(arguments: str) -> str | None:
    """Return the positional name from `docker network/volume create` arguments."""

    command_segment = re.split(r"\s*(?:&&|\|\||[;|])\s*", arguments, maxsplit=1)[0]
    try:
        tokens = shlex.split(command_segment)
    except ValueError:
        tokens = command_segment.split()

    candidates = [
        token
        for token in tokens
        if not token.startswith("-") and not re.match(r"^\d*[<>]", token)
    ]
    return candidates[-1] if candidates else None


def removed_resource_names(arguments: str) -> list[str]:
    """Return positional names from a Docker resource removal command."""

    command_segment = re.split(r"\s*(?:&&|\|\||[;|])\s*", arguments, maxsplit=1)[0]
    try:
        tokens = shlex.split(command_segment)
    except ValueError:
        tokens = command_segment.split()

    names: list[str] = []
    offset = 0
    options_with_values = {"--signal", "--time", "-s", "-t"}
    while offset < len(tokens):
        token = tokens[offset]
        if token in options_with_values:
            offset += 2
            continue
        if token.startswith(("--signal=", "--time=")):
            offset += 1
            continue
        if token.startswith("-") or re.match(r"^\d*[<>]", token) or token == "true":
            offset += 1
            continue
        names.append(token)
        offset += 1

    return names


def owned_resources(source: str) -> set[OwnedResource]:
    """Return the Compose and explicitly named Docker resources a job creates."""

    resources: set[OwnedResource] = set()

    for _, command in resource_scan_commands(source):
        for match in COMPOSE_COMMAND.finditer(command):
            matched_prefix = command[match.start() : match.end()].lstrip()
            if starts_inside_shell_quote(
                command, match.start()
            ) and not matched_prefix.startswith(("(", "`")):
                continue

            arguments = command[match.end() :]
            project_match = PROJECT_FLAG.search(arguments)
            if project_match is None or COMPOSE_DOWN_OPERATION.search(arguments):
                continue
            if COMPOSE_CREATE_OPERATION.search(arguments) or (
                not COMPOSE_OPERATION.search(arguments) and "$@" in arguments
            ):
                resources.add(OwnedResource("compose", project_match.group("project")))

        for run_match in DOCKER_RUN_COMMAND.finditer(command):
            if starts_inside_shell_quote(command, run_match.start()):
                continue
            name_match = CONTAINER_NAME.search(command[run_match.end() :])
            if name_match:
                resources.add(OwnedResource("container", name_match.group("name")))

        for create_match in DOCKER_RESOURCE_CREATE_COMMAND.finditer(command):
            if starts_inside_shell_quote(command, create_match.start()):
                continue
            name = created_resource_name(command[create_match.end() :])
            if name:
                resources.add(OwnedResource(create_match.group("kind"), name))

    python_project = PYTHON_PROJECT_VALUE.search(source)
    if python_project:
        resources.add(
            OwnedResource(
                "compose",
                python_project_scope_value(source, python_project.group("project")),
            )
        )

    return resources


def python_project_scope_value(source: str, value: str) -> str:
    """Resolve a Python helper parameter to its job-provided environment input."""

    if variable_assignments(source, value.strip()):
        return value
    environment_match = PYTHON_COMPOSE_PROJECT_ENV.search(source)
    if environment_match:
        return f"${{{environment_match.group('variable')}}}"
    return value


def workflow_step_blocks(job_source: str) -> list[str]:
    """Split one workflow job into its Actions step blocks."""

    steps: list[str] = []
    current: list[str] = []

    for line in job_source.splitlines():
        if re.match(r"^      -\s", line):
            if current:
                steps.append("\n".join(current))
            current = [line]
        elif current:
            current.append(line)

    if current:
        steps.append("\n".join(current))
    return steps


def resource_aliases(
    source: str,
    value: str,
    inspected: set[str] | None = None,
) -> set[str]:
    """Return non-identity variables and literals that identify one resource."""

    inspected = set() if inspected is None else inspected
    aliases: set[str] = set()
    references = referenced_variables(value, source)

    for variable in references:
        if variable in SCRIPT_SCOPE_PARTS or variable in inspected:
            continue
        aliases.add(f"${variable}")
        for assignment in variable_assignments(source, variable):
            aliases.update(resource_aliases(source, assignment, inspected | {variable}))

    if not references:
        normalized = value.strip().strip("\"'")
        normalized = re.sub(r"\s+", "", normalized)
        if normalized:
            aliases.add(f"={normalized}")

    return aliases


def cleanup_resources(
    job_source: str,
    resolution_source: str,
) -> tuple[set[OwnedResource], list[str]]:
    """Return resources removed by tolerant always-steps and cleanup violations."""

    resources: set[OwnedResource] = set()
    violations: list[str] = []

    for step in workflow_step_blocks(job_source):
        if not re.search(r"(?m)^\s+(?:-\s+)?if:\s*.*always\(\)", step):
            continue

        commands = logical_shell_lines(step)
        label_cleanup: dict[str, set[str]] = {}
        step_lines = step.splitlines()
        folded_run = next(
            (
                index
                for index, line in enumerate(step_lines)
                if re.match(r"^\s+run:\s*>-?\s*$", line)
            ),
            None,
        )
        if folded_run is not None:
            commands.append(
                (
                    folded_run + 1,
                    " ".join(line.strip() for line in step_lines[folded_run + 1 :]),
                )
            )

        commands = [
            (line_number, scan_command)
            for line_number, command in commands
            for scan_command in resource_command_variants(command)
        ]

        for _, command in commands:
            for match in COMPOSE_COMMAND.finditer(command):
                arguments = command[match.end() :]
                if not COMPOSE_DOWN_OPERATION.search(arguments):
                    continue
                project_match = PROJECT_FLAG.search(arguments)
                if project_match is None:
                    continue
                project = project_match.group("project")
                if not value_is_job_scoped(resolution_source, project):
                    violations.append(
                        "Docker cleanup Compose project must derive from "
                        "github.run_id, github.run_attempt, and job identity"
                    )
                if "|| true" not in command:
                    violations.append(
                        "Docker cleanup commands must remain harmless after partial setup"
                    )
                resources.add(OwnedResource("compose", project))

            for match in DOCKER_CONTAINER_DESTRUCTIVE_COMMAND.finditer(command):
                names = removed_resource_names(command[match.end() :])
                for name in names:
                    if not value_is_job_scoped(resolution_source, name):
                        violations.append(
                            "Docker container cleanup must target a job-scoped name"
                        )
                    if match.group("operation") in {"rm", "remove"}:
                        resources.add(OwnedResource("container", name))
                if names and "|| true" not in command:
                    violations.append(
                        "Docker cleanup commands must remain harmless after partial setup"
                    )

            for match in DOCKER_RESOURCE_DESTRUCTIVE_COMMAND.finditer(command):
                names = removed_resource_names(command[match.end() :])
                for name in names:
                    if not value_is_job_scoped(resolution_source, name):
                        violations.append(
                            f"Docker {match.group('kind')} cleanup must target a "
                            "job-scoped name"
                        )
                    resources.add(OwnedResource(match.group("kind"), name))
                if names and "|| true" not in command:
                    violations.append(
                        "Docker cleanup commands must remain harmless after partial setup"
                    )

            for match in XARGS_DOCKER_REMOVE.finditer(command):
                kind = match.group("kind") or "container"
                cleanup_pipeline = re.split(
                    r"\s*(?:;|&&|\|\|)\s*",
                    command[: match.start()],
                )[-1]
                label_match = COMPOSE_PROJECT_LABEL.search(cleanup_pipeline)
                expected_listing = {
                    "container": r"\bdocker\s+ps\b",
                    "network": r"\bdocker\s+network\s+ls\b",
                    "volume": r"\bdocker\s+volume\s+ls\b",
                }[kind]
                if (
                    label_match is None
                    or re.search(expected_listing, cleanup_pipeline) is None
                ):
                    violations.append(
                        f"Docker {kind} cleanup must filter resources by its "
                        "job-scoped Compose project"
                    )
                    continue

                project = label_match.group("project")
                if not value_is_job_scoped(resolution_source, project):
                    violations.append(
                        f"Docker {kind} cleanup Compose project must derive from "
                        "github.run_id, github.run_attempt, and job identity"
                    )
                    continue
                if "|| true" not in command:
                    violations.append(
                        "Docker cleanup commands must remain harmless after partial setup"
                    )
                label_cleanup.setdefault(project, set()).add(kind)

        for project, kinds in label_cleanup.items():
            if kinds != {"container", "network", "volume"}:
                violations.append(
                    "Docker Compose label cleanup must remove owned containers, "
                    "networks, and volumes"
                )
                continue
            resources.add(OwnedResource("compose", project))

    return resources, violations


def cleanup_covers_resource(
    source: str,
    resource: OwnedResource,
    cleanup: set[OwnedResource],
) -> bool:
    owned_aliases = resource_aliases(source, resource.value)
    return any(
        candidate.kind == resource.kind
        and bool(owned_aliases & resource_aliases(source, candidate.value))
        for candidate in cleanup
    )


def inspect_resource_commands(
    path: Path,
    source: str,
    resolution_prelude: str = "",
) -> tuple[bool, bool, list[str], list[str]]:
    """Return whether source owns named resources and any policy violations."""

    owns_resources = False
    uses_compose = False
    violations: list[str] = []
    compose_resolution_sources: list[str] = []
    source_lines = source.splitlines()
    docker_option_errors: list[tuple[int, str]] = []

    for line_number, command in resource_scan_commands(source, docker_option_errors):
        resolution_source = "\n".join(
            (
                resolution_prelude,
                "\n".join(source_lines[:line_number]),
            )
        )
        if DOCKER_GLOBAL_PRUNE_COMMAND.search(command):
            violations.append(
                f"{path}:{line_number}: global Docker prune cannot prove job ownership"
            )

        for match in DOCKER_CONTAINER_DESTRUCTIVE_COMMAND.finditer(command):
            if starts_inside_shell_quote(command, match.start()):
                continue
            owns_resources = True
            operation = match.group("operation")
            for name in removed_resource_names(command[match.end() :]):
                if value_is_job_scoped(resolution_source, name):
                    continue
                label = "removal" if operation in {"rm", "remove"} else operation
                violations.append(
                    f"{path}:{line_number}: Docker container {label} must target "
                    "a job-scoped name"
                )

        for match in DOCKER_RESOURCE_DESTRUCTIVE_COMMAND.finditer(command):
            if starts_inside_shell_quote(command, match.start()):
                continue
            owns_resources = True
            kind = match.group("kind")
            for name in removed_resource_names(command[match.end() :]):
                if not value_is_job_scoped(resolution_source, name):
                    violations.append(
                        f"{path}:{line_number}: Docker {kind} removal must target "
                        "a job-scoped name"
                    )

        for match in COMPOSE_COMMAND.finditer(command):
            matched_prefix = command[match.start() : match.end()].lstrip()
            if starts_inside_shell_quote(
                command, match.start()
            ) and not matched_prefix.startswith(("(", "`")):
                continue

            arguments = command[match.end() :]
            if re.match(r"\s*(?:version|help)(?:\s|$)", arguments):
                continue

            owns_resources = True
            uses_compose = True
            if COMPOSE_CREATE_OPERATION.search(arguments) or (
                not COMPOSE_OPERATION.search(arguments) and "$@" in arguments
            ):
                compose_resolution_sources.append(resolution_source)
            project_match = PROJECT_FLAG.search(arguments)
            if project_match is None:
                violations.append(
                    f"{path}:{line_number}: docker compose must set -p/--project-name"
                )
            elif not value_is_job_scoped(
                resolution_source,
                project_match.group("project"),
            ):
                violations.append(
                    f"{path}:{line_number}: docker compose project must use a scoped "
                    "variable derived from github.run_id, github.run_attempt, and job identity"
                )

        for run_match in DOCKER_RUN_COMMAND.finditer(command):
            if starts_inside_shell_quote(command, run_match.start()):
                continue

            arguments = command[run_match.end() :]
            publishes = [
                match.group("publish") for match in DOCKER_PUBLISH.finditer(arguments)
            ]
            if publishes:
                owns_resources = True
            if any(
                not docker_publish_is_dynamic(resolution_source, publish)
                for publish in publishes
            ):
                violations.append(
                    f"{path}:{line_number}: Docker published host ports must be dynamic"
                )

            name_match = CONTAINER_NAME.search(arguments)
            if name_match is None:
                continue

            owns_resources = True
            if not value_is_job_scoped(
                resolution_source,
                name_match.group("name"),
            ):
                violations.append(
                    f"{path}:{line_number}: docker run --name must use a scoped variable "
                    "derived from github.run_id, github.run_attempt, and job identity"
                )

        for create_match in DOCKER_RESOURCE_CREATE_COMMAND.finditer(command):
            if starts_inside_shell_quote(command, create_match.start()):
                continue

            owns_resources = True
            kind = create_match.group("kind")
            name = created_resource_name(command[create_match.end() :])
            if name is None or not value_is_job_scoped(resolution_source, name):
                violations.append(
                    f"{path}:{line_number}: docker {kind} create name must use a scoped "
                    "variable derived from github.run_id, github.run_attempt, and job identity"
                )

    violations.extend(
        f"{path}:{line_number}: {error}" for line_number, error in docker_option_errors
    )

    if PYTHON_COMPOSE_COMMAND.search(source):
        owns_resources = True
        uses_compose = True
        resolution_source = "\n".join((resolution_prelude, source))
        compose_resolution_sources.append(resolution_source)
        project_match = PYTHON_PROJECT_VALUE.search(source)
        if project_match is None:
            violations.append(
                f"{path}: Python docker compose invocation must set -p/--project-name"
            )
        elif not value_is_job_scoped(
            resolution_source,
            python_project_scope_value(source, project_match.group("project")),
        ):
            violations.append(
                f"{path}: Python docker compose project must derive from "
                "github.run_id, github.run_attempt, and job identity"
            )

    return owns_resources, uses_compose, violations, compose_resolution_sources


def missing_scope_parts(source: str, parts: tuple[str, ...]) -> list[str]:
    return [part for part in parts if part not in source]


def literal_repository_script(root: Path, value: str) -> str:
    """Return a literal repository-relative script path when it exists."""

    value = value.strip().strip("\"'")
    path_match = LITERAL_SCRIPT_PATH.fullmatch(value)
    if path_match is None:
        return ""

    relative_path = path_match.group("path")
    return relative_path if (root / relative_path).is_file() else ""


def spans_overlap(first: tuple[int, int], second: tuple[int, int]) -> bool:
    return first[0] < second[1] and second[0] < first[1]


def workflow_run_blocks(job_source: str) -> list[str]:
    """Return only shell source from run steps in one workflow job."""

    run_blocks: list[str] = []
    for step in workflow_step_blocks(job_source):
        lines = step.splitlines()
        for index, line in enumerate(lines):
            run_match = re.match(
                r"^(?P<indent>\s+)(?P<list>-\s+)?run:\s*(?P<value>.*)$",
                line,
            )
            if run_match is None:
                continue

            value = run_match.group("value")
            if value not in {"|", "|-", "|+", ">", ">-", ">+"}:
                run_blocks.append(value)
                break

            property_indent = len(run_match.group("indent")) + (
                2 if run_match.group("list") else 0
            )
            body: list[str] = []
            for body_line in lines[index + 1 :]:
                if (
                    body_line.strip()
                    and len(body_line) - len(body_line.lstrip()) <= property_indent
                ):
                    break
                body.append(body_line)

            source = textwrap.dedent("\n".join(body))
            if value.startswith(">"):
                source = " ".join(part.strip() for part in source.splitlines())
            run_blocks.append(source)
            break

    return run_blocks


def contains_shell_expansion(value: str) -> bool:
    """Return whether shell text evaluates dynamic data or another command."""

    return SHELL_EXPANSION.search(re.sub(r"'[^']*'", "", value)) is not None


def argument_contains_repository_script(root: Path, value: str) -> bool:
    """Return whether command arguments contain an existing local script token."""

    return any(
        literal_repository_script(root, match.group("path"))
        for pattern in (
            WORKFLOW_LOCAL_SCRIPT_TOKEN,
            WORKFLOW_EMBEDDED_SCRIPT_TOKEN,
        )
        for match in pattern.finditer(value)
    )


def is_assignment_word(value: str) -> bool:
    return re.match(r"^[A-Za-z_][A-Za-z0-9_]*(?:\+)?=", value) is not None


def shell_group_end(source: str, opening_parenthesis: int) -> int:
    """Return the offset after a balanced shell group, or the source end."""

    depth = 1
    single_quoted = False
    double_quoted = False
    escaped = False
    for offset in range(opening_parenthesis + 1, len(source)):
        character = source[offset]
        if escaped:
            escaped = False
        elif character == "\\" and not single_quoted:
            escaped = True
        elif character == "'" and not double_quoted:
            single_quoted = not single_quoted
        elif character == '"' and not single_quoted:
            double_quoted = not double_quoted
        elif not single_quoted and not double_quoted:
            if character == "(":
                depth += 1
            elif character == ")":
                depth -= 1
                if depth == 0:
                    return offset + 1
    return len(source)


def source_without_shell_array_assignments(source: str) -> str:
    """Remove array data while leaving executable shell source intact."""

    masked = list(source)
    assignment = re.compile(
        r"(?m)^\s*[A-Za-z_][A-Za-z0-9_]*\+?=\(",
    )
    search_offset = 0
    while match := assignment.search(source, search_offset):
        end = shell_group_end(source, match.end() - 1)
        for offset in range(match.start(), end):
            if masked[offset] != "\n":
                masked[offset] = " "
        search_offset = end

    return "".join(masked)


def mask_github_expressions(source: str) -> str:
    """Keep Actions expressions dynamic without parsing their operators as shell."""

    return re.sub(r"\$\{\{.*?\}\}", "${GITHUB_EXPRESSION}", source)


def shell_evaluation_fragments(source: str) -> list[str]:
    """Return commands evaluated inside substitutions, including nested forms."""

    fragments: list[str] = []
    pending = [source]
    while pending:
        value = pending.pop()
        single_quoted = False
        double_quoted = False
        escaped = False
        offset = 0

        while offset < len(value):
            character = value[offset]
            if escaped:
                escaped = False
                offset += 1
                continue
            if character == "\\" and not single_quoted:
                escaped = True
                offset += 1
                continue
            if character == "'" and not double_quoted:
                single_quoted = not single_quoted
                offset += 1
                continue
            if character == '"' and not single_quoted:
                double_quoted = not double_quoted
                offset += 1
                continue
            if single_quoted:
                offset += 1
                continue

            if character == "`":
                end = offset + 1
                while end < len(value):
                    if value[end] == "`" and value[end - 1] != "\\":
                        fragment = value[offset + 1 : end]
                        fragments.append(fragment)
                        pending.append(fragment)
                        offset = end + 1
                        break
                    end += 1
                else:
                    offset += 1
                continue

            if (
                character in {"$", "<", ">"}
                and value[offset + 1 : offset + 2] == "("
                and value[offset + 2 : offset + 3] != "("
            ):
                end = shell_group_end(value, offset + 1)
                fragment = value[offset + 2 : end - 1]
                fragments.append(fragment)
                pending.append(fragment)
                offset = end
                continue

            offset += 1

    return fragments


def invocation_tokens(arguments: str) -> list[str]:
    """Tokenize one statically inspected shell invocation."""

    try:
        return shlex.split(arguments)
    except ValueError:
        return arguments.split()


def normalized_executable(program: str) -> str:
    """Normalize shell spellings that still execute the Docker client."""

    normalized = program.strip("\"'")
    if normalized.startswith("\\"):
        normalized = normalized[1:]
    if normalized.rsplit("/", maxsplit=1)[-1] == "docker":
        return "docker"
    return normalized


def is_modeled_docker_executable(program: str) -> bool:
    """Recognize literal Docker plus the existing shell helper alias."""

    return normalized_executable(program) in {"docker", "$docker_bin"}


def docker_subcommand_tokens(tokens: list[str]) -> tuple[list[str], str]:
    """Strip supported Docker global options or return a fail-closed reason."""

    offset = 0
    while offset < len(tokens):
        token = tokens[offset]
        if token == "--":
            return tokens[offset + 1 :], ""
        if not token.startswith("-"):
            return tokens[offset:], ""
        if token in DOCKER_GLOBAL_FLAG_OPTIONS:
            offset += 1
            continue
        if token in DOCKER_GLOBAL_OPTIONS_WITH_VALUES:
            if offset + 1 >= len(tokens):
                return [], f"Docker global option {token} requires a value"
            offset += 2
            continue

        option, separator, value = token.partition("=")
        if separator and option in DOCKER_GLOBAL_OPTIONS_WITH_VALUES:
            if not value:
                return [], f"Docker global option {option} requires a value"
            offset += 1
            continue

        return [], f"unrecognized Docker global option {token}"

    return [], ""


def arguments_contain_docker_executable(tokens: list[str]) -> bool:
    """Return whether an argument can be launched as the literal Docker client."""

    return any(normalized_executable(token) == "docker" for token in tokens)


def command_matches_prefix(tokens: list[str], *prefixes: tuple[str, ...]) -> bool:
    return any(tokens[: len(prefix)] == list(prefix) for prefix in prefixes)


def awk_program_is_non_launching(arguments: str) -> bool:
    """Allow dynamic AWK input only for a literal, non-executing program."""

    try:
        tokens = shlex.split(arguments, posix=False)
    except ValueError:
        return False

    offset = 0
    while offset < len(tokens):
        token = tokens[offset]
        if token in {"-f", "--file"} or token.startswith(("-f=", "--file=")):
            return False
        if token in {"-F", "-v"}:
            offset += 2
            continue
        if token.startswith(("-F", "-v")):
            offset += 1
            continue
        if token == "--":
            offset += 1
            break
        if token.startswith("-"):
            offset += 1
            continue
        break

    if offset >= len(tokens):
        return False
    program = tokens[offset]
    if len(program) >= 2 and program[0] == program[-1] == "'":
        program = program[1:-1]
    elif contains_shell_expansion(program):
        return False

    return (
        re.search(r"\bsystem\s*\(", program) is None
        and re.search(r"(?<!\|)\|(?!\|)", program) is None
    )


def docker_run_arguments_are_data(tokens: list[str]) -> bool:
    """Allow dynamic Docker run options/image, but not a dynamic container command."""

    if any(
        token.startswith("--entrypoint=") and contains_shell_expansion(token)
        for token in tokens
    ):
        return False
    options_with_values = {
        "--entrypoint",
        "--env",
        "--env-file",
        "--mount",
        "--name",
        "--network",
        "--publish",
        "--volume",
        "--workdir",
        "-e",
        "-p",
        "-v",
        "-w",
    }
    offset = 1
    while offset < len(tokens):
        token = tokens[offset]
        if token == "--":
            offset += 1
            break
        if token in options_with_values:
            if (
                token == "--entrypoint"
                and offset + 1 < len(tokens)
                and contains_shell_expansion(tokens[offset + 1])
            ):
                return False
            offset += 2
            continue
        if token.startswith("-"):
            offset += 1
            continue
        break

    if offset >= len(tokens):
        return False
    command_arguments = tokens[offset + 1 :]
    if not command_arguments:
        return True
    if contains_shell_expansion(command_arguments[0]):
        return False
    if command_arguments[0].startswith("-"):
        return True
    return all(not contains_shell_expansion(token) for token in command_arguments[1:])


def docker_compose_shape_is_modeled(tokens: list[str]) -> bool:
    """Allow the Compose operations used to inspect or own one scoped project."""

    options_with_values = {
        "--env-file",
        "--file",
        "--parallel",
        "--profile",
        "--project-directory",
        "--project-name",
        "-f",
        "-p",
    }
    offset = 1
    while offset < len(tokens):
        token = tokens[offset]
        if token in options_with_values:
            offset += 2
            continue
        if token.startswith("-"):
            offset += 1
            continue
        return token in {"down", "images", "logs", "port", "ps", "up"}
    return False


def helm_arguments_are_data(tokens: list[str]) -> bool:
    """Allow only Helm shapes whose dynamic arguments cannot launch helpers."""

    if command_matches_prefix(tokens, ("lint",), ("template",)):
        return "--post-renderer" not in tokens and not any(
            token.startswith("--post-renderer=") for token in tokens
        )
    if len(tokens) == 3 and tokens[0] == "push":
        return True
    if (
        len(tokens) == 6
        and tokens[:2] == ["registry", "login"]
        and tokens[3] == "--username"
        and tokens[5] == "--password-stdin"
    ):
        return True
    return len(tokens) == 3 and tokens[:2] == ["registry", "logout"]


def xargs_docker_cleanup_is_modeled(tokens: list[str]) -> bool:
    """Allow only the Docker cleanup shape checked by the ownership scanner."""

    tokens = list(tokens)
    while tokens[:1] and tokens[0] in {"-r", "--no-run-if-empty"}:
        tokens.pop(0)
    if tokens[:1] == ["--"]:
        tokens.pop(0)
    if not tokens or normalized_executable(tokens.pop(0)) != "docker":
        return False
    if any(contains_shell_expansion(token) for token in tokens):
        return False

    while tokens and re.fullmatch(r"\d*[<>].*", tokens[-1]):
        tokens.pop()
    return tokens in (
        ["rm", "-f"],
        ["network", "rm"],
        ["volume", "rm", "-f"],
    )


def xargs_docker_cleanup_is_scoped(
    source: str,
    program_offset: int,
    tokens: list[str],
    resolution_source: str,
) -> bool:
    """Return whether one xargs invocation is a scoped, tolerant cleanup."""

    if not xargs_docker_cleanup_is_modeled(tokens):
        return False

    line_start = source.rfind("\n", 0, program_offset) + 1
    line_end = source.find("\n", program_offset)
    if line_end < 0:
        line_end = len(source)
    command = source[line_start:line_end]
    local_offset = program_offset - line_start
    match = next(
        (
            candidate
            for candidate in XARGS_DOCKER_REMOVE.finditer(command)
            if candidate.start() <= local_offset < candidate.end()
        ),
        None,
    )
    if match is None or "|| true" not in command:
        return False

    cleanup_pipeline = command[: match.start()]
    label_match = COMPOSE_PROJECT_LABEL.search(cleanup_pipeline)
    kind = match.group("kind") or "container"
    expected_listing = {
        "container": r"\bdocker\s+ps\b",
        "network": r"\bdocker\s+network\s+ls\b",
        "volume": r"\bdocker\s+volume\s+ls\b",
    }[kind]
    return (
        label_match is not None
        and re.search(expected_listing, cleanup_pipeline) is not None
        and value_is_job_scoped(resolution_source, label_match.group("project"))
    )


def command_arguments_are_modeled(
    root: Path,
    program: str,
    tokens: list[str],
    raw_arguments: str,
) -> bool:
    """Return whether an exact command shape has no unchecked execution position."""

    if program in NON_LAUNCHING_DATA_COMMANDS:
        return True
    if program == "awk":
        return awk_program_is_non_launching(raw_arguments)
    if program == "ct":
        return command_matches_prefix(tokens, ("lint",))
    if program == "helm":
        return helm_arguments_are_data(tokens)
    if program == "kind":
        return command_matches_prefix(tokens, ("export", "kubeconfig"))
    if program in {"pip", "pip3"}:
        return command_matches_prefix(tokens, ("install",)) and all(
            not contains_shell_expansion(token)
            or re.fullmatch(
                r"durable-workflow==\$\{PYTHON_SDK_VERSION\}",
                token,
            )
            for token in tokens[1:]
        )
    if program == "php":
        return (
            len(tokens) == 2
            and tokens[0] == "scripts/ci/check-worker-openapi-evolution.php"
            and (root / tokens[0]).is_file()
            and tokens[1] in {"$CORPUS_BASE_REF", "$OPENAPI_BASE_REF"}
        )
    if program == "git":
        while tokens[:1] == ["-C"] and len(tokens) >= 2:
            tokens = tokens[2:]
        return command_matches_prefix(tokens, ("rev-list",), ("rev-parse",))
    if program == "gh":
        return command_matches_prefix(
            tokens,
            ("api",),
            ("release", "create"),
            ("release", "view"),
            ("run", "rerun"),
            ("workflow", "run"),
        )
    if is_modeled_docker_executable(program):
        tokens, global_option_error = docker_subcommand_tokens(tokens)
        if global_option_error:
            return False
        if tokens[:1] == ["compose"]:
            return docker_compose_shape_is_modeled(tokens)
        if tokens[:1] == ["run"]:
            return docker_run_arguments_are_data(tokens)
        if tokens[:1] == ["build"]:
            return tokens[-1:] == ["."] and all(
                not contains_shell_expansion(token) or token == "${build_args[@]}"
                for token in tokens[1:]
            )
        return command_matches_prefix(
            tokens,
            ("container", "kill"),
            ("container", "remove"),
            ("container", "rm"),
            ("container", "stop"),
            ("inspect",),
            ("kill",),
            ("logs",),
            ("network", "create"),
            ("network", "ls"),
            ("network", "remove"),
            ("network", "rm"),
            ("ps",),
            ("rm",),
            ("stop",),
            ("volume", "create"),
            ("volume", "ls"),
            ("volume", "remove"),
            ("volume", "rm"),
        )
    return False


def unwrap_command_launcher(
    program: str,
    tokens: list[str],
) -> tuple[str, list[str], bool]:
    """Resolve transparent command launchers without accepting command strings."""

    tokens = list(tokens)
    command_string = False
    program = normalized_executable(program)

    while True:
        if program == "builtin":
            if tokens[:1] == ["--"]:
                tokens.pop(0)
            if not tokens:
                return "", [], command_string
            builtin = normalized_executable(tokens[0])
            if builtin not in {"command", "exec"}:
                break
            tokens.pop(0)
            program = builtin
            continue
        if program == "command":
            while tokens and tokens[0].startswith("-"):
                option = tokens.pop(0)
                if option in {"-v", "-V"}:
                    return "", [], False
        elif program == "exec":
            while tokens and tokens[0].startswith("-"):
                option = tokens.pop(0)
                if option == "-a" and tokens:
                    tokens.pop(0)
        elif program == "env":
            while tokens:
                option = tokens[0]
                if option == "--":
                    tokens.pop(0)
                    break
                if option in {"-S", "--split-string"} or option.startswith(
                    "--split-string="
                ):
                    command_string = True
                    return "", [], command_string
                if option in {"-u", "--unset", "-C", "--chdir"}:
                    tokens.pop(0)
                    if tokens:
                        tokens.pop(0)
                    continue
                if option.startswith(("--unset=", "--chdir=")):
                    tokens.pop(0)
                    continue
                if option.startswith("-"):
                    tokens.pop(0)
                    continue
                if re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*=.*", option):
                    tokens.pop(0)
                    continue
                break
        elif program == "nohup":
            if tokens and tokens[0] == "--":
                tokens.pop(0)
            elif tokens and tokens[0].startswith("-"):
                return "", [], False
        elif program == "time":
            while tokens and tokens[0].startswith("-"):
                tokens.pop(0)
        else:
            break

        if not tokens:
            return "", [], command_string
        program = normalized_executable(tokens.pop(0))

    return program, tokens, command_string


def resource_command_variants(
    command: str,
    docker_option_errors: list[str] | None = None,
) -> list[str]:
    """Return raw and launcher-normalized forms used for Docker resource scans."""

    variants = [command]
    for invocation in WORKFLOW_COMMAND_INVOCATION.finditer(command):
        if starts_inside_shell_quote(command, invocation.start()):
            continue

        raw_program = invocation.group("program")
        program, tokens, command_string = unwrap_command_launcher(
            raw_program,
            invocation_tokens(invocation.group("arguments")),
        )
        if command_string or not is_modeled_docker_executable(program):
            continue

        subcommand_tokens, global_option_error = docker_subcommand_tokens(tokens)
        if global_option_error:
            if (
                docker_option_errors is not None
                and global_option_error not in docker_option_errors
            ):
                docker_option_errors.append(global_option_error)
            continue

        normalized = (
            shlex.join(["docker", *subcommand_tokens]) + command[invocation.end() :]
        )
        if normalized not in variants:
            variants.append(normalized)

    return variants


def resource_scan_commands(
    source: str,
    docker_option_errors: list[tuple[int, str]] | None = None,
) -> list[tuple[int, str]]:
    """Return logical commands plus Docker commands behind transparent launchers."""

    commands: list[tuple[int, str]] = []
    for line_number, command in logical_shell_lines(source):
        command_errors: list[str] = []
        commands.extend(
            (line_number, scan_command)
            for scan_command in resource_command_variants(command, command_errors)
        )
        if docker_option_errors is not None:
            docker_option_errors.extend(
                (line_number, error) for error in command_errors
            )
    return commands


def destructive_placement_violations(
    path: Path,
    source: str,
    cleanup_step: bool,
) -> list[str]:
    """Reject destructive commands outside an unconditional cleanup context."""

    violations: list[str] = []
    if cleanup_step:
        return violations

    for _, command in resource_scan_commands(source):
        if any(
            COMPOSE_DOWN_OPERATION.search(command[match.end() :])
            for match in COMPOSE_COMMAND.finditer(command)
        ):
            violations.append(
                f"{path}: Docker Compose down must run in an if: always() step"
            )

        if any(
            match.group("operation") in {"rm", "remove"}
            for match in DOCKER_CONTAINER_DESTRUCTIVE_COMMAND.finditer(command)
        ):
            violations.append(
                f"{path}: Docker container removal must run in an if: always() step"
            )

        for match in DOCKER_RESOURCE_DESTRUCTIVE_COMMAND.finditer(command):
            violations.append(
                f"{path}: Docker {match.group('kind')} removal must run in an "
                "if: always() step"
            )

    return list(dict.fromkeys(violations))


def workflow_destructive_placement_violations(
    root: Path,
    path: Path,
    job_source: str,
) -> list[str]:
    """Propagate each workflow step's cleanup context into invoked helpers."""

    violations: list[str] = []
    for step in workflow_step_blocks(job_source):
        cleanup_step = bool(re.search(r"(?m)^\s+(?:-\s+)?if:\s*.*always\(\)", step))
        violations.extend(destructive_placement_violations(path, step, cleanup_step))

        referenced_scripts, _ = workflow_script_references(root, step)
        inspected_scripts: set[Path] = set()
        for relative_script in referenced_scripts:
            for script, script_source in collect_script_tree(
                root,
                root / relative_script,
                inspected_scripts,
            ):
                violations.extend(
                    destructive_placement_violations(
                        script,
                        script_source,
                        cleanup_step,
                    )
                )

    return list(dict.fromkeys(violations))


def invoked_script_value(program: str, arguments: str) -> str:
    """Return a statically resolvable helper path or an unsafe variable target."""

    program, tokens, _ = unwrap_command_launcher(
        program,
        invocation_tokens(arguments),
    )
    if not program:
        return ""
    if referenced_variables(program):
        return program
    if LITERAL_SCRIPT_PATH.fullmatch(program.strip("\"'")):
        return program
    if program not in {"bash", "sh", "python", "python3", "source", "."}:
        return ""

    while tokens and tokens[0].startswith("-"):
        option = tokens.pop(0)
        if program.startswith("python") and option in {"-c", "-m"}:
            return ""

    return tokens[0] if tokens else ""


def invokes_shell_command_string(program: str, arguments: str) -> bool:
    """Return whether a shell receives its command through a string."""

    program, tokens, launcher_command_string = unwrap_command_launcher(
        program,
        invocation_tokens(arguments),
    )
    if launcher_command_string:
        return True
    if program not in {"bash", "sh"}:
        return False

    while tokens:
        option = tokens.pop(0)
        if option == "--" or not option.startswith(("-", "+")):
            return False
        if option.startswith("-") and "c" in option[1:]:
            return True
        if option in {"-o", "+o", "-O"} and tokens:
            tokens.pop(0)

    return False


def unclassified_dynamic_invocations(
    root: Path,
    job_source: str,
) -> list[str]:
    """Reject dynamic arguments to commands not proven to consume data only."""

    violations: list[str] = []
    run_blocks = (
        (
            run_source,
            re.search(r"(?m)^\s+(?:-\s+)?if:\s*.*always\(\)", step),
        )
        for step in workflow_step_blocks(job_source)
        for run_source in workflow_run_blocks(step)
    )
    for run_source, cleanup_step in run_blocks:
        run_source = mask_github_expressions(run_source)
        invocation_sources = [
            source_without_shell_array_assignments(run_source),
            *shell_evaluation_fragments(run_source),
        ]
        for invocation_source in invocation_sources:
            invocation_source = "\n".join(
                command
                for _, command in logical_shell_lines(
                    source_without_heredoc_bodies(invocation_source)
                )
            )
            for invocation in WORKFLOW_COMMAND_INVOCATION.finditer(invocation_source):
                if starts_inside_shell_quote(
                    invocation_source,
                    invocation.start(),
                ):
                    continue
                if (
                    invocation_source[invocation.start()] == "("
                    and invocation.start() > 0
                    and invocation_source[invocation.start() - 1] in {"=", "+"}
                ):
                    continue

                raw_program = invocation.group("program")
                raw_arguments = invocation.group("arguments")
                if is_assignment_word(raw_program):
                    continue

                program, tokens, command_string = unwrap_command_launcher(
                    raw_program.strip("\"'"),
                    invocation_tokens(raw_arguments),
                )
                normalized_program = program.strip("\"'")
                if not normalized_program or command_string:
                    continue
                if normalized_program in SHELL_GRAMMAR_WORDS:
                    continue
                if command_arguments_are_modeled(
                    root,
                    normalized_program,
                    tokens,
                    raw_arguments,
                ):
                    continue
                if literal_repository_script(root, normalized_program):
                    continue
                if (
                    cleanup_step
                    and normalized_program == "xargs"
                    and xargs_docker_cleanup_is_scoped(
                        invocation_source,
                        invocation.start("program"),
                        tokens,
                        job_source,
                    )
                ):
                    continue

                if normalized_program in {
                    "bash",
                    "sh",
                    "python",
                    "python3",
                    "source",
                    ".",
                }:
                    script_value = invoked_script_value(raw_program, raw_arguments)
                    if literal_repository_script(root, script_value):
                        continue
                    tokens = invocation_tokens(raw_arguments)
                    if (
                        normalized_program in {"python", "python3"}
                        and len(tokens) >= 2
                        and tokens[:2] in (["-m", "py_compile"], ["-m", "unittest"])
                    ):
                        continue

                if not (
                    contains_shell_expansion(raw_arguments)
                    or argument_contains_repository_script(root, raw_arguments)
                    or arguments_contain_docker_executable(tokens)
                ):
                    continue
                violation = (
                    "unclassified command "
                    f"{normalized_program or raw_program} cannot safely consume "
                    "Docker executable, dynamic, or repository-script arguments"
                )
                if violation not in violations:
                    violations.append(violation)

    return violations


def workflow_script_references(
    root: Path,
    job_source: str,
) -> tuple[list[str], list[str]]:
    """Return literal helper targets and reject every unproven script position."""

    invocation_source = "\n".join(
        command
        for _, command in logical_shell_lines(source_without_heredoc_bodies(job_source))
    )
    repository_tokens = []
    seen_token_spans: list[tuple[int, int]] = []
    for pattern in (
        WORKFLOW_LOCAL_SCRIPT_TOKEN,
        WORKFLOW_EMBEDDED_SCRIPT_TOKEN,
    ):
        for match in pattern.finditer(invocation_source):
            token_span = match.span("path")
            if any(spans_overlap(token_span, span) for span in seen_token_spans):
                continue
            path = literal_repository_script(root, match.group("path"))
            if not path:
                continue
            repository_tokens.append((match, path))
            seen_token_spans.append(token_span)
    references: set[str] = set()
    allowed_script_spans: list[tuple[int, int]] = []
    violations = unclassified_dynamic_invocations(root, job_source)

    for reference in WORKFLOW_SCRIPT_REFERENCE.finditer(invocation_source):
        if (
            invocation_source[reference.start()] == "("
            and reference.start() > 0
            and invocation_source[reference.start() - 1] in {"=", "+"}
        ):
            continue
        path = literal_repository_script(root, reference.group(1))
        if not path:
            continue
        references.add(path)
        allowed_script_spans.append(reference.span(1))

    for invocation in MODELED_SCRIPT_DATA_INVOCATION.finditer(invocation_source):
        allowed_script_spans.extend(
            token.span("path")
            for token, _ in repository_tokens
            if invocation.start() <= token.start() and token.end() <= invocation.end()
        )

    for invocation in VARIABLE_COMMAND_INVOCATION.finditer(invocation_source):
        violations.append(
            "helper invocation must use a literal repository-relative path under "
            f"scripts/; variable command {invocation.group('target')} cannot be "
            "proven safe"
        )

    for _ in EVAL_INVOCATION.finditer(invocation_source):
        violations.append(
            "helper invocation must use a literal repository-relative path under "
            "scripts/; eval command strings cannot be proven safe"
        )

    for invocation in WORKFLOW_SCRIPT_INVOCATION.finditer(invocation_source):
        program = invocation.group("program")
        arguments = invocation.group("arguments")
        if invokes_shell_command_string(program, arguments):
            violations.append(
                "helper invocation must use a literal repository-relative path "
                "under scripts/; shell command strings cannot be proven safe"
            )
            continue

        value = invoked_script_value(
            program,
            arguments,
        )
        if not value:
            continue
        if referenced_variables(value):
            violations.append(
                "helper invocation must use a literal repository-relative path "
                f"under scripts/; variable target {value} cannot be proven safe"
            )
            continue
        path = literal_repository_script(root, value)
        if not path:
            continue
        references.add(path)
        for token, token_path in repository_tokens:
            if token_path != path:
                continue
            if invocation.start() <= token.start() and token.end() <= invocation.end():
                allowed_script_spans.append(token.span("path"))
                break

    for token, path in repository_tokens:
        if any(
            spans_overlap(token.span("path"), allowed_span)
            for allowed_span in allowed_script_spans
        ):
            continue
        violations.append(
            "helper invocation must use a literal repository-relative path under "
            f"scripts/; {path} is not in a statically proven execution position"
        )

    return sorted(references), violations


def collect_script_tree(
    root: Path,
    script: Path,
    inspected: set[Path],
) -> list[tuple[Path, str]]:
    script = script.resolve()
    if script in inspected or not script.is_file():
        return []
    inspected.add(script)

    source = script.read_text(encoding="utf-8")
    sources = [(script, source)]

    # A controller may execute a referenced repository script over SSH on an
    # isolated host. Inspect the controller itself, but do not attribute the
    # remote script's Docker resources to the local Actions job.
    if REMOTE_SCRIPT_BOUNDARY in source:
        return sources

    for reference in LOCAL_SCRIPT_REFERENCE.findall(source):
        referenced_path = (
            root / reference
            if reference.startswith("scripts/")
            else script.parent / reference
        )
        sources.extend(
            collect_script_tree(
                root,
                referenced_path,
                inspected,
            )
        )

    return sources


def workflow_job_blocks(source: str) -> list[tuple[str, str]]:
    jobs: list[tuple[str, str]] = []
    current_name = ""
    current_lines: list[str] = []
    in_jobs = False

    for line in source.splitlines():
        if line == "jobs:":
            in_jobs = True
            continue
        if not in_jobs:
            continue
        if line and not line.startswith((" ", "\t")):
            break

        job_match = re.match(r"^  ([A-Za-z_][A-Za-z0-9_-]*):\s*$", line)
        if job_match:
            if current_name:
                jobs.append((current_name, "\n".join(current_lines)))
            current_name = job_match.group(1)
            current_lines = [line]
        elif current_name:
            current_lines.append(line)

    if current_name:
        jobs.append((current_name, "\n".join(current_lines)))

    return jobs


def validate(root: Path) -> list[str]:
    violations: list[str] = []
    workflow_dir = root / ".github" / "workflows"
    workflows = sorted((*workflow_dir.glob("*.yml"), *workflow_dir.glob("*.yaml")))

    if not workflows:
        return [f"{workflow_dir}: no Actions workflows found"]

    for workflow in workflows:
        source = workflow.read_text(encoding="utf-8")
        for job_name, job_source in workflow_job_blocks(source):
            referenced_scripts, discovery_violations = workflow_script_references(
                root, job_source
            )
            violations.extend(
                f"{workflow} job {job_name}: {violation}"
                for violation in discovery_violations
            )
            script_sources: list[tuple[Path, str]] = []
            inspected_scripts: set[Path] = set()
            for relative_script in referenced_scripts:
                script = root / relative_script
                if not script.is_file():
                    continue
                script_sources.extend(
                    collect_script_tree(root, script, inspected_scripts)
                )

            resource_source = "\n".join(
                [job_source, *(script_source for _, script_source in script_sources)]
            )
            (
                owns_resources,
                uses_compose,
                command_violations,
                compose_resolution_sources,
            ) = inspect_resource_commands(workflow, job_source)
            violations.extend(command_violations)
            violations.extend(
                workflow_destructive_placement_violations(
                    root,
                    workflow,
                    job_source,
                )
            )

            script_owns_resources = False
            script_uses_compose = False
            for script, script_source in script_sources:
                (
                    script_owns,
                    script_compose,
                    script_violations,
                    script_compose_resolution_sources,
                ) = inspect_resource_commands(
                    script,
                    script_source,
                    job_source,
                )
                script_owns_resources = script_owns_resources or script_owns
                script_uses_compose = script_uses_compose or script_compose
                compose_resolution_sources.extend(script_compose_resolution_sources)
                violations.extend(script_violations)

            if script_owns_resources:
                script_scope_source = "\n".join(
                    script_source for _, script_source in script_sources
                )
                missing = missing_scope_parts(
                    script_scope_source,
                    SCRIPT_SCOPE_PARTS,
                )
                if missing:
                    violations.append(
                        f"{workflow} job {job_name}: Docker resource scope is missing "
                        f"{', '.join(missing)} in referenced scripts"
                    )

            owns_resources = owns_resources or script_owns_resources
            uses_compose = uses_compose or script_uses_compose
            if not owns_resources:
                continue

            missing = missing_scope_parts(job_source, WORKFLOW_SCOPE_PARTS)
            if missing:
                violations.append(
                    f"{workflow} job {job_name}: Docker resource scope is missing {', '.join(missing)}"
                )

            if FIXED_PORT_ASSIGNMENT.search(job_source):
                violations.append(
                    f"{workflow} job {job_name}: Docker published host port assignments must use 0"
                )

            if FIXED_COMPOSE_PORT.search(resource_source) or any(
                COMPOSE_FIXED_LONG_PORT.match(line.strip())
                for line in resource_source.splitlines()
            ):
                violations.append(
                    f"{workflow} job {job_name}: Compose published host ports must be dynamic"
                )

            if uses_compose and not DYNAMIC_PORT_EVIDENCE.search(resource_source):
                violations.append(
                    f"{workflow} job {job_name}: Compose jobs must explicitly use Docker-assigned or disabled host ports"
                )
            if uses_compose:
                violations.extend(
                    inspect_compose_files(
                        root,
                        resource_source,
                        compose_resolution_sources,
                    )
                )
                violations.extend(
                    inspect_generated_compose(
                        workflow,
                        job_source,
                        compose_resolution_sources,
                    )
                )
                for script, script_source in script_sources:
                    violations.extend(
                        inspect_generated_compose(
                            script,
                            script_source,
                            compose_resolution_sources,
                        )
                    )

            created = owned_resources(resource_source)
            cleaned, cleanup_violations = cleanup_resources(
                job_source,
                resource_source,
            )
            violations.extend(
                f"{workflow} job {job_name}: {violation}"
                for violation in cleanup_violations
            )
            for resource in sorted(
                created,
                key=lambda item: (item.kind, item.value),
            ):
                if cleanup_covers_resource(resource_source, resource, cleaned):
                    continue
                violations.append(
                    f"{workflow} job {job_name}: {resource.kind} resource "
                    f"{resource.value} needs matching harmless cleanup in an "
                    "if: always() step"
                )

    return violations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--root",
        type=Path,
        default=Path(__file__).resolve().parents[2],
        help="repository root to inspect",
    )
    args = parser.parse_args()

    violations = validate(args.root.resolve())
    if violations:
        print("Docker workflow isolation policy failed:", file=sys.stderr)
        for violation in violations:
            print(f"- {violation}", file=sys.stderr)
        return 1

    print("Docker workflow isolation policy passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
