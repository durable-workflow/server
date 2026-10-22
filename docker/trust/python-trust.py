"""Print every CA store a fresh venv's ssl module and pip would use."""
import os
import ssl

paths = ssl.get_default_verify_paths()
stores = [paths.cafile, paths.capath, paths.openssl_cafile, paths.openssl_capath]
try:
    from pip._vendor import certifi

    stores.append(certifi.where())
except ImportError:
    pass
for store in dict.fromkeys(stores):
    if store and os.path.exists(store):
        print(os.path.realpath(store))
