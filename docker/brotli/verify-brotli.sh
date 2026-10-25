#!/bin/sh
# Refuses an image whose Brotli decoders lack the CVE-2026-111497 fix. The
# system decoder must be Debian's libbrotli1 at or above the trixie-security
# fix named in `version`, be the only libbrotlidec on the image and be the one
# every linked object loads. Node must be a release carrying its own fixed
# copy. No malformed stream is used: the fix's new error code identifies the
# fixed decoder, and a valid stream must still round trip.
set -eu

here=$(dirname "$(readlink -f "$0")")
minimum=$(cat "$here/version")
fail() { echo "$*" >&2; exit 1; }

installed=$(dpkg-query -W -f '${Version}' libbrotli1)
dpkg --compare-versions "$installed" ge "$minimum" || fail "libbrotli1 $installed is older than the fixed $minimum."
library=$(readlink -f "$(dpkg -L libbrotli1 | grep '/libbrotlidec\.so\.1$')")
case $(dpkg -S "$library" 2>/dev/null) in
    libbrotli1:*) ;;
    *) fail "$library is not owned by libbrotli1." ;;
esac

# Every libbrotlidec file or link on the image resolves to that library.
sh "$here/decoder-inventory.sh" / "$library"

# Every object that needs the decoder loads that file.
for object in $(grep -rlsF libbrotlidec.so.1 /usr/lib /usr/local/lib /usr/bin /usr/local/bin /usr/sbin /usr/local/sbin); do
    [ "$(readlink -f "$object")" = "$library" ] && continue
    case $(head -c 4 "$object" | od -An -c | tr -d ' ') in
        177ELF) ;;
        *) continue ;;
    esac
    loaded=$(ldd "$object" | awk '$1 == "libbrotlidec.so.1" { print $3 }')
    [ -z "$loaded" ] || [ "$(readlink -f "$loaded")" = "$library" ] \
        || fail "$object loads $loaded, not $library."
done

# Node 22.23.4, 24.22.1 and 26.12.1 carry the fix in their own copy.
node -e '
const [major, minor, patch] = process.versions.node.split(".").map(Number);
const fixed = {22: [23, 4], 24: [22, 1], 26: [12, 1]}[major];
const ok = major > 26 || (fixed && (minor > fixed[0] || (minor === fixed[0] && patch >= fixed[1])));
if (!ok) { console.error(`Node ${process.version} lacks the CVE-2026-111497 fix.`); process.exit(1); }'

# The fixed decoder names its new error code; a valid stream round trips.
sample='Durable Workflow brotli round trip λ'
encoded=$(node -e 'process.stdout.write(require("zlib").brotliCompressSync(Buffer.from(process.argv[1])).toString("hex"))' "$sample")
python3 -I "$here/brotli-check.py" "$library" "$encoded" "$sample"
