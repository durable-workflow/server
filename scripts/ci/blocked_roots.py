"""Refuse a CA bundle that still trusts a root withdrawn under GHSA-6q48-6c7w-9gw2.

Usage: python blocked_roots.py BUNDLE [BUNDLE ...]

Prints each bundle's root count and blocked subjects, and exits non-zero
when a bundle is empty or trusts a withdrawn root. Standard library only, so
it runs in any CI or container Python.
"""
import os
import re
import ssl
import sys
import tempfile

BLOCKED = ("AAA Certificate Services", "emSign ECC Root CA - G3")


def blocked_roots(path):
    with open(path, encoding="ascii", errors="replace") as bundle:
        certificates = re.findall(
            r"-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----", bundle.read(), re.S
        )
    counts = dict.fromkeys(BLOCKED, 0)
    for certificate in certificates:
        with tempfile.NamedTemporaryFile("w", suffix=".pem", delete=False) as handle:
            handle.write(certificate)
        try:
            subject = ssl._ssl._test_decode_cert(handle.name)["subject"]
        finally:
            os.unlink(handle.name)
        for name in (value for rdn in subject for key, value in rdn if key == "commonName"):
            if name in counts:
                counts[name] += 1
    return len(certificates), counts


def main(paths):
    status = 0
    for path in paths:
        total, counts = blocked_roots(path)
        print(f"{path}: {total} roots, blocked {counts}")
        if total == 0 or any(counts.values()):
            status = 1
    return status


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]) if len(sys.argv) > 1 else "Usage: blocked_roots.py BUNDLE [BUNDLE ...]")
