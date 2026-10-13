#!/bin/sh
# The entry Sherpa needs in the ACP registry (github.com/agentclientprotocol/
# registry), for a release already published: its agent.json on stdout, with
# the SHA-256 of each executable as downloaded from the release. Once listed,
# the registry follows new releases by itself.
#
# The executables are hashed rather than their .sha256 fetched: the installs
# badge counts .sha256 downloads, and this is not an install.
#
#   bin/acp-registry.sh 1.0.4 > agent.json
set -eu

version=${1:?usage: bin/acp-registry.sh <version>}
repo=https://github.com/hbenabdallah/sherpa
base="$repo/releases/download/v$version"

entry() {
    # $1: the registry's platform, $2: the executable's
    file="sherpa-$version-$2"
    sum=$(curl -fsSL "$base/$file" | sha256sum | cut -d' ' -f1)
    printf '      "%s": {\n        "archive": "%s",\n        "sha256": "%s",\n        "cmd": "./%s",\n        "args": ["acp"]\n      }' \
        "$1" "$base/$file" "$sum" "$file"
}

cat <<JSON
{
  "id": "sherpa",
  "name": "Sherpa",
  "version": "$version",
  "description": "A coding agent for any model provider, local ones included, that asks before anything that writes or executes",
  "repository": "$repo",
  "authors": ["Houssem Eddine BENABDALLAH"],
  "license": "PolyForm-Shield-1.0.0",
  "license_url": "$repo/blob/main/LICENSE",
  "distribution": {
    "binary": {
$(entry darwin-aarch64 macos-aarch64),
$(entry darwin-x86_64 macos-x86_64),
$(entry linux-aarch64 linux-aarch64),
$(entry linux-x86_64 linux-x86_64)
    }
  }
}
JSON
