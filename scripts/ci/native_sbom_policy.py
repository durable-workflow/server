#!/usr/bin/env python3
"""Bounded SPDX schema/semantic observations; no scanner or publication authority.

The future generator/verifier must additionally bind these bytes to the actual
smoked child, pinned tool execution, provider artifact and signed predicate.
This library neither fetches resources nor admits an operation.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
from typing import Any
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent / "native-sbom"
RAW_LIMIT = 16 * 1024 * 1024
SCHEMA_SHA256 = "4126dc29f15e92feec92e9622f4939131ad58b125d6f578aa93e980a6e6f3212"
TOOL_CREATOR = "Tool: syft-1.55.0"
IDENTIFIER = re.compile(r"SPDXRef-[A-Za-z0-9.-]+\Z")
COLLECTION_LIMIT = 100000


class Refusal(ValueError):
    """Static diagnostics only; never emit untrusted package/file content."""


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


def parse(raw: bytes, limit: int = RAW_LIMIT) -> Any:
    require(type(raw) is bytes and 0 < len(raw) <= limit, "spdx_size")

    def pairs(items):
        result = {}
        for key, value in items:
            require(key not in result, "spdx_duplicate_member")
            result[key] = value
        return result

    def noninteger(_):
        raise Refusal("spdx_noninteger_number")

    try:
        return json.loads(raw.decode("utf-8", errors="strict"), object_pairs_hook=pairs,
                          parse_float=noninteger, parse_constant=noninteger)
    except (UnicodeError, ValueError, RecursionError) as error:
        if isinstance(error, Refusal):
            raise
        raise Refusal("spdx_json") from None


def canonical(value: Any) -> bytes:
    try:
        return json.dumps(value, ensure_ascii=False, allow_nan=False, sort_keys=True,
                          separators=(",", ":")).encode("utf-8")
    except (UnicodeError, ValueError, RecursionError):
        raise Refusal("spdx_canonical_json") from None


def schema_check(document: Any) -> None:
    # The source/schema checks here use an already-installed validator. A
    # future executable must first enforce the separately pinned dependency
    # closure; that closure is currently null and no executable is wired.
    from jsonschema import Draft201909Validator
    from referencing import Registry
    from referencing.exceptions import NoSuchResource

    with (ROOT / "spdx-schema-2.3.json").open("rb") as stream:
        raw = stream.read(131073)
    require(hashlib.sha256(raw).hexdigest() == SCHEMA_SHA256, "spdx_schema_source")
    schema = parse(raw, 131072)

    def no_remote(uri):
        raise NoSuchResource(ref=uri)

    validator = Draft201909Validator(schema, registry=Registry(retrieve=no_remote))
    # A fixed validator/schema ignores any instance-supplied $schema URL.
    require(next(validator.iter_errors(document), None) is None, "spdx_schema_shape")


def collection(document: dict[str, Any], key: str, nonempty: bool = False) -> list[dict[str, Any]]:
    value = document.get(key, [])
    require(type(value) is list and len(value) <= COLLECTION_LIMIT
            and (bool(value) or not nonempty) and all(type(item) is dict for item in value),
            "spdx_collection_shape")
    return value


def document(raw: bytes) -> dict[str, Any]:
    value = parse(raw)
    require(type(value) is dict, "spdx_document_shape")
    schema_check(value)
    # Upstream 2.3.1's schema declares only type=string for spdxVersion.
    require(value.get("spdxVersion") == "SPDX-2.3" and value.get("SPDXID") == "SPDXRef-DOCUMENT"
            and value.get("dataLicense") == "CC0-1.0", "spdx_document_identity")
    namespace = value.get("documentNamespace")
    require(type(namespace) is str and len(namespace) <= 2048
            and all(0x21 <= ord(char) <= 0x7e for char in namespace), "spdx_namespace_text")
    try:
        uri = urlsplit(namespace)
        valid_uri = (uri.scheme == "https" and bool(uri.hostname) and not uri.username
                     and not uri.password and not uri.fragment and bool(uri.path))
    except ValueError:
        valid_uri = False
    require(valid_uri, "spdx_namespace_absolute")
    creators = value.get("creationInfo", {}).get("creators")
    require(type(creators) is list and creators.count(TOOL_CREATOR) == 1
            and all(type(item) is str for item in creators)
            and all(item == TOOL_CREATOR for item in creators if item.startswith("Tool:")),
            "spdx_creator_version")
    packages = collection(value, "packages", nonempty=True)
    files = collection(value, "files")
    snippets = collection(value, "snippets")
    relationships = collection(value, "relationships", nonempty=True)
    ids, package_ids, file_ids = {"SPDXRef-DOCUMENT"}, set(), set()
    for kind, members in (("packages", packages), ("files", files), ("snippets", snippets)):
        for member in members:
            identity = member.get("SPDXID")
            require(type(identity) is str and IDENTIFIER.fullmatch(identity) is not None
                    and identity not in ids, "spdx_duplicate_or_invalid_id")
            ids.add(identity)
            if kind == "packages":
                package_ids.add(identity)
            elif kind == "files":
                file_ids.add(identity)
    edges = set()
    described, linked_files, file_parents = set(), set(), set()
    for edge in relationships:
        source, relation, target = (edge.get(key) for key in
                                    ("spdxElementId", "relationshipType", "relatedSpdxElement"))
        require(type(source) is str and type(target) is str and type(relation) is str
                and source in ids and target in ids, "spdx_relationship_target")
        key = (source, relation, target)
        require(key not in edges, "spdx_duplicate_relationship")
        edges.add(key)
        if source == "SPDXRef-DOCUMENT" and relation == "DESCRIBES" and target in package_ids:
            described.add(target)
        if source in package_ids and relation == "CONTAINS" and target in file_ids:
            linked_files.add(target)
            file_parents.add(source)
    require(bool(described), "spdx_image_description_missing")
    # No claim of complete file enumeration is invented when Syft explicitly
    # marks a package filesAnalyzed=false. Declared files/true coverage must link.
    require(linked_files == file_ids, "spdx_unlinked_file")
    require(all(package.get("filesAnalyzed") is not True or package["SPDXID"] in file_parents
                for package in packages), "spdx_analyzed_package_without_files")
    return {"raw_bytes": len(raw), "raw_sha256": hashlib.sha256(raw).hexdigest(),
            "canonical_sha256": hashlib.sha256(canonical(value)).hexdigest(),
            "namespace": namespace, "package_count": len(packages), "file_count": len(files),
            "relationship_count": len(relationships), "predicate": value}


def predicate_matches(raw: bytes, signed_predicate: bytes) -> dict[str, Any]:
    observed = document(raw)
    signed = document(signed_predicate)
    require(observed["canonical_sha256"] == signed["canonical_sha256"], "spdx_signed_predicate_mismatch")
    return observed


def distinct_platform_documents(amd64: bytes, arm64: bytes) -> tuple[dict[str, Any], dict[str, Any]]:
    pair = document(amd64), document(arm64)
    require(pair[0]["namespace"] != pair[1]["namespace"], "spdx_namespace_reused")
    return pair


if __name__ == "__main__":
    raise SystemExit("native_sbom_policy is a source library; executable/dependency closure is not wired")
