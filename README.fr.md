<p align="center">
  <img src="https://raw.githubusercontent.com/hbenabdallah/sherpa/main/public/sherpa.jpeg" alt="Sherpa, un agent de développement pour le terminal" width="720">
</p>

<h1 align="center">Sherpa</h1>

<p align="center">
  <b>Un agent de développement pour le terminal, avec le modèle de votre choix.</b><br>
  Il lit votre code, cherche dans votre documentation, modifie des fichiers et lance vos tests,<br>
  et demande avant tout ce qui écrit ou exécute.<br>
  En ligne par Anthropic ou n'importe quelle API compatible OpenAI, ou entièrement en local avec Ollama.
</p>

<p align="center">
  <a href="https://github.com/hbenabdallah/sherpa/actions/workflows/tests.yml"><img src="https://github.com/hbenabdallah/sherpa/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/hbenabdallah/sherpa/releases/latest"><img src="https://img.shields.io/github/v/release/hbenabdallah/sherpa" alt="Dernière version"></a>
  <a href="https://github.com/hbenabdallah/sherpa/releases"><img src="https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/hbenabdallah/sherpa/badges/downloads.json" alt="Installations"></a>
  <img src="https://img.shields.io/badge/platform-Linux%20%7C%20macOS-lightgrey" alt="Linux | macOS">
  <a href="https://github.com/hbenabdallah/sherpa/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-PolyForm%20Shield%201.0.0-blue" alt="Licence : PolyForm Shield 1.0.0"></a>
  <a href="https://github.com/hbenabdallah/sherpa"><img src="https://img.shields.io/github/stars/hbenabdallah/sherpa?style=social" alt="Étoiles GitHub"></a>
</p>

<p align="center"><a href="https://github.com/hbenabdallah/sherpa/blob/main/README.md">English</a> · <b>Français</b></p>

---

```
$ cd ~/code/boutique
$ sherpa
✓ Model: gpt-4o-mini (online, api.openai.com · 32,768 tokens · machine config)
✓ Project loaded: boutique (PHP)

❯ Pourquoi le sous-total des factures est faux quand une ligne a une quantité > 1 ?
  → Tool: project_grep pattern=subtotal
  → Tool: file_read path=src/Billing/Invoice.php
Sherpa: Invoice::subtotal() additionne le prix de chaque produit une seule fois,
        sans le multiplier par $line->quantity (src/Billing/Invoice.php, ligne 42)…
```

Sherpa répond dans la langue de votre question ; son interface est en anglais.

## Pourquoi Sherpa ?

- **Votre modèle, votre fournisseur.** Anthropic, OpenAI, Mistral, Groq,
  OpenRouter, Cloudflare Workers AI, vLLM, LM Studio… ou un modèle qui tourne sur
  votre machine avec Ollama, d'où votre code ne sort jamais. Changez de
  fournisseur en pleine session, sans redémarrer.
- **Un seul fichier, rien à installer.** Un exécutable unique pour Linux et
  macOS : ni PHP, ni Node, ni Python, ni Docker.
- **Il demande avant d'agir.** Lire est libre ; écrire un fichier, lancer une
  commande ou lire une page web demande votre accord — une fois, pour la session,
  ou toujours pour ce projet.
- **Il connaît votre projet.** Une mémoire des faits durables par projet, et une
  recherche dans votre documentation par mots-clés et par le sens, sources citées.
