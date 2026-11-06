"""Synthetic OCI tar graph controls; no Docker export, scan or extraction."""
import copy
import hashlib
import io
import json
from pathlib import Path
import sys
import tarfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[3] / "scripts/ci"))
import native_sbom_archive as A


def encoded(value):
    return json.dumps(value, separators=(",", ":")).encode()


def digest(raw):
    return "sha256:" + hashlib.sha256(raw).hexdigest()


def blob_name(raw):
    return "blobs/sha256/" + digest(raw)[7:]


def descriptor(raw, media_type):
    return {"mediaType": media_type, "digest": digest(raw), "size": len(raw)}


def fixture(platform="linux/amd64", mutate_manifest=None, mutate_index=None):
    layers = [b"synthetic layer one", b"synthetic layer two"]
    config = encoded({"os": "linux", "architecture": platform.split("/")[1],
                      "rootfs": {"type": "layers", "diff_ids": [digest(raw) for raw in layers]}})
    manifest = {"schemaVersion": 2, "mediaType": A.MANIFEST_TYPE,
                "config": descriptor(config, A.CONFIG_TYPE),
                "layers": [descriptor(raw, A.LAYER_TYPE) for raw in layers]}
    if mutate_manifest:
        mutate_manifest(manifest)
    manifest_raw = encoded(manifest)
    selected = {**descriptor(manifest_raw, A.MANIFEST_TYPE),
                "platform": {"os": "linux", "architecture": platform.split("/")[1]}}
    index = {"schemaVersion": 2, "mediaType": "application/vnd.oci.image.index.v1+json", "manifests": [selected]}
    if mutate_index:
        mutate_index(index)
    files = {"oci-layout": b'{"imageLayoutVersion":"1.0.0"}', "index.json": encoded(index),
             blob_name(config): config, blob_name(manifest_raw): manifest_raw,
             **{blob_name(raw): raw for raw in layers}}
    return files, digest(config)


def archive(files, extras=()):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode="w", format=tarfile.USTAR_FORMAT) as tar:
        for name, raw in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(raw)
            tar.addfile(info, io.BytesIO(raw))
        for info, raw in extras:
            tar.addfile(info, io.BytesIO(raw))
    return output.getvalue()


