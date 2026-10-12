#!/usr/bin/env sh
# What a release changed, in Markdown: the commits since the release before it,
# one line each. They are written to be read — one line, what changed and why —
# so they are the notes. Used for the release page and release-notes.md, which
# `sherpa update` shows once it has installed the release.
#
#   bin/release-notes.sh v1.0.2

set -eu

tag=${1:?usage: bin/release-notes.sh <tag>}
version=${tag#v}

# The release tag before this one; none for the first.
previous=$(git describe --tags --abbrev=0 --match 'v[0-9]*' "$tag^" 2>/dev/null || true)
range=${previous:+$previous..}$tag

printf '## What changed in Sherpa %s\n\n' "$version"
git log --no-merges --format='- %s' "$range"
printf '\n'
if [ -n "$previous" ]; then
    printf 'Since %s. ' "${previous#v}"
fi
printf '%s\n' "Single executable for Linux (x86_64, aarch64) and macOS (Apple Silicon, Intel): nothing to install. \`sherpa update\` installs it over an earlier one; see the README for setup, and THIRD-PARTY-NOTICES.md for the licences of what it bundles."