- **Mesuré, pas supposé.** Chaque choix de conception a été gardé parce qu'un
  benchmark le justifiait ; les chiffres, et ce qui a été rejeté, sont
  [publiés](https://github.com/hbenabdallah/sherpa/blob/main/docs/design-choices.md) (en anglais).

## Fonctionnalités

- **Outils sur le code** : lister, trouver, chercher et lire des fichiers ; les
  écrire et les modifier avec un aperçu ; un fichier PHP ou JSON qui ne serait
  plus valide est refusé avant d'être écrit.
- **Commandes** : lancer vos tests ou n'importe quelle commande, dans le
  conteneur Docker du projet s'il en a un, ou laisser un serveur tourner en
  arrière-plan et lire sa sortie.
- **Recherche dans la documentation** (RAG) : Markdown, texte, reStructuredText,
  AsciiDoc et HTML, découpés selon leurs titres, cherchés par mots-clés et par le
  sens à la fois, index tenu à jour tout seul.
- **Mémoire du projet** : les conventions, l'emplacement de la configuration, la
  commande des tests — gardés dans SQLite et classés selon ce sur quoi vous
  travaillez.
- **Longues sessions** : les vieilles sorties d'outils sortent de la fenêtre de
  contexte et peuvent être rappelées ; les conversations sont enregistrées à
  chaque tour et reprises avec `/resume`.
- **Mode plan** : le modèle lit et propose, et rien n'est écrit ni lancé avant que
  vous approuviez son plan.
- **Sous-agents** pour les questions qui demandent beaucoup de lecture, qui ne
  rapportent que leurs conclusions.
- **Skills** (fichiers Markdown) et **serveurs MCP** (outils, resources et
  prompts), au format `mcpServers` des autres clients.

## Démarrage rapide

**1. Installer** — un seul fichier pour Linux (x86_64, aarch64) et macOS (Apple Silicon) :

```sh
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh
```

**2. Le lancer dans un projet :**

```sh
cd ~/code/mon-projet
sherpa
```

**3. Répondre à deux questions** la première fois : l'adresse du fournisseur (par
exemple `https://api.openai.com/v1`, ou Entrée pour un modèle local avec Ollama)
et sa clé, puis choisir un modèle dans la liste du fournisseur. Ensuite,
demandez : *« Présente-moi ce projet »*, *« Pourquoi ce test échoue ? »*,
*« Ajoute une option `--dry-run` à la commande d'import »*.

## Installation

### L'exécutable

```sh
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh
```

