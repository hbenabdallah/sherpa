#!/usr/bin/env sh
# The single executable, run end to end against a fake provider: it starts
# without PHP, a .env or the checkout, compiles its container outside the
# archive, runs a turn with a tool call, and still refuses a PHP file that does
# not parse — with PHP_BINARY being Sherpa itself, that check runs in-process.
#
#   tests/release/smoke.sh <executable>   (named sherpa-<version>-<os>-<arch>)

set -eu

binary=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")
# The release number is in the file name: sherpa-<version>-linux-x86_64.
expected=$(basename "$1" | sed -E 's/^sherpa-(.*)-linux-[a-z0-9_]+$/\1/')
here=$(cd "$(dirname "$0")/../.." && pwd)
php=${SHERPA_PHP:-$("$here/bin/find-php.sh")}

work=$(mktemp -d)
trap 'kill "$server" 2>/dev/null || true; rm -rf "$work"' EXIT
mkdir -p "$work/home/.config/sherpa" "$work/project"
printf '# Demo\n\nA project used to check the executable.\n' > "$work/project/README.md"

port=$(( 20000 + $$ % 10000 ))
"$php" -S "127.0.0.1:$port" "$here/tests/fixtures/fake_provider.php" > "$work/server.log" 2>&1 &
server=$!
i=0
until curl -s "http://127.0.0.1:$port/v1/models" > /dev/null 2>&1; do
    i=$((i + 1)); [ "$i" -lt 50 ] || { echo "✗ fake provider did not start"; exit 1; }; sleep 0.1
done

# The project known in advance, with file_write granted: a pipe cannot answer
# a confirmation.
cat > "$work/home/.config/sherpa/projects.yaml" <<YAML
projects:
  demo:
    name: demo
    path: '$work/project'
    memory_db: '$work/home/.config/sherpa/projects/demo/memory.db'
    docker: { enabled: false, container: '', db_container: '' }
    stack: ''
    created_at: '2026-01-01T00:00:00+00:00'
    last_used_at: '2026-01-01T00:00:00+00:00'
    allowed_tools: [file_write]
YAML

out=$(cd "$work/project" && printf 'Write broken.php\n/docs\n/exit\n' | env -i PATH=/usr/bin:/bin HOME="$work/home" \
    SHERPA_API_URL="http://127.0.0.1:$port/v1" SHERPA_API_KEY=test SHERPA_API_MODEL=fake-chat \
    SHERPA_FACT_EXTRACTION=off "$binary" 2>&1) || { echo "$out"; echo "✗ the executable failed"; exit 1; }

# Colours in between would split what is looked for.
out=$(printf '%s' "$out" | sed 's/\x1b\[[0-9;]*m//g')

fail=0
check() { if printf '%s' "$out" | grep -q "$1"; then echo "  ✓ $2"; else echo "  ✗ $2"; fail=1; fi; }

check 'Model: fake-chat' 'starts on the configured model, with no .env'
check "v$expected" 'says which release it is'
check 'FAKE: the write was refused as invalid PHP' 'refuses PHP that does not parse, in-process'
check 'documents' '/docs reads the project documentation'
if [ -e "$work/project/broken.php" ]; then echo "  ✗ the broken file was not written"; fail=1; else echo "  ✓ the broken file was not written"; fi
if ls "$work/home/.cache/sherpa"/prod-* > /dev/null 2>&1; then echo "  ✓ its container is compiled under ~/.cache/sherpa"; else echo "  ✗ no container under ~/.cache/sherpa"; fail=1; fi

[ "$fail" -eq 0 ] || { echo "$out" | tail -40; exit 1; }
echo "✓ smoke test passed"
