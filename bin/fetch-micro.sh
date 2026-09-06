#!/usr/bin/env sh
# Fetch the static PHP "micro" runtime the single executable is built on — the
# same static-php-cli build as the PHP Sherpa runs with on the host, as a
# self-extracting head a phar is appended to. Checked against a SHA-256 pinned
# in the Makefile: a different file is not a newer version, it is one nobody
# reviewed.
#
#   bin/fetch-micro.sh <url> <sha256> <directory>

set -eu

# sha256sum on Linux, shasum on macOS.
sha256() {
    if command -v sha256sum > /dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi | cut -d' ' -f1
}

url=$1
expected=$2
dir=$3

if [ -f "$dir/micro.sfx" ] && [ "$(cat "$dir/micro.sha256" 2>/dev/null)" = "$expected" ]; then
    exit 0
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

echo "Downloading $url"
# Retried: the host has had passing 500s, and one of them is not worth a
# failed build.
curl -fsSL --max-time 300 --retry 5 --retry-delay 10 --retry-all-errors -o "$tmp/micro.tgz" "$url"

actual=$(sha256 "$tmp/micro.tgz")
if [ "$actual" != "$expected" ]; then
    echo "✗ unexpected SHA-256 sum for micro: nothing was built." >&2
    echo "  expected: $expected" >&2
    echo "  got:      $actual" >&2
    exit 1
fi

tar -xzf "$tmp/micro.tgz" -C "$tmp" micro.sfx
mkdir -p "$dir"
mv "$tmp/micro.sfx" "$dir/micro.sfx"
echo "$expected" > "$dir/micro.sha256"
echo "✓ micro runtime checked and stored in $dir"
