#!/bin/sh
# Read-only first-RUN boundary for the exact Trixie production parent (amd64/arm64).
# The Dockerfile invokes this through an absolute, credential-free environment.
set -eu

refuse() {
    printf '%s\n' 'Fixed APT prerequisite refused; no package operation was started.' >&2
    exit 1
}

[ "$#" -eq 0 ] || refuse
[ "$(/usr/bin/sed -n 's/^ID=//p' /etc/os-release)" = debian ] || refuse
[ "$(/usr/bin/sed -n 's/^VERSION_ID=//p' /etc/os-release)" = '"13"' ] || refuse
architecture=$(/usr/bin/timeout 10 /usr/bin/dpkg --print-architecture) || refuse
case "$architecture" in amd64|arm64) ;; *) refuse ;; esac

installed_version() {
    record=$(/usr/bin/timeout 10 /usr/bin/dpkg-query --show \
        "--showformat=\${Status}\t\${Version}\n" "$1") || refuse
    prefix=$(printf 'install ok installed\t')
    case "$record" in "$prefix"*) version=${record#"$prefix"} ;; *) refuse ;; esac
    case "$version" in ''|[!0-9]*|*[!0-9A-Za-z.+:~-]*) refuse ;; esac
    [ "${#version}" -le 100 ] || refuse
    printf '%s' "$version"
}

apt_version=$(installed_version apt) || refuse
libapt_version=$(installed_version libapt-pkg7.0) || refuse
[ "$apt_version" = "$libapt_version" ] || refuse
/usr/bin/timeout 10 /usr/bin/dpkg --compare-versions "$apt_version" ge 3.0.4 || refuse
client=$(/usr/bin/timeout 10 /usr/bin/apt-get --version) || refuse
[ "$(printf '%s\n' "$client" | /usr/bin/sed -n '1p')" = "apt $apt_version ($architecture)" ] || refuse
printf 'fixed-apt os=debian release=13 arch=%s apt=%s libapt-pkg7.0=%s\n' "$architecture" "$apt_version" "$libapt_version"