class SbomArchiveTest(unittest.TestCase):
    def test_buildkit_root_creation_metadata_does_not_replace_config_authority(self):
        def buildkit_root(index):
            item = index["manifests"][0]
            del item["platform"]
            item["annotations"] = {"org.opencontainers.image.created": "2026-11-05T00:00:00Z"}

        for platform in ("linux/amd64", "linux/arm64"):
            files, config = fixture(platform, mutate_index=buildkit_root)
            raw = archive(files)
            with self.subTest(platform=platform):
                self.assertEqual(A.oci_archive_identity(io.BytesIO(raw), config, platform)["platform"], platform)
                foreign = "linux/arm64" if platform == "linux/amd64" else "linux/amd64"
                with self.assertRaisesRegex(A.Refusal, "config_platform"):
                    A.oci_archive_identity(io.BytesIO(raw), config, foreign)

    def test_export_annotations_refuse_unknown_names_types_and_invalid_calendar(self):
        bad = [{"org.opencontainers.image.ref.name": "mutable:latest"},
               {"org.opencontainers.image.created": None},
               {"org.opencontainers.image.created": "2026-02-30T00:00:00Z"},
               {"org.opencontainers.image.created": "2026-11-05T00:00:00Zsuffix"},
               {"org.opencontainers.image.created": "2026-11-05T00:00:00Z", "foreign": "value"}]
        for annotations in bad:
            files, config = fixture(mutate_index=lambda index: index["manifests"][0].update(annotations=annotations))
            with self.subTest(annotations=annotations), self.assertRaisesRegex(A.Refusal, "export_annotation"):
                A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")

    def test_both_platforms_bind_complete_config_and_ordered_layers(self):
        for platform in ("linux/amd64", "linux/arm64"):
            files, config = fixture(platform)
            raw = archive(files)
            with self.subTest(platform=platform):
                observed = A.oci_archive_identity(io.BytesIO(raw), config, platform)
                self.assertEqual(observed["image_config_digest"], config)
                self.assertEqual(observed["archive_bytes"], len(raw))
                self.assertEqual(observed["archive_sha256"], hashlib.sha256(raw).hexdigest())
                self.assertEqual(observed["layer_count"], 2)
                self.assertNotEqual(observed["archive_manifest_digest"], config)

    def test_smoked_config_platform_and_layer_order_cannot_be_substituted(self):
        files, config = fixture()
        for expected, platform in (("sha256:" + "0" * 64, "linux/amd64"), (config, "linux/arm64")):
            with self.subTest(expected=expected, platform=platform), self.assertRaises(A.Refusal):
                A.oci_archive_identity(io.BytesIO(archive(files)), expected, platform)
        files, config = fixture(mutate_manifest=lambda manifest: manifest["layers"].reverse())
        with self.assertRaisesRegex(A.Refusal, "layer_diff_id"):
            A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")
        files, config = fixture(mutate_manifest=lambda manifest: manifest["layers"][0].update(mediaType="application/vnd.oci.image.layer.v1.tar+gzip"))
        with self.assertRaisesRegex(A.Refusal, "descriptor"):
            A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")

    def test_matching_config_never_hides_changed_layer_bytes(self):
        files, config = fixture()
        name = blob_name(b"synthetic layer one")
        files[name] = b"synthetic layer evil"
        with self.assertRaisesRegex(A.Refusal, "blob_digest"):
            A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")

    def test_extra_images_members_and_wrong_descriptor_sizes_refuse(self):
        changes = [lambda index: index["manifests"].append(copy.deepcopy(index["manifests"][0])),
                   lambda index: index["manifests"][0].update(size=1),
                   lambda index: index["manifests"][0].update(digest="sha256:" + "f" * 64),
                   lambda index: index["manifests"][0]["platform"].update(variant="v9")]
        for change in changes:
            files, config = fixture(mutate_index=change)
            with self.subTest(change=change), self.assertRaises(A.Refusal):
                A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")
        files, config = fixture()
        files[blob_name(b"unreferenced")] = b"unreferenced"
        with self.assertRaisesRegex(A.Refusal, "unreferenced_member"):
            A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")

    def test_duplicate_paths_links_traversal_and_extensions_refuse(self):
        files, config = fixture()
        duplicate = tarfile.TarInfo("oci-layout")
        duplicate.size = 2
        extras = [(duplicate, b"{}")]
        for name, kind in (("../outside", tarfile.REGTYPE), ("/outside", tarfile.REGTYPE),
                           ("blobs/sha256/" + "0" * 64, tarfile.SYMTYPE),
                           ("blobs/sha256/" + "0" * 64, tarfile.LNKTYPE),
                           ("pax-header", tarfile.XHDTYPE), ("sparse", tarfile.GNUTYPE_SPARSE)):
            info = tarfile.TarInfo(name)
            info.type = kind
            extras.append((info, b""))
        for info, raw in extras:
            with self.subTest(name=info.name, kind=info.type), self.assertRaises(A.Refusal):
                A.oci_archive_identity(io.BytesIO(archive(files, [(info, raw)])), config, "linux/amd64")

    def test_truncation_nonzero_trailing_and_resource_bounds_refuse(self):
        files, config = fixture()
        raw = archive(files)
        for changed in (raw[:100], raw[:1024], raw + b"not-padding"):
            with self.subTest(size=len(changed)), self.assertRaises(A.Refusal):
                A.oci_archive_identity(io.BytesIO(changed), config, "linux/amd64")
        with patch.object(A, "ARCHIVE_LIMIT", 100), self.assertRaisesRegex(A.Refusal, "size"):
            A.oci_archive_identity(io.BytesIO(raw), config, "linux/amd64")
        with patch.object(A, "MEMBER_LIMIT", 2), self.assertRaisesRegex(A.Refusal, "count"):
            A.oci_archive_identity(io.BytesIO(raw), config, "linux/amd64")
        with patch.object(A.time, "monotonic", side_effect=[0, 31]), self.assertRaisesRegex(A.Refusal, "deadline"):
            A.oci_archive_identity(io.BytesIO(raw), config, "linux/amd64")

    def test_archive_metadata_requires_original_strict_json(self):
        files, config = fixture()
        for changed in (b'{"imageLayoutVersion":"bad","imageLayoutVersion":"1.0.0"}',
                        b'{"imageLayoutVersion":"1.0.0"}{}', b'{"imageLayoutVersion":"\xff"}'):
            files["oci-layout"] = changed
            with self.subTest(changed=changed), self.assertRaises(A.Refusal):
                A.oci_archive_identity(io.BytesIO(archive(files)), config, "linux/amd64")

    def test_changed_metadata_between_hashing_and_graph_read_refuses(self):
        files, config = fixture()

        class ChangedInput(io.BytesIO):
            changed = False

            def seek(self, offset, whence=0):
                result = super().seek(offset, whence)
                if offset == 512 and not self.changed:
                    self.changed = True
                    super().write(b'{"imageLayoutVersion":"9.0.0"}')
                    super().seek(offset)
                return result

        with self.assertRaisesRegex(A.Refusal, "archive_changed"):
            A.oci_archive_identity(ChangedInput(archive(files)), config, "linux/amd64")


if __name__ == "__main__":
    unittest.main()
