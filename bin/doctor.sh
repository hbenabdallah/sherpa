#!/usr/bin/env sh
# Everything Sherpa needs, checked one by one, with the fix next to each
# failure.
#
# Written for a machine without root: every remedy below stays in userspace,
# because the machine this was built on has no sudo and the next one may not
# either.
#
# shellcheck disable=SC2088
# ~/.local/bin is shown to the user as written, never expanded.

set -u

ok=0
bad=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; ok=$((ok + 1)); }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; bad=$((bad + 1)); shift; for l in "$@"; do printf '      %s\n' "$l"; done; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; shift; for l in "$@"; do printf '      %s\n' "$l"; done; }

HOME_DIR="${HOME:-/}"

# The shell first, .env second: the order docker compose applies, so what is
# checked here is what the container will actually receive. Sourcing .env
# outright did the opposite — its empty SHERPA_API_KEY= line wiped out a key
# exported in the shell, and this check reported a key the container had.
if [ -f .env ]; then
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in ''|'#'*) continue ;; esac
        key=${line%%=*}
        case "$key" in ''|*[!A-Za-z0-9_]*) continue ;; esac
        eval "[ -n \"\${$key+set}\" ]" && continue
        value=${line#*=}
        case "$value" in
            \"*\") value=${value#\"}; value=${value%\"} ;;
            \'*\') value=${value#\'}; value=${value%\'} ;;
        esac
        export "$key=$value"
    done < .env
fi

OLLAMA_URL="${OLLAMA_URL:-http://localhost:11434}"
API_URL="${SHERPA_API_URL:-}"
API_URL_SOURCE='SHERPA_API_URL'
API_KEY="${SHERPA_API_KEY:-}"
PROJECTS_ROOT="${SHERPA_PROJECTS_ROOT:-$HOME_DIR/workspace}"

# What this machine actually runs, which is config.yaml's if it has one.
# Checking .env's instead would report a missing model nobody uses, and pass a
# machine whose real choice was never made.
CONFIG_FILE="$HOME_DIR/.config/sherpa/config.yaml"

# A top-level key, and a key inside one backend's section.
yaml_top() { [ -f "$CONFIG_FILE" ] && sed -n "s/^$1:[[:space:]]*//p" "$CONFIG_FILE" | head -1 | tr -d "\"'"; }
yaml_in() {
    [ -f "$CONFIG_FILE" ] && awk -v sec="$1" -v key="$2" '
        /^[^[:space:]#][^:]*:/ { cur = $1; sub(/:.*/, "", cur) }
        cur == sec && $1 == key":" { sub("^[[:space:]]*" key ":[[:space:]]*", ""); gsub(/["\047]/, ""); print; exit }
    ' "$CONFIG_FILE"
}

BACKEND=$(yaml_top backend)
LEGACY_MODEL=$(yaml_top model)
if [ -n "$BACKEND" ]; then
    BACKEND_SOURCE='in config.yaml'
elif [ -n "$LEGACY_MODEL" ]; then
    # Written before there was a choice: it can only describe an Ollama model.
    BACKEND='ollama'
    BACKEND_SOURCE='in config.yaml'
elif [ -n "${SHERPA_BACKEND:-}" ]; then
    BACKEND="$SHERPA_BACKEND"
    BACKEND_SOURCE='in .env'
else
    BACKEND='api'
    BACKEND_SOURCE='by default'
fi
[ "$BACKEND" = 'ollama' ] || BACKEND='api'

OLLAMA_CHOSEN=$(yaml_in ollama model)
[ -z "$OLLAMA_CHOSEN" ] && OLLAMA_CHOSEN="$LEGACY_MODEL"
if [ -n "$OLLAMA_CHOSEN" ]; then
    OLLAMA_MODEL="$OLLAMA_CHOSEN"
    OLLAMA_MODEL_SOURCE="config.yaml"
else
    OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5-coder:7b}"
    OLLAMA_MODEL_SOURCE=".env"
fi

# The address the first-run question recorded, when the environment has none.
if [ -z "$API_URL" ]; then
    API_URL=$(yaml_in api url)
    API_URL_SOURCE='config.yaml'
fi

API_CHOSEN=$(yaml_in api model)
if [ -n "$API_CHOSEN" ]; then
    API_MODEL="$API_CHOSEN"
    API_MODEL_SOURCE="config.yaml"
else
    API_MODEL="${SHERPA_API_MODEL:-}"
    API_MODEL_SOURCE=".env"
fi

# The backend in force must work; the other one is reported without failing
# the check, since a machine only needs one of them.
problem() {
    if [ "$section" = "$BACKEND" ]; then fail "$@"; else warn "$@"; fi
}

echo
echo 'Sherpa — checking the installation'
echo

# ---- Docker -----------------------------------------------------------------
if command -v docker >/dev/null 2>&1; then
    pass "docker present ($(docker --version 2>/dev/null | cut -d, -f1))"

    if docker info >/dev/null 2>&1; then
        pass 'the docker daemon answers'
    else
        fail 'the docker daemon does not answer' \
             'Without sudo: your user has to be in the docker group.' \
             "  id -nG | tr ' ' '\\n' | grep -x docker" \
             'If the group was added recently, open a new session.'
    fi
else
    fail 'docker missing' 'Sherpa runs in a container; docker is the only system requirement.'
fi

if docker compose version >/dev/null 2>&1; then
    pass 'docker compose (v2) present'
else
    fail 'docker compose v2 missing' \
         'Without sudo: drop the plugin in ~/.docker/cli-plugins/docker-compose'
fi

# ---- configuration ----------------------------------------------------------
if [ -f .env ]; then
    pass '.env present'

    if [ "${HOME_PATH:-}" = "$HOME_DIR" ]; then
        pass "HOME_PATH matches \$HOME ($HOME_DIR)"
    else
        fail "HOME_PATH (${HOME_PATH:-empty}) does not match \$HOME ($HOME_DIR)" \
             'Project paths are absolute and mounted identically on both sides:' \
             'when they diverge, the tools read files that do not exist.' \
             'Fix with: make setup-env'
    fi

    if [ "${USER_ID:-}" = "$(id -u)" ]; then
        pass "USER_ID matches ($(id -u))"
    else
        fail "USER_ID (${USER_ID:-empty}) does not match $(id -u)" \
             'Files written by Sherpa would belong to another user.' \
             'Fix with: make setup-env'
    fi
else
    fail '.env missing' 'Create it with: make setup-env'
fi

if [ -d "$PROJECTS_ROOT" ]; then
    pass "projects root present ($PROJECTS_ROOT)"
else
    fail "projects root not found ($PROJECTS_ROOT)" \
         'Only projects under this root are visible inside the container.' \
         'Adjust SHERPA_PROJECTS_ROOT in .env.'
fi

if [ -d "$HOME_DIR/.config/sherpa" ]; then
    pass "configuration present ($HOME_DIR/.config/sherpa)"
else
    warn "configuration missing ($HOME_DIR/.config/sherpa)" 'Create it with: make config-dir'
fi

# ---- where Sherpa runs ------------------------------------------------------
# The same rule the launcher applies, from the same script.
if [ "${SHERPA_RUNTIME:-}" = 'docker' ]; then
    pass 'Sherpa runs in Docker (SHERPA_RUNTIME=docker)'
elif php=$(./bin/find-php.sh); then
    pass "Sherpa runs on this machine, with PHP $("$php" -r 'echo PHP_VERSION;') ($php)"
else
    warn 'Sherpa runs in Docker: no PHP 8.4 with pdo_sqlite and pcntl on this machine' \
         'On the machine, shell_exec runs your own tools (npm, pytest, docker compose exec…);' \
         'in Docker, only PHP. To move over:  make php'
fi

# ---- PHP dependencies -------------------------------------------------------
if [ -d vendor ]; then
    pass 'Composer dependencies installed'
else
    fail 'vendor/ missing' 'Install them with: make install'
fi

# ---- backend ----------------------------------------------------------------
case "$BACKEND" in
    api) pass "backend: api, an online model (chosen $BACKEND_SOURCE)" ;;
    *)   pass "backend: ollama, a local model (chosen $BACKEND_SOURCE)" ;;
esac

# ---- API --------------------------------------------------------------------
section=api
[ "$BACKEND" = 'api' ] || printf '\n  \033[2m(API — not the backend in force, for information)\033[0m\n'

if [ -z "$API_URL" ]; then
    problem 'no API address (neither SHERPA_API_URL nor api.url in config.yaml)' \
            'Run sherpa in a terminal: it asks for it and keeps it.' \
            'Or export it:  export SHERPA_API_URL=https://api.openai.com/v1'
else
    if [ -z "$API_KEY" ]; then
        warn 'SHERPA_API_KEY empty' \
             'Only a server with no authentication (vLLM, LM Studio…) will answer.' \
             'Export it:  export SHERPA_API_KEY=…   or fill it in with  make setup-env'
    fi

    # The key goes to the address the user configured and nowhere else, and is
    # never printed.
    if [ -n "$API_KEY" ]; then
        resp=$(curl -s --max-time 10 -w '\n%{http_code}' -H "Authorization: Bearer $API_KEY" "$API_URL/models" 2>/dev/null)
    else
        resp=$(curl -s --max-time 10 -w '\n%{http_code}' "$API_URL/models" 2>/dev/null)
    fi
    code=$(printf '%s' "$resp" | tail -n 1)
    body=$(printf '%s' "$resp" | sed '$d')

    case "$code" in
        200)
            pass "the API answers on $API_URL (from $API_URL_SOURCE)"

            if [ -z "$API_MODEL" ]; then
                warn 'no model chosen for the API' \
                     'On the first run, Sherpa offers the provider list and' \
                     "writes the answer to $CONFIG_FILE."
            elif printf '%s' "$body" | grep -q "\"$API_MODEL\""; then
                pass "model $API_MODEL offered by the provider (chosen in $API_MODEL_SOURCE)"
            else
                warn "model $API_MODEL not on the provider's list (chosen in $API_MODEL_SOURCE)" \
                     'Some providers accept names that are not on their list;' \
                     'otherwise change it from Sherpa with /model.'
            fi
            ;;
        401|403)
            problem "key refused by $API_URL (HTTP $code)" 'Check SHERPA_API_KEY.' ;;
        405)
            # The route exists and does not take GET: a provider with no model
            # catalogue — Cloudflare Workers AI answers exactly this. The address
            # is right, and saying otherwise sent people to fix what was not
            # broken. What cannot be checked from here is said instead.
            pass "the API answers on $API_URL (from $API_URL_SOURCE)"
            warn "this provider publishes no catalogue ($API_URL/models: HTTP 405)" \
                 'The key and the model name are only checked on the first request;' \
                 'an unknown model is named there, with how to fix it.' ;;
        000|'')
            problem "the API does not answer on $API_URL" 'Check the address and the connection.' ;;
        *)
            problem "the API answers HTTP $code on $API_URL/models" \
                    'Check that the address stops at the version segment (…/v1).' ;;
    esac
