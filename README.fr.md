# Sherpa

[English](README.md) · **Français**

Un agent de développement pour le terminal. Lancez-le dans un projet et
demandez en langage courant : il lit le code, cherche dans la documentation du
projet, modifie des fichiers, lance vos tests — et demande avant tout ce qui
écrit ou exécute.

Il s'appuie sur un modèle **en ligne**, par n'importe quelle API compatible
OpenAI (OpenAI, Mistral, Groq, OpenRouter, Cloudflare Workers AI, vLLM, LM
Studio…), ou **sur votre machine** avec Ollama. Écrit en PHP 8.4 et Symfony 8 ;
sous licence MIT.

```
$ cd ~/code/boutique
$ sherpa
✓ Model: gpt-4o-mini (online, api.openai.com · 32,768 tokens · machine config)
✓ Project loaded: boutique (PHP)

❯ Pourquoi le sous-total des factures est faux quand une ligne a une quantité > 1 ?
  → Tool: project_grep pattern=subtotal
  → Tool: file_read path=src/Billing/Invoice.php
Sherpa  Invoice::subtotal() additionne le prix de chaque produit une seule fois,
        sans le multiplier par $line->quantity (src/Billing/Invoice.php, ligne 42)…
```

L'interface est en anglais ; Sherpa répond dans la langue de votre question.

## Installation

### L'exécutable

Un seul fichier pour Linux x86_64 — ni PHP, ni Docker, rien d'autre à
installer. Prenez la dernière version sur la page [Releases](../../releases) :

```sh
version=0.1.0
base="https://github.com/hbenabdallah/sherpa/releases/download/v$version"
curl -fLO "$base/sherpa-$version-linux-x86_64"
curl -fLO "$base/sherpa-$version-linux-x86_64.sha256"
sha256sum -c "sherpa-$version-linux-x86_64.sha256"
install -m 0755 "sherpa-$version-linux-x86_64" ~/.local/bin/sherpa
```

`~/.local/bin` doit être dans votre `PATH`.

### Depuis les sources

Il faut Docker (pour installer les dépendances) et `make` :

```sh
git clone https://github.com/hbenabdallah/sherpa.git && cd sherpa
make install   # .env, l'image Docker, les dépendances Composer
make php       # le PHP statique de Sherpa, pour tourner sur la machine (sans root)
make link      # une commande `sherpa` dans ~/.local/bin
make doctor    # vérifie tout, et dit ce qui manque
```

Avec `make php`, Sherpa tourne sur votre machine, où ses commandes atteignent vos
propres outils. Sans lui, la commande `sherpa` se replie sur le conteneur Docker,
qui ne voit que les projets sous `SHERPA_PROJECTS_ROOT` et ne contient que PHP.

## Premier lancement

```sh
export SHERPA_API_KEY=…      # facultatif : sans elle, le premier lancement demande la clé
cd ~/code/mon-projet
sherpa
```

Le premier lancement sur une machine demande l'adresse du fournisseur — par
exemple `https://api.openai.com/v1` — puis fait choisir un modèle de chat dans
sa liste, avec sa fenêtre de contexte. Les deux vont dans
`~/.config/sherpa/config.yaml` et ne sont plus demandés. Entrée à la question de
l'adresse : un modèle local avec Ollama à la place.

Sherpa cherche ensuite, chez le même fournisseur, un modèle qui transforme le
texte en vecteurs, pour chercher dans votre documentation par le sens. Sans rien
demander : il lit le catalogue du fournisseur, ou essaie les noms connus sur un
mot. Un fournisseur qui n'en a pas (Anthropic n'en propose pas) garde la
recherche par mots-clés, et le dit.

**Plusieurs fournisseurs.** `/provider add` demande une adresse, un nom court
(`groq` pour `api.groq.com`) et la clé — tapée sans s'afficher, essayée aussitôt
— puis y passe la session et affiche les modèles du fournisseur. Rien à
exporter, rien à redémarrer. `/provider groq` fait passer de l'un à l'autre,
`/provider` les liste, `/provider key groq` remplace une clé, et
`sherpa -P groq` démarre sur l'un d'eux. Chacun garde son modèle, et un
fournisseur ne reçoit jamais la clé d'un autre.

Les clés vont dans `~/.config/sherpa/keys.yaml`, lisible par vous seul — jamais
dans `config.yaml`. Un fournisseur peut à la place nommer une variable
(`key_env: GROQ_API_KEY` dans `config.yaml`), qui l'emporte quand elle est définie.

