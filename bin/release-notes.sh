#!/usr/bin/env sh
# What a release changed, in Markdown: its section of the changelog. Used for
# the release page and release-notes.md, which `sherpa update` shows once it
# has installed the release. A version the changelog has no section for is an
# error, so a release is never published without its notes.
#
#   bin/release-notes.sh v1.8.0 [CHANGELOG.md]

set -eu

tag=${1:?usage: bin/release-notes.sh <tag> [changelog]}
changelog=${2:-CHANGELOG.md}
version=${tag#v}

# From the version's heading, "## 1.8.0" or "## 1.8.0 (date)", to the next one.
section=$(awk -v heading="## $version" '
    /^## / { if (found) exit; found = ($0 == heading || index($0, heading " ") == 1); next }
    found
' "$changelog")

if [ -z "$(printf '%s' "$section" | tr -d '[:space:]')" ]; then
    printf '%s has no section for %s: rename its "## Unreleased" to "## %s (date)".\n' "$changelog" "$version" "$version" >&2
    exit 1
fi

printf '## What changed in Sherpa %s\n\n' "$version"
printf '%s\n\n' "$(printf '%s\n' "$section" | sed '/./,$!d')"
printf '%s\n' "Single executable for Linux (x86_64, aarch64) and macOS (Apple Silicon, Intel): nothing to install. \`sherpa update\` installs it over an earlier one; see the README for setup, and THIRD-PARTY-NOTICES.md for the licences of what it bundles."
