#!/usr/bin/env sh
# Everything Sherpa needs, checked one by one, with the fix next to each
# failure.
#
# Written for a machine without root: every remedy below stays in userspace,
# because the machine this was built on has no sudo and the next one may not
# either.

set -u

ok=0
bad=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; ok=$((ok + 1)); }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; bad=$((bad + 1)); shift; for l in "$@"; do printf '      %s\n' "$l"; done; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; shift; for l in "$@"; do printf '      %s\n' "$l"; done; }

HOME_DIR="${HOME:-/}"
[ -f .env ] && . ./.env 2>/dev/null

OLLAMA_URL="${OLLAMA_URL:-http://localhost:11434}"
PROJECTS_ROOT="${SHERPA_PROJECTS_ROOT:-$HOME_DIR/workspace}"

# The model this machine actually runs, which is config.yaml's if it has one.
# Checking .env's instead would report a missing model nobody uses, and pass a
# machine whose real choice was never pulled.
CONFIG_FILE="$HOME_DIR/.config/sherpa/config.yaml"
CHOSEN=""
[ -f "$CONFIG_FILE" ] && CHOSEN=$(sed -n 's/^model:[[:space:]]*//p' "$CONFIG_FILE" | head -1 | tr -d '"'"'"'"')

if [ -n "$CHOSEN" ]; then
    OLLAMA_MODEL="$CHOSEN"
    MODEL_SOURCE="config.yaml"
else
    OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5-coder:7b}"
    MODEL_SOURCE=".env"
fi

echo
echo 'Sherpa — vérification de l’installation'
echo

# ---- Docker -----------------------------------------------------------------
if command -v docker >/dev/null 2>&1; then
    pass "docker présent ($(docker --version 2>/dev/null | cut -d, -f1))"

    if docker info >/dev/null 2>&1; then
        pass 'le démon docker répond'
    else
        fail 'le démon docker ne répond pas' \
             'Sans sudo : votre utilisateur doit être dans le groupe docker.' \
             "  id -nG | tr ' ' '\\n' | grep -x docker" \
             'Si le groupe a été ajouté récemment, ouvrez une nouvelle session.'
    fi
else
    fail 'docker absent' 'Sherpa tourne dans un conteneur ; docker est le seul prérequis système.'
fi

if docker compose version >/dev/null 2>&1; then
    pass 'docker compose (v2) présent'
else
    fail 'docker compose v2 absent' \
         'Sans sudo : déposez le plugin dans ~/.docker/cli-plugins/docker-compose'
fi

# ---- configuration ----------------------------------------------------------
if [ -f .env ]; then
    pass '.env présent'

    if [ "${HOME_PATH:-}" = "$HOME_DIR" ]; then
        pass "HOME_PATH correspond à \$HOME ($HOME_DIR)"
    else
        fail "HOME_PATH (${HOME_PATH:-vide}) ne correspond pas à \$HOME ($HOME_DIR)" \
             'Les chemins de projets sont absolus et montés à l’identique des deux' \
             'côtés : s’ils divergent, les tools lisent des fichiers qui n’existent pas.' \
             'Corrigez avec : make setup-env'
    fi

    if [ "${USER_ID:-}" = "$(id -u)" ]; then
        pass "USER_ID correspond ($(id -u))"
    else
        fail "USER_ID (${USER_ID:-vide}) ne correspond pas à $(id -u)" \
             'Les fichiers écrits par Sherpa appartiendraient à un autre utilisateur.' \
             'Corrigez avec : make setup-env'
    fi
else
    fail '.env absent' 'Créez-le avec : make setup-env'
fi

if [ -d "$PROJECTS_ROOT" ]; then
    pass "racine des projets présente ($PROJECTS_ROOT)"
else
    fail "racine des projets introuvable ($PROJECTS_ROOT)" \
         'Seuls les projets situés sous cette racine sont visibles dans le conteneur.' \
         'Ajustez SHERPA_PROJECTS_ROOT dans .env.'
fi

if [ -d "$HOME_DIR/.config/sherpa" ]; then
    pass "configuration présente ($HOME_DIR/.config/sherpa)"
else
    warn "configuration absente ($HOME_DIR/.config/sherpa)" 'Créez-la avec : make config-dir'
fi

# ---- dépendances PHP --------------------------------------------------------
if [ -d vendor ]; then
    pass 'dépendances Composer installées'
else
    fail 'vendor/ absent' 'Installez-les avec : make install'
fi

# ---- Ollama -----------------------------------------------------------------
tags=$(curl -s --max-time 5 "$OLLAMA_URL/api/tags" 2>/dev/null)

if [ -n "$tags" ]; then
    pass "Ollama répond sur $OLLAMA_URL"

    if printf '%s' "$tags" | grep -q "\"$OLLAMA_MODEL\""; then
        pass "modèle $OLLAMA_MODEL présent (choisi dans $MODEL_SOURCE)"
    else
        fail "modèle $OLLAMA_MODEL absent (choisi dans $MODEL_SOURCE)" \
             "  ollama pull $OLLAMA_MODEL" \
             '…ou changez-en depuis Sherpa avec /model.' \
             'Modèles installés :' \
             "  $(printf '%s' "$tags" | grep -o '"name":"[^"]*"' | cut -d'"' -f4 | tr '\n' ' ')"
    fi

    if [ -z "$CHOSEN" ]; then
        warn 'aucun modèle choisi pour cette machine' \
             'Au premier lancement, Sherpa demandera lequel utiliser et' \
             "écrira la réponse dans $CONFIG_FILE."
    fi
else
    fail "Ollama ne répond pas sur $OLLAMA_URL" \
         'Sans sudo, en espace utilisateur :' \
         '  curl -L https://ollama.com/download/ollama-linux-amd64.tgz | tar -xz -C ~/.local' \
         '  ~/.local/bin/ollama serve   (ou une unité systemctl --user)' \
         "Si Ollama tourne ailleurs, ajustez OLLAMA_URL dans .env."
fi

# ---- lanceur ----------------------------------------------------------------
if [ -x "$HOME_DIR/.local/bin/sherpa" ]; then
    pass 'commande sherpa installée dans ~/.local/bin'

    case ":${PATH}:" in
        *":$HOME_DIR/.local/bin:"*) pass '~/.local/bin est dans le PATH' ;;
        *) warn '~/.local/bin n’est pas dans le PATH' \
                "Ajoutez à ~/.zshrc :  export PATH=\"\$HOME/.local/bin:\$PATH\"" ;;
    esac
else
    warn 'commande sherpa non installée' \
         'Installez-la avec : make link  (permet de lancer sherpa depuis un projet)'
fi

echo
if [ "$bad" -eq 0 ]; then
    printf '  \033[32m%d vérification(s) passée(s), rien à corriger.\033[0m\n\n' "$ok"
    exit 0
fi

printf '  \033[31m%d problème(s)\033[0m, %d vérification(s) passée(s).\n\n' "$bad" "$ok"
exit 1