**Le dossier de lancement est le projet.** Un nouveau reçoit deux questions — son
nom, et si ses commandes tournent dans un conteneur Docker — et il est enregistré
à votre premier message. Sherpa refuse de démarrer dans votre répertoire
personnel ou à `/` : ses outils de fichiers sont confinés au projet, et là ce
serait tout.

## Utilisation

Tapez une demande ; Sherpa la traite avec ses outils, puis répond. Ctrl+C arrête
le tour en cours ; à une invite vide, il quitte.

| Outil | Rôle | Demande d'abord |
|---|---|---|
| `list_dir`, `project_grep`, `file_read` | trouver et lire le code | non |
| `doc_search` | chercher dans la documentation du projet | non |
| `file_write`, `file_patch` | écrire ou modifier un fichier, avec un aperçu | **oui** |
| `shell_exec` | lancer une commande — dans le conteneur du projet s'il en a un | **oui** |
| `memory_remember`, `memory_recall` | garder et retrouver des faits durables sur le projet | non |
| `context_recall` | rappeler une sortie d'outil retirée du contexte | non |
| `skill_load` | charger un skill (voir plus bas) | non |

Une confirmation propose **[a]** une fois, **[s]** pour la session, **[p]**
toujours pour ce projet, **[r]** refuser. `/permissions` liste les autorisations
permanentes et les retire. Un fichier PHP ou JSON qui ne serait plus valide est
refusé avant d'être écrit.

| Commande | |
|---|---|
| `/help` | toutes les commandes |
| `/model`, `/backend` | changer de modèle, ou passer du modèle en ligne au local |
| `/provider` · `/provider add` · `/provider <nom>` · `/provider key <nom>` · `/provider remove <nom>` | les fournisseurs d'API : lister, ajouter, changer, changer de clé, oublier |
| `/docs` · `/docs <question>` · `/docs eval` | l'index de la documentation · ce que le modèle recevrait · la qualité de la recherche sur vos propres questions |
| `/memory`, `/remember <k> <v>`, `/forget <k>` | la mémoire du projet |
| `/sessions`, `/resume [n]` | les conversations enregistrées |
| `/context`, `/compact` | ce qui remplit la fenêtre de contexte, et la compacter tout de suite |
| `/project`, `/project edit`, `/project forget` | le projet courant |
| `/permissions`, `/mcp`, `/skills`, `/tools` | le reste |

À l'invite, `/` ouvre la liste des commandes et des skills, filtrée à mesure
de la frappe (↑↓ pour choisir, Tab pour compléter, Entrée pour lancer). Un skill
se lance comme une commande : `/<skill> [demande]` donne au modèle son texte avec
votre demande. **Page Haut** ouvre toute la session en plein écran, pour
remonter dedans (PgUp/PgDn, ↑↓, Début/Fin ; `q`, Échap ou Entrée pour revenir).

