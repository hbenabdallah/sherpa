#!/usr/bin/env sh
# Install Sherpa: the single executable for this machine, from the GitHub
# Releases, checked against its SHA-256 before it goes anywhere.
#
#   curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh
#   curl -fsSL …/install.sh | sh -s -- --version 0.1.5
#   curl -fsSL …/install.sh | sh -s -- --uninstall
#
# SHERPA_VERSION and SHERPA_INSTALL_DIR (default ~/.local/bin) do the same as
# the options. Nothing needs root; nothing outside the install directory is
# touched, except that --uninstall can also remove ~/.config/sherpa on request.

set -eu

repo=hbenabdallah/sherpa
releases="https://github.com/$repo/releases"
base=${SHERPA_DOWNLOAD_BASE:-$releases/download}
dir=${SHERPA_INSTALL_DIR:-$HOME/.local/bin}
version=${SHERPA_VERSION:-}
uninstall=0

say() { printf '%s\n' "$*"; }
fail() { printf '✗ %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case $1 in
        --version) [ $# -ge 2 ] || fail "--version needs a number, like 0.1.5"; version=$2; shift 2 ;;
        --version=*) version=${1#--version=}; shift ;;
        --dir) [ $# -ge 2 ] || fail "--dir needs a directory"; dir=$2; shift 2 ;;
        --dir=*) dir=${1#--dir=}; shift ;;
        --uninstall) uninstall=1; shift ;;
        -h|--help)
            say "Install Sherpa. Options: --version X.Y.Z (default: the latest release),"
            say "--dir DIR (default: ~/.local/bin), --uninstall."
            exit 0 ;;
        *) fail "unknown option: $1" ;;
    esac
done

target="$dir/sherpa"

if [ "$uninstall" -eq 1 ]; then
    if [ -e "$target" ]; then
        rm -f "$target"
        say "✓ Removed $target"
    else
        say "Nothing to remove at $target"
    fi
    say "  Your settings, keys, memory and conversations are in ~/.config/sherpa;"
    say "  remove that directory too to forget everything (rm -rf ~/.config/sherpa)."
    exit 0
fi

command -v curl > /dev/null 2>&1 || fail "curl is needed to download Sherpa."

# The platform, in the release's words.
os=$(uname -s)
arch=$(uname -m)
case "$os-$arch" in
    Linux-x86_64|Linux-amd64) platform=linux-x86_64 ;;
    Linux-aarch64|Linux-arm64) platform=linux-aarch64 ;;
    Darwin-arm64|Darwin-aarch64) platform=macos-aarch64 ;;
    Darwin-x86_64) fail "no executable for Intel Macs yet. Sherpa runs from the source there: see the README." ;;
    *) fail "no executable for $os $arch. Sherpa runs from the source there: see the README." ;;
esac

# The latest release, unless one was named: GitHub redirects /latest to its tag.
if [ -z "$version" ]; then
    latest=$(curl -fsSLI -o /dev/null -w '%{url_effective}' "$releases/latest") || fail "cannot reach GitHub to find the latest release."
    version=${latest##*/}
    case $version in
        v[0-9]*) ;;
        *) fail "could not tell the latest release (got \"$version\"). Name one with --version." ;;
    esac
fi
version=${version#v}

file="sherpa-$version-$platform"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT INT TERM

say "Downloading Sherpa $version for $platform…"
curl -fL --progress-bar -o "$tmp/$file" "$base/v$version/$file" \
    || fail "no Sherpa $version for $platform. The releases are listed at $releases"
curl -fsSL -o "$tmp/$file.sha256" "$base/v$version/$file.sha256" \
    || fail "the checksum of $file is missing from the release: nothing was installed."

expected=$(cut -d' ' -f1 < "$tmp/$file.sha256")
if command -v sha256sum > /dev/null 2>&1; then
    actual=$(sha256sum "$tmp/$file" | cut -d' ' -f1)
else
    actual=$(shasum -a 256 "$tmp/$file" | cut -d' ' -f1)
fi
[ -n "$expected" ] && [ "$actual" = "$expected" ] \
    || fail "the SHA-256 of the download does not match the release's: nothing was installed."
say "✓ SHA-256 checked"

previous=""
if [ -x "$target" ]; then
    previous=$("$target" --version 2>/dev/null | head -n 1 | sed "s/ (env:.*//" || true)
fi

mkdir -p "$dir"
chmod 0755 "$tmp/$file"
# Replaced in one rename, so a Sherpa starting meanwhile never finds half a file.
mv "$tmp/$file" "$target.new"
mv "$target.new" "$target"

# A file a browser downloaded is quarantined on macOS; curl's is not, but a
# leftover mark from an earlier install would still stop it at launch.
if [ "$platform" = macos-aarch64 ] && command -v xattr > /dev/null 2>&1; then
    xattr -d com.apple.quarantine "$target" 2> /dev/null || true
fi

say "✓ Sherpa $version installed in $target${previous:+ (was: $previous)}"

case ":$PATH:" in
    *":$dir:"*)
        say ""
        say "Go to a project and run: sherpa"
        ;;
    *)
        say ""
        say "⚠ $dir is not in your PATH. Add it, then open a new terminal:"
        case ${SHELL:-} in
            */zsh) say "    echo 'export PATH=\"$dir:\$PATH\"' >> ~/.zshrc" ;;
            */bash) say "    echo 'export PATH=\"$dir:\$PATH\"' >> ~/.bashrc" ;;
            *) say "    export PATH=\"$dir:\$PATH\"" ;;
        esac
        say "Then, in a project: sherpa"
        ;;
esac
say "The first launch asks for your model provider's address and key."
