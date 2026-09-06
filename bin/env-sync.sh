#!/usr/bin/env bash
# Bring an existing .env up to date with .env.dist, and ask for the one secret
# Sherpa can never ask for later.
#
# `cp .env.dist .env` only ever happened once, on the day someone installed.
# Every variable added since — a whole API backend's worth — arrived in the
# template and in nobody's .env, silently: the file simply had nothing to say
# about a feature that existed. That is the failure mode this project hunts
# everywhere else, so it does not get to live in its own installer.
#
# Values already set are never touched. Only names absent from .env are added,
# with the comment that explains them.
#
#   bin/env-sync.sh [--no-prompt]

set -euo pipefail

cd "$(dirname "$0")/.."

TEMPLATE=.env.dist
TARGET=.env
PROMPT=1

[ "${1:-}" = "--no-prompt" ] && PROMPT=0

if [ ! -f "$TEMPLATE" ]; then
    echo "✗ $TEMPLATE not found" >&2
    exit 1
fi

if [ ! -f "$TARGET" ]; then
    cp "$TEMPLATE" "$TARGET"
    echo "✓ $TARGET created from $TEMPLATE"
else
    added=$(awk '
        # First file: the names this machine already has, whatever their value.
        NR == FNR {
            if ($0 ~ /^[A-Za-z_][A-Za-z0-9_]*=/) {
                split($0, parts, "=")
                have[parts[1]] = 1
            }
            next
        }

        # Second file: keep the comment block that introduces each variable, so
        # a name added here arrives with the paragraph that says what it is for.
        /^[[:space:]]*#/ || /^[[:space:]]*$/ { block = block $0 "\n"; next }

        /^[A-Za-z_][A-Za-z0-9_]*=/ {
            split($0, parts, "=")
            key = parts[1]
            if (!(key in have)) {
                printf "%s%s\n", block, $0 >> target
                names = names " " key
            }
            block = ""
            next
        }

        { block = "" }

        END { print names }
    ' target="$TARGET" "$TARGET" "$TEMPLATE")

    if [ -n "${added// /}" ]; then
        echo "✓ $TARGET filled in:${added}"
    else
        echo "✓ $TARGET already holds every variable of $TEMPLATE"
    fi
fi

# The key is the one thing the first-run screen will never ask for: a secret
# typed into a prompt ends up in the terminal's scrollback. Here is the one
# place where asking is reasonable — a file, once, with the right permissions.
#
# The URL is deliberately NOT asked: set here it would win over
# ~/.config/sherpa/config.yaml for good, and /model could no longer change it.
# Sherpa asks for it at first run and remembers it where it can be changed.
current_key=$(grep -E '^SHERPA_API_KEY=' "$TARGET" 2>/dev/null | head -1 | cut -d= -f2- || true)

if [ "$PROMPT" = "1" ] && [ -z "${current_key}" ] && [ -z "${SHERPA_API_KEY:-}" ] && [ -t 0 ]; then
    echo
    echo "The API key (SHERPA_API_KEY) is neither in your shell nor in $TARGET."
    echo "It is optional: a local server with no authentication does not want one,"
    echo "and neither does an Ollama model. Enter to skip."
    printf "  Key (nothing is shown): "
    read -rs key || key=""
    echo

    if [ -n "$key" ]; then
        # Written with a temporary file rather than sed -i: the key can hold
        # any character, and a & or a / in a sed replacement is a corruption
        # nobody would notice until the first refused request.
        tmp=$(mktemp)
        while IFS= read -r line || [ -n "$line" ]; do
            case "$line" in
                SHERPA_API_KEY=*) printf 'SHERPA_API_KEY=%s\n' "$key" ;;
                *) printf '%s\n' "$line" ;;
            esac
        done < "$TARGET" > "$tmp"
        mv "$tmp" "$TARGET"
        echo "✓ key written to $TARGET"
    else
        echo "  (skipped — export SHERPA_API_KEY once you have one)"
    fi
fi

# It may now hold a secret, and it never needed to be world-readable anyway.
chmod 600 "$TARGET"