Options : `-b api|ollama` (backend pour cette exécution), `-P <fournisseur>`
(un fournisseur d'API enregistré, pour cette exécution), `-m <modèle>`,
`-c <tokens>` (fenêtre de contexte), `-r [n]` (reprendre la dernière
conversation, ou la n-ième).

## Ce qui le fait marcher

**La mémoire du projet.** Les faits durables — une convention, l'emplacement de
la configuration, la commande qui lance les tests — sont gardés par projet dans
SQLite, par le modèle au fil du travail et par un court passage en fin de
session. Un résumé entre dans chaque prompt, classé selon ce sur quoi vous
travaillez : les fichiers touchés dans git, le dossier de lancement.

**La documentation du projet.** Les fichiers Markdown, texte, reStructuredText,
AsciiDoc et HTML sont découpés selon leurs titres, sans jamais couper un tableau
ni un bloc de code, et cherchés par mots-clés et par le sens à la fois. Le modèle
reçoit la section entière autour de chaque résultat, avec sa source à citer, et
la consigne de le dire quand la documentation ne répond pas. L'index se met à
jour tout seul avant chaque recherche.

**La fenêtre de contexte.** Chaque tour renvoie toute la conversation : sa taille
fait le coût et l'attente. Les vieilles sorties d'outils sortent de la fenêtre —
`context_recall` les ramène — ou les plus vieux tours sont résumés, selon ce que
la machine fait le plus vite. La question en cours est toujours gardée mot pour
mot. Les sorties d'outils sont bornées : un grep qui tombe sur un fichier minifié
renvoie le passage autour du résultat, pas un mégaoctet.

**Les conversations** sont enregistrées après chaque tour, dans le dossier du
projet : fermer le terminal coûte un tour, et `/resume` en reprend une.

**Les skills** sont des fichiers Markdown — `~/.config/sherpa/skills/*.md` pour
tous les projets, `.sherpa/skills/*.md` dans l'un d'eux — dont le premier
paragraphe est présenté au modèle ; il charge le fichier entier quand le sujet
se présente.

**Les serveurs MCP** déclarés dans `~/.config/sherpa/mcp.json` — le format
`mcpServers` des autres clients, avec une `command` à lancer ou une `url` à
joindre — ajoutent leurs outils, qui demandent toujours avant de s'exécuter. Voir
`mcp.json.dist`.

## Mesuré, pas supposé

Un choix n'a été gardé que si une mesure le justifiait :

- **Recherche dans la documentation.** Bonne section en premier : mots-clés 72 %
  et sens 94 % sur un corpus de test, mais 74 % et 70 % sur la documentation d'un
  vrai projet (54 vraies questions). Chacune manque ce que l'autre trouve : les
  deux servent, fusionnées — 94 % et 76 %. `make rag-eval` rejoue la mesure ;
  `/docs eval` la fait sur votre projet.
- **Les questions sans réponse dans la documentation.** Avec des sorties d'outils
  non bornées, l'agent en traitait bien 6 sur 12 et inventait 2 réponses ;
  bornées, 12 sur 12, aucune inventée. Une règle de prompt censée aider n'a rien
  apporté, et a été écartée.
- **Les modifications de fichiers.** `file_patch` échouait d'abord sur un appel
  sur deux, toujours sur les mêmes erreurs — antislashs doublés, fins de ligne,
  indentation. Ne corriger une erreur que lorsqu'elle n'a qu'une lecture possible
  a ramené ce taux à 1 sur 22.

## Configuration

| Où | Quoi |
|---|---|
| `~/.config/sherpa/config.yaml` | backend, fournisseurs d'API (adresse, modèle), tarifs (pour afficher un coût) |
| `~/.config/sherpa/keys.yaml` | les clés des fournisseurs, lisible par vous seul |
| `~/.config/sherpa/projects.yaml` | projets connus et leurs autorisations permanentes |
| `~/.config/sherpa/projects/<projet>/` | mémoire, conversations, index de la documentation |
| `~/.config/sherpa/skills/`, `~/.config/sherpa/mcp.json` | skills et serveurs MCP pour tous les projets |
| `~/.cache/sherpa/` | compilé au premier lancement par l'exécutable ; peut être supprimé |

| Variable | |
|---|---|
| `SHERPA_API_KEY` | la clé d'un fournisseur qui n'en a ni d'enregistrée ni de variable à lui |
| `SHERPA_API_URL`, `SHERPA_API_MODEL` | l'emportent sur `config.yaml` |
| `SHERPA_EMBEDDING_MODEL` | impose un modèle d'embeddings |
| `OLLAMA_URL` | où répond Ollama (par défaut `http://localhost:11434`) |
| `SHERPA_MAX_SESSION_TOKENS` | arrête une session au-delà de ce nombre de tokens |
| `SHERPA_FACT_EXTRACTION=off` | pas de passage mémoire en fin de session |

Depuis les sources, les mêmes réglages peuvent aller dans `.env` (voir
`.env.dist`).

## Développement

```sh
make test       # toutes les suites, dans Docker, contre des faux — aucun modèle appelé
make lint       # les scripts shell
make bench      # des tâches réalistes contre un vrai modèle (coûte un peu)
make release VERSION=1.2.0    # l'exécutable unique, vérifié de bout en bout
```

`make release` emballe Sherpa dans un phar collé derrière le runtime PHP statique
`micro`, épinglé par somme de contrôle, puis le lance contre un faux fournisseur
(`tests/release/smoke.sh`). Un tag `v1.2.0` fait la même chose en CI et publie
l'exécutable, sa somme SHA-256 et `THIRD-PARTY-NOTICES.md` dans les Releases.

## Limites connues

- L'exécutable n'existe que pour Linux x86_64, pour l'instant.
- Le modèle doit savoir appeler des outils : Sherpa est une boucle d'appels
  d'outils, et le dit quand un modèle ne sait pas le faire.
- Le modèle d'embeddings doit venir du même fournisseur que le modèle de chat.

## Licence

MIT — voir [LICENSE](LICENSE). L'exécutable embarque aussi PHP et des paquets
Composer sous leurs propres licences, listées dans `THIRD-PARTY-NOTICES.md` à
chaque version.
