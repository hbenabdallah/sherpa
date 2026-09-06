#!/usr/bin/env sh
# Install the PHP Sherpa runs with on the host.
#
# One static binary from static-php-cli: no root, no package manager, nothing
# else on the machine touched. It is an executable downloaded from the web, so
# it is checked against a SHA-256 pinned in the Makefile before it is allowed
# anywhere, and a mismatch stops everything — a different file is not a newer
# version, it is a file nobody reviewed.
#
# Installed as ~/.local/share/sherpa/php, deliberately outside PATH: it must not
# shadow a PHP the system or another project relies on. The sherpa launcher
# looks there first.
#
#   bin/install-php.sh <url> <sha256> <directory>

set -eu

# sha256sum on Linux, shasum on macOS.
sha256() {
    if command -v sha256sum > /dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi | cut -d' ' -f1
}

url=$1
expected=$2
dir=$3

if [ -z "$expected" ]; then
    echo "✗ no PHP pinned for $(uname -s)-$(uname -m) (only Linux x86_64 and aarch64, macOS aarch64)." >&2
    echo "  Any PHP 8.4 with pdo_sqlite and pcntl will do: in the PATH, or named by SHERPA_PHP=…" >&2
    exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

echo "Downloading $url"
curl -fsSL --max-time 300 -o "$tmp/php.tgz" "$url"

actual=$(sha256 "$tmp/php.tgz")
if [ "$actual" != "$expected" ]; then
    echo "✗ unexpected SHA-256 sum: nothing was installed." >&2
    echo "  expected: $expected" >&2
    echo "  got:      $actual" >&2
    exit 1
fi
echo "✓ SHA-256 sum checked"

tar -xzf "$tmp/php.tgz" -C "$tmp" php

# What Sherpa cannot run without: 8.4, SQLite for its memory, pcntl for Ctrl+C.
if ! "$tmp/php" -r 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_sqlite") && extension_loaded("pcntl") ? 0 : 1);'; then
    echo "✗ this PHP does not suit Sherpa (8.4, pdo_sqlite and pcntl required): nothing was installed." >&2
    exit 1
fi

mkdir -p "$dir"
# Replaced in one rename, so a Sherpa starting meanwhile never finds half a file.
install -m 0755 "$tmp/php" "$dir/php.new"
mv "$dir/php.new" "$dir/php"

echo "✓ PHP $("$dir/php" -r 'echo PHP_VERSION;') installed in $dir/php"
