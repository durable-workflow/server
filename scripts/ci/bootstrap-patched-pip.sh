#!/bin/sh
# Installs the hash-pinned pip 26.2.2 into the given venv before its first
# networked pip install, refuses a pip whose CA bundle still trusts a root
# withdrawn under GHSA-6q48-6c7w-9gw2, and confines the venv's later pip
# runs to that verified bundle. The wheel's SHA-256 pin keeps its own
# download safe over any trust path. This is the container counterpart of the
# central bootstrap-patched-pip action.
#
# pip normally trusts the operating system store as well as its bundle
# (truststore), and several images still ship an affected store. The venv's
# pip.conf therefore selects pip's legacy-certs mode, which trusts only the
# verified bundle.
#
# Usage: bootstrap-patched-pip.sh VENV_PYTHON   (for example "$venv/bin/python")
set -eu

python=${1:?Usage: bootstrap-patched-pip.sh VENV_PYTHON}
here=$(dirname "$0")

"$python" -I -m pip --isolated --disable-pip-version-check install --no-deps --upgrade --quiet --no-cache-dir \
    "pip @ https://files.pythonhosted.org/packages/3f/06/3e4420302363fbb71be3c7f35ce042f54bfca330e3e66b3a2e3c4cca579f/pip-26.2.2-py3-none-any.whl#sha256=fd7f4dfa8190e014013b9f08f3e973f4e4c26ffd179a204895533a7254813a70"

version=$("$python" -I -c 'import pip; print(pip.__version__)')
if [ "$version" != 26.2.2 ]; then
    echo "pip 26.2.2 is required; found $version" >&2
    exit 1
fi
bundle=$("$python" -I -c 'from pip._vendor import certifi; print(certifi.where())')
"$python" -I "$here/blocked_roots.py" "$bundle"

prefix=$("$python" -I -c 'import sys; print(sys.prefix if sys.prefix != sys.base_prefix else "")')
if [ -z "$prefix" ]; then
    echo "$python is not a venv; its pip would still trust the operating system store." >&2
    exit 1
fi
printf '[global]\nuse-deprecated = legacy-certs\n' > "$prefix/pip.conf"