Le script prend le fichier de votre machine dans la
[dernière release](https://github.com/hbenabdallah/sherpa/releases/latest),
vérifie sa somme SHA-256 et le met dans `~/.local/bin` (il le signale si ce
dossier n'est pas dans votre `PATH`). Le relancer met à jour. Une version
donnée, un autre dossier, ou la désinstallation :

```sh
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh -s -- --version 0.1.5
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh -s -- --dir ~/bin
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh -s -- --uninstall
```

À la main : prenez `sherpa-<version>-<plateforme>` et son `.sha256` sur la page
[Releases](https://github.com/hbenabdallah/sherpa/releases), vérifiez-le,
rendez-le exécutable et mettez-le dans votre `PATH`. Sur macOS, un fichier
téléchargé avec un navigateur est mis en quarantaine et refusé au lancement :
`xattr -d com.apple.quarantine sherpa` la lève.

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
le tour en cours ; à une invite vide, il quitte. Pour un message sur plusieurs
lignes, **Alt+Entrée** (Option+Entrée sur Mac) ou `\` puis Entrée passe à la
ligne — Ctrl+Entrée vaut Entrée dans la plupart des terminaux. Un texte collé
arrive en entier, sauts de ligne compris, et part quand vous appuyez sur Entrée ;
au-delà de cinq lignes ou de mille caractères, il s'affiche comme
`[Pasted text #1 +25 lines]`, et le modèle le reçoit quand même en entier.

### Outils

| Outil | Rôle | Demande d'abord |
|---|---|---|
| `list_dir`, `file_find`, `project_grep`, `file_read` | trouver et lire le code : l'arborescence, les fichiers par motif de nom, le texte dedans | non |
| `doc_search` | chercher dans la documentation du projet | non |
| `delegate` | confier une question qui demande beaucoup de lecture à un sous-agent, qui ne rapporte que ses conclusions | non |
| `file_write`, `file_patch` | écrire ou modifier un fichier, avec un aperçu | **oui** |
| `shell_exec` | lancer une commande — dans le conteneur du projet s'il en a un — ou la laisser tourner en arrière-plan | **oui** |
| `job_output`, `job_stop` | lire ce qu'une commande en arrière-plan a affiché, l'arrêter avec ce qu'elle a lancé | non |
| `web_fetch` | lire une page web en texte : la doc d'une librairie, une erreur expliquée en ligne | **oui** |
| `memory_remember`, `memory_recall` | garder et retrouver des faits durables sur le projet | non |
| `context_recall` | rappeler une sortie d'outil retirée du contexte | non |
| `skill_load` | charger un skill (voir plus bas) | non |
| `todo_write` | tenir un plan pour une tâche en plusieurs étapes, affiché à chaque mise à jour | non |

Une confirmation propose **[a]** une fois, **[s]** pour la session, **[p]**
toujours pour ce projet, **[r]** refuser. `/permissions` liste les autorisations
permanentes et les retire.

### Commandes

| Commande | |
|---|---|
| `/help` | toutes les commandes |
| `/model`, `/backend` | changer de modèle, ou passer du modèle en ligne au local |
| `/provider` · `/provider add` · `/provider <nom>` · `/provider key <nom>` · `/provider remove <nom>` | les fournisseurs d'API : lister, ajouter, changer, changer de clé, oublier |
| `/docs` · `/docs <question>` · `/docs eval` | l'index de la documentation · ce que le modèle recevrait · la qualité de la recherche sur vos propres questions |
| `/memory`, `/remember <k> <v>`, `/forget <k>` | la mémoire du projet |
| `/sessions`, `/resume [n]` | les conversations enregistrées |
| `/context`, `/compact` | ce qui remplit la fenêtre de contexte, les limites du fournisseur, et la compacter tout de suite |
| `/copy` | la dernière réponse dans le presse-papiers, telle que le modèle l'a écrite (Markdown) |
| `/plan` · `/plan off` | le mode plan : le modèle lit et propose, et rien n'est écrit ni lancé avant que vous approuviez son plan |
| `/todo` | le plan que le modèle tient pour la tâche en cours |
| `/jobs` · `/jobs stop <id>` | les commandes laissées en arrière-plan, et en arrêter une |
| `/project`, `/project edit`, `/project forget` | le projet courant |
| `/permissions` · `/permissions revoke <outil>` · `/permissions clear` | les autorisations permanentes, et les retirer |
| `/mcp` · `/mcp add` · `/mcp disable\|enable\|remove <nom>` · `/<serveur>:<prompt>` | les serveurs MCP avec leurs resources et prompts · en déclarer un, essayé aussitôt · en couper un, le rallumer ou l'oublier · lancer un de leurs prompts |
| `/skills`, `/tools` | ce que le modèle peut charger, et ce qu'il peut appeler |
| `/reset`, `/exit` | une conversation vide · quitter (Ctrl+C à une invite vide aussi) |

À l'invite, `/` ouvre la liste des commandes, des skills et des prompts MCP,
filtrée à mesure de la frappe (↑↓ pour choisir, Tab pour compléter, Entrée pour
lancer). Un skill se lance comme une commande : `/<skill> [demande]` donne au
modèle son texte avec votre demande. **Page Haut** ouvre toute la session en
plein écran, pour remonter dedans (PgUp/PgDn, ↑↓, Début/Fin ; `q`, Échap ou
Entrée pour revenir).

Options : `-b api|ollama` (backend pour cette exécution), `-P <fournisseur>`
(un fournisseur d'API enregistré, pour cette exécution), `-m <modèle>`,
`-c <tokens>` (fenêtre de contexte), `-r [n]` (reprendre la dernière
conversation, ou la n-ième).

## Comment ça marche

**La mémoire du projet.** Les faits durables — une convention, l'emplacement de
la configuration, la commande qui lance les tests — sont gardés par projet dans
SQLite, par le modèle au fil du travail et par un court passage en fin de
session. Un résumé entre dans chaque prompt, classé selon ce sur quoi vous
travaillez : les fichiers touchés dans git, le dossier de lancement.

**La documentation du projet.** Les fichiers sont découpés selon leurs titres,
sans jamais couper un tableau ni un bloc de code, et cherchés par mots-clés et
par le sens à la fois. Le modèle reçoit la section entière autour de chaque
résultat, avec sa source à citer, et la consigne de le dire quand la
documentation ne répond pas. L'index se met à jour tout seul avant chaque
recherche.

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

**Les serveurs MCP** ajoutent leurs outils, qui demandent toujours avant de
s'exécuter. On en déclare un avec `/mcp add` dans une session — ses outils sont
là tout de suite — ou depuis le shell :

```sh
sherpa mcp add filesystem -- npx -y @modelcontextprotocol/server-filesystem ~/notes
sherpa mcp add tracker --url https://example.com/mcp -H "Authorization: Bearer …"
sherpa mcp list          # et : disable, enable, remove <nom>
```

Les deux écrivent `~/.config/sherpa/mcp.json`, au format `mcpServers` des autres
clients : une configuration venue d'ailleurs s'y copie telle quelle. Les
resources d'un serveur sont lues par le modèle via un outil de plus, et ses
prompts se lancent comme des commandes `/<serveur>:<prompt>`. Voir
[`mcp.json.dist`](https://github.com/hbenabdallah/sherpa/blob/main/mcp.json.dist).
Sur un projet PHP, [phpgraph](https://github.com/hbenabdallah/phpgraph) donne à
Sherpa le graphe d'appels du code par MCP.

La suite, en anglais, est dans la [documentation](https://github.com/hbenabdallah/sherpa/blob/main/docs/README.md) :
[concepts](https://github.com/hbenabdallah/sherpa/blob/main/docs/concepts.md),
[guides pratiques](https://github.com/hbenabdallah/sherpa/blob/main/docs/how-to.md) et
[choix de conception](https://github.com/hbenabdallah/sherpa/blob/main/docs/design-choices.md).

## Mesuré, pas supposé

Un choix n'a été gardé que si une mesure le justifiait (le détail, y compris
ce qui a été rejeté, est dans [les choix de conception](https://github.com/hbenabdallah/sherpa/blob/main/docs/design-choices.md), en anglais) :

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
- **Un fournisseur surchargé.** Sur une offre gratuite chargée, le fournisseur
  interrompait les réponses avec « overloaded » en plein flux : 11 sessions de
  bench sur 20 perdues. Redemander, quand rien n'était encore affiché, a fait
  passer la passe de 9/20 à 16/20, sans plus aucune session perdue à cause du
  fournisseur.

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
| `SHERPA_BACKEND` | `api` ou `ollama`, l'emporte sur `config.yaml` |
| `SHERPA_API_URL`, `SHERPA_API_MODEL`, `SHERPA_API_CONTEXT` | l'adresse, le modèle et la fenêtre de contexte de l'API, l'emportent sur `config.yaml` |
| `SHERPA_EMBEDDING_MODEL` | impose un modèle d'embeddings |
| `SHERPA_TEMPERATURE`, `SHERPA_MAX_TOKENS` | température d'échantillonnage (0,15 par défaut) et plafond d'une réponse (4096 par défaut) |
| `OLLAMA_URL` | où répond Ollama (par défaut `http://localhost:11434`) |
| `OLLAMA_MODEL`, `OLLAMA_CTX`, `OLLAMA_KEEP_ALIVE` | le modèle d'Ollama, sa fenêtre de contexte, et combien de temps il reste chargé (`30m` par défaut) |
| `SHERPA_MAX_SESSION_TOKENS` | arrête une session au-delà de ce nombre de tokens |
| `SHERPA_FACT_EXTRACTION=off` | pas de passage mémoire en fin de session |

Depuis les sources, les mêmes réglages peuvent aller dans `.env` (voir
`.env.dist`).

## FAQ

**Où va mon code ?** Chez le fournisseur que vous avez configuré, et nulle part
ailleurs : son adresse est affichée au début de chaque session et dans
`/context`, et une clé n'est jamais envoyée qu'au fournisseur pour lequel elle a
été donnée. Avec un modèle local par Ollama, rien ne quitte votre machine.

**Peut-il modifier mes fichiers ou lancer des commandes sans demander ?** Non.
Écrire un fichier, lancer une commande et lire une page web demandent toujours
d'abord, sauf si vous avez autorisé cet outil pour la session ou le projet ;
`/permissions` affiche et retire ces autorisations. Les outils de fichiers sont
confinés au dossier du projet.

**Quels modèles fonctionnent ?** Tout modèle qui sait appeler des outils, en
ligne ou par Ollama. Sherpa est une boucle d'appels d'outils, et le dit quand un
modèle ne sait pas le faire.

**Faut-il PHP ?** Non. L'exécutable embarque son propre PHP. Sherpa travaille
sur des projets dans n'importe quel langage ; il est écrit en PHP 8.4 et
Symfony 8.

**Combien ça coûte ?** Ce que votre fournisseur facture pour les tokens. Ajoutez
les tarifs du modèle dans `config.yaml` et Sherpa affiche le coût de la
session ; `SHERPA_MAX_SESSION_TOKENS` fixe un plafond. Un modèle local ne coûte
rien.

## Limites connues

- L'exécutable existe pour Linux (x86_64, aarch64) et les Mac Apple Silicon ;
  pas pour les Mac Intel ni Windows.
- Le modèle doit savoir appeler des outils.
- Le modèle d'embeddings doit venir du même fournisseur que le modèle de chat.

## Contribuer

Les issues et pull requests sont les bienvenues sur
[GitHub](https://github.com/hbenabdallah/sherpa/issues). Tout tourne dans
Docker :

```sh
make test       # toutes les suites, contre des faux — aucun modèle appelé
make lint       # les scripts shell
make bench      # des tâches réalistes contre un vrai modèle (coûte un peu)
make release VERSION=1.2.0        # l'exécutable pour cette machine, vérifié de bout en bout
make release-all VERSION=1.2.0    # l'exécutable pour chaque plateforme
```

`make release` emballe Sherpa dans un phar collé derrière le runtime PHP statique
`micro` de la plateforme (`PLATFORM=linux-aarch64` en construit une autre),
épinglé par somme de contrôle, puis le lance contre un faux fournisseur
(`tests/release/smoke.sh`) quand la plateforme est celle de la machine. Un tag
`v1.2.0` construit toutes les plateformes en CI, lance chaque exécutable sur sa
propre plateforme, puis les publie avec leur somme SHA-256 et
`THIRD-PARTY-NOTICES.md` dans les
[Releases](https://github.com/hbenabdallah/sherpa/releases).

Si Sherpa vous est utile, une ⭐ sur [GitHub](https://github.com/hbenabdallah/sherpa)
aide d'autres à le trouver.

## Auteur

Sherpa est développé par **Houssem Eddine BENABDALLAH** —
[@hbenabdallah](https://github.com/hbenabdallah) sur GitHub, aussi auteur de
[phpgraph](https://github.com/hbenabdallah/phpgraph), un graphe de connaissances
des projets PHP pour les agents de code.

## Licence

Sherpa est distribué sous la
[PolyForm Shield License 1.0.0](https://github.com/hbenabdallah/sherpa/blob/main/LICENSE),
une licence « source disponible » : vous pouvez l'utiliser, le modifier et le
redistribuer, y compris en entreprise, pour tout usage qui ne concurrence pas
Sherpa. Il est interdit de fournir, à partir de ce code, un produit concurrent.
Ce n'est pas une licence open source approuvée par l'OSI.

L'exécutable embarque aussi PHP et des paquets Composer sous leurs propres
licences, listées dans `THIRD-PARTY-NOTICES.md` à chaque version.
