#!/usr/bin/env sh
# Which PHP runs Sherpa on the host: its path on stdout, or exit 1 when none can.
#
# One place for the rule, shared by the launcher, `make link` and `make doctor`.
# Three copies of it would sooner or later disagree about where Sherpa runs —
# and the doctor would then vouch for a runtime the launcher does not use.
#
# In order: one named with SHERPA_PHP, Sherpa's own (`make php` puts it outside
# PATH), then whatever `php` is on PATH. Each is kept only if it can actually
# run Sherpa: 8.4, SQLite for its memory, pcntl for Ctrl+C.

set -u

for candidate in "${SHERPA_PHP:-}" "$HOME/.local/share/sherpa/php" "$(command -v php 2>/dev/null || true)"; do
    if [ -z "$candidate" ] || [ ! -x "$candidate" ]; then
        continue
    fi

    if "$candidate" -r 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_sqlite") && extension_loaded("pcntl") ? 0 : 1);' 2>/dev/null; then
        printf '%s\n' "$candidate"
        exit 0
    fi
done

exit 1
