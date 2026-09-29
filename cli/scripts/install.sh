#!/bin/sh
# Installs the latest envclient release into a directory on PATH.
#
#   curl -fsSL https://raw.githubusercontent.com/I-247/envserver/main/cli/scripts/install.sh | sh
#
# Override the destination with INSTALL_DIR, e.g.:
#   curl -fsSL .../install.sh | INSTALL_DIR=$HOME/.local/bin sh
#
# With cosign installed, checksums.txt is verified against the Sigstore
# signature of this repository's release workflow before any checksum in it
# is trusted. Set ENVCLIENT_REQUIRE_SIGNATURE=1 to refuse to install at all
# without that check.

set -eu

repo="I-247/envserver"
install_dir="${INSTALL_DIR:-/usr/local/bin}"

os=$(uname -s | tr '[:upper:]' '[:lower:]')
arch=$(uname -m)
case "$arch" in
    x86_64 | amd64) arch=amd64 ;;
    aarch64 | arm64) arch=arm64 ;;
    *)
        echo "envclient: unsupported architecture '$arch'" >&2
        exit 1
        ;;
esac
case "$os" in
    linux | darwin) ;;
    *)
        echo "envclient: unsupported OS '$os'" >&2
        exit 1
        ;;
esac

echo "envclient: looking up the latest release..." >&2
tag=$(curl -fsSL "https://api.github.com/repos/${repo}/releases/latest" \
    | grep '"tag_name":' | head -n1 | cut -d'"' -f4)

if [ -z "$tag" ]; then
    echo "envclient: could not determine the latest release" >&2
    exit 1
fi

# The tag ends up in a URL and a file name, so it has to look like one.
if ! printf '%s\n' "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'; then
    echo "envclient: the latest release tag '$tag' is not a version; refusing to install" >&2
    exit 1
fi

version=${tag#v}
archive="envclient_${version}_${os}_${arch}.tar.gz"
base="https://github.com/${repo}/releases/download/${tag}"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

echo "envclient: downloading ${tag} for ${os}/${arch}..." >&2
curl -fsSL "${base}/${archive}" -o "$tmp/$archive"
curl -fsSL "${base}/checksums.txt" -o "$tmp/checksums.txt"

# The signature is checked before any checksum is believed: whoever could
# replace the archive could replace checksums.txt next to it.
identity="https://github.com/${repo}/.github/workflows/release.yml@refs/tags/${tag}"
issuer="https://token.actions.githubusercontent.com"
require_signature="${ENVCLIENT_REQUIRE_SIGNATURE:-0}"

if command -v cosign >/dev/null 2>&1; then
    if ! curl -fsSL "${base}/checksums.txt.sigstore.json" -o "$tmp/checksums.txt.sigstore.json"; then
        echo "envclient: ${tag} has no signature (checksums.txt.sigstore.json); refusing to install" >&2
        exit 1
    fi

    if ! cosign verify-blob \
        --bundle "$tmp/checksums.txt.sigstore.json" \
        --certificate-identity "$identity" \
        --certificate-oidc-issuer "$issuer" \
        "$tmp/checksums.txt" >/dev/null; then
        echo "envclient: the signature on ${tag} does not verify as Envserver's release workflow; refusing to install" >&2
        exit 1
    fi

    echo "envclient: signature verified (${identity})" >&2
elif [ "$require_signature" = 1 ]; then
    echo "envclient: ENVCLIENT_REQUIRE_SIGNATURE=1 but cosign is not installed; refusing to install" >&2
    exit 1
else
    echo "envclient: warning: cosign is not installed, so only the checksum was verified, not who published it." >&2
    echo "envclient: to verify by hand:" >&2
    echo "  cosign verify-blob --bundle checksums.txt.sigstore.json \\" >&2
    echo "    --certificate-identity ${identity} \\" >&2
    echo "    --certificate-oidc-issuer ${issuer} checksums.txt" >&2
fi

# Fail closed: no line for this archive, or no tool to check it, is a
# refusal, never a skipped check.
expected=$(awk -v name="$archive" '$2 == name { print $1 }' "$tmp/checksums.txt")
if [ -z "$expected" ]; then
    echo "envclient: ${archive} is not listed in checksums.txt; refusing to install" >&2
    exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
    actual=$(sha256sum "$tmp/$archive" | awk '{ print $1 }')
elif command -v shasum >/dev/null 2>&1; then
    actual=$(shasum -a 256 "$tmp/$archive" | awk '{ print $1 }')
else
    echo "envclient: neither sha256sum nor shasum is available to verify the download" >&2
    exit 1
fi

if [ "$actual" != "$expected" ]; then
    echo "envclient: checksum mismatch for ${archive} (expected ${expected}, got ${actual}); refusing to install" >&2
    exit 1
fi

tar -xzf "$tmp/$archive" -C "$tmp" envclient

if [ ! -w "$install_dir" ] && [ "$(id -u)" != 0 ]; then
    echo "envclient: installing to ${install_dir} (sudo)" >&2
    sudo install -m 0755 "$tmp/envclient" "$install_dir/envclient"
else
    mkdir -p "$install_dir"
    install -m 0755 "$tmp/envclient" "$install_dir/envclient"
fi

echo "envclient: installed to ${install_dir}/envclient" >&2
"$install_dir/envclient" --version 2>/dev/null || true