fi

# ---- Ollama -----------------------------------------------------------------
section=ollama
[ "$BACKEND" = 'ollama' ] || printf '\n  \033[2m(Ollama — not the backend in force, for information)\033[0m\n'

tags=$(curl -s --max-time 5 "$OLLAMA_URL/api/tags" 2>/dev/null)

if [ -n "$tags" ]; then
    pass "Ollama answers on $OLLAMA_URL"

    if printf '%s' "$tags" | grep -q "\"$OLLAMA_MODEL\""; then
        pass "model $OLLAMA_MODEL present (chosen in $OLLAMA_MODEL_SOURCE)"
    else
        problem "model $OLLAMA_MODEL missing (chosen in $OLLAMA_MODEL_SOURCE)" \
             "  ollama pull $OLLAMA_MODEL" \
             '…or change it from Sherpa with /model.' \
             'Models installed:' \
             "  $(printf '%s' "$tags" | grep -o '"name":"[^"]*"' | cut -d'"' -f4 | tr '\n' ' ')"
    fi

    if [ -z "$OLLAMA_CHOSEN" ] && [ "$BACKEND" = 'ollama' ]; then
        warn 'no model chosen for this machine' \
             'On the first run, Sherpa asks which one to use and' \
             "writes the answer to $CONFIG_FILE."
    fi
else
    problem "Ollama does not answer on $OLLAMA_URL" \
         'Without sudo, in userspace:' \
         '  curl -L https://ollama.com/download/ollama-linux-amd64.tgz | tar -xz -C ~/.local' \
         '  ~/.local/bin/ollama serve   (or a systemctl --user unit)' \
         "If Ollama runs elsewhere, adjust OLLAMA_URL in .env."
fi
echo

# ---- launcher ---------------------------------------------------------------
if [ -x "$HOME_DIR/.local/bin/sherpa" ]; then
    pass 'sherpa command installed in ~/.local/bin'

    case ":${PATH}:" in
        *":$HOME_DIR/.local/bin:"*) pass '~/.local/bin is in the PATH' ;;
        *) warn '~/.local/bin is not in the PATH' \
                "Add to ~/.zshrc:  export PATH=\"\$HOME/.local/bin:\$PATH\"" ;;
    esac
else
    warn 'sherpa command not installed' \
         'Install it with: make link  (lets you run sherpa from a project)'
fi

echo
if [ "$bad" -eq 0 ]; then
    printf '  \033[32m%d check(s) passed, nothing to fix.\033[0m\n\n' "$ok"
    exit 0
fi

printf '  \033[31m%d problem(s)\033[0m, %d check(s) passed.\n\n' "$bad" "$ok"
exit 1
