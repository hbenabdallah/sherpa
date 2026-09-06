# Sherpa

Un agent de développement en terminal, écrit en PHP 8.4 / Symfony 8, qui parle à
un modèle servi localement. Il lit et modifie le code d'un projet, exécute des
commandes, garde en mémoire ce qu'il apprend d'un projet d'une session à
l'autre, et demande avant tout ce qui écrit.

État : bêta. Utilisé quotidiennement par son auteur, sur une seule machine.

## Ce dont il a besoin

- **Docker** (le démon, et votre utilisateur dans le groupe `docker`). C'est le
  seul prérequis système : PHP, Composer et les extensions vivent dans l'image.
- **Ollama**, ou tout autre service exposant son API, avec un modèle qui sait
  appeler des tools. Lequel est demandé au premier lancement — il n'y a pas de
  modèle imposé, et rien n'est écrit en dur dans le dépôt.

Aucune de ces deux choses ne demande les droits root — voir plus bas.

## Installation

```sh
git clone <dépôt> ~/workspace/sherpa
cd ~/workspace/sherpa

make install    # .env, image Docker, dépendances Composer
make link       # installe la commande `sherpa` dans ~/.local/bin
make doctor     # vérifie tout et dit précisément ce qui manque
```

`make doctor` est le point d'entrée quand quelque chose ne va pas. Il vérifie
Docker, la cohérence de `.env` avec votre utilisateur, la racine des projets,
Ollama, le modèle, et le lanceur — et donne la correction à côté de chaque
échec.

## Le premier lancement

Au tout premier `sherpa`, une seule question se pose : **quel modèle ?**

```
┌──────────────── Sherpa · Quel modèle ? ─────────────────┐
│  3 modèles sur Ollama — ce choix sera retenu.           │
│                                                          │
│  > qwen3-coder:30b        30.5B    19 GB    256k        │
│    devstral-small-2:24b   24.0B    15 GB    384k        │
│    qwen2.5-coder:7b        7.6B   4.7 GB     32k        │
│    ──────────────────────────────────────────           │
│    + Un autre modèle (saisir un nom)                     │
│                                                          │
│  ↑↓ naviguer · ↵ choisir · a saisir un nom · q quitter  │
└──────────────────────────────────────────────────────────┘
```

La liste est celle du serveur, donc elle est juste sur n'importe quelle machine
sans que rien n'ait été prévu à l'avance. Mais elle n'est pas une limite :
`a` permet de saisir **n'importe quel nom**, y compris un modèle jamais
récupéré — Sherpa interroge le serveur, dit ce qu'il en sait, et rappelle la
commande `ollama pull` s'il manque.

Vient ensuite la fenêtre de contexte, avec le plafond natif du modèle affiché.
Ce plafond n'est pas un réglage : la fenêtre demandée occupe de la VRAM **en
plus** des poids, et quand il n'y en a plus, Ollama bascule sur le processeur
sans le dire. La règle est de surveiller `ollama ps` — tant qu'il affiche
`100% GPU`, c'est tenable.

Deux garde-fous : un modèle incapable d'appeler des tools est **refusé**
(Sherpa n'est qu'une boucle d'appels d'outils — il parlerait sans jamais agir),
et une fenêtre au-delà du plafond du modèle est ramenée à ce plafond.

La réponse est écrite dans `~/.config/sherpa/config.yaml`, **hors du dépôt** :
le même clone tourne sur un portable contre un 7B et sur une machine à GPU
contre un 30B, sans qu'aucune des deux ne touche un fichier suivi par git.

## Utilisation

```sh
cd ~/workspace/mon-projet
sherpa                  # ouvre ce projet directement
```

Lancé depuis un répertoire que Sherpa connaît, il ouvre ce projet sans passer
par le menu. Ailleurs, il affiche la liste : `↵` ouvre, `n` ajoute, `e` modifie
(nom, chemin, conteneur Docker), `d` supprime après confirmation — avec la
mémoire du projet.

```sh
sherpa -p mon-projet            # par son slug, depuis n'importe où
sherpa -m qwen3-coder:30b       # avec un autre modèle, pour cette fois
sherpa -m devstral-small-2:24b -c 65536   # et une autre fenêtre
```

Dans la session : `/help` liste les commandes. Les plus utiles sont `/model`,
`/memory`, `/permissions`, `/context`, `/compact` et `/mcp`.

`/model` rouvre l'écran ci-dessus ; `/model <nom>` bascule directement.

**Changer de modèle repart de zéro.** L'historique n'est pas neutre : il porte
des appels d'outils au format du modèle précédent, ses réponses, et une longueur
choisie pour sa fenêtre. Le donner à un modèle qui ne l'a jamais vu — souvent
plus petit — est la façon dont une bascule se transforme en agent qui se
comporte bizarrement pour le reste de la session.

| Réinitialisé | Conservé |
|---|---|
| la conversation | la mémoire du projet |
| la calibration `chars/token` | les autorisations permanentes |
| les compteurs de tokens du serveur | les serveurs MCP |

La confirmation n'est demandée **que s'il y a quelque chose à perdre** : changer
de modèle dans les premières secondes d'une session n'écarte rien et ne pose
aucune question. En cours de conversation, Sherpa dit combien de messages
seraient écartés et attend un `o` — Entrée seule annule la bascule et laisse
tout en place.

Retoucher la fenêtre du **même** modèle ne réinitialise rien : même tokenizer,
même template, l'historique est toujours le sien. Sherpa signale seulement s'il
ne tient plus dans la nouvelle fenêtre.

### D'où vient le modèle utilisé

Le premier qui répond gagne :

| | |
|---|---|
| `-m` / `-c` | cette exécution |
| `/model` | cette session |
| `~/.config/sherpa/config.yaml` | cette machine |
| `.env` | dernier recours, et exécutions non interactives |

`/context` affiche le modèle en cours et l'origine du choix.

**Ctrl+C** annule le tour en cours sans perdre la conversation ; une seconde
pression quitte.

## Où sont les choses

| | |
|---|---|
| `~/.config/sherpa/config.yaml` | le modèle et la fenêtre choisis **sur cette machine** |
| `~/.config/sherpa/projects.yaml` | les projets connus, et leurs autorisations permanentes |
| `~/.config/sherpa/projects/<slug>/memory.db` | ce que Sherpa a retenu de ce projet |
| `~/.config/sherpa/skills/*.md` | des instructions chargées à la demande |
| `~/.config/sherpa/mcp.json` | les serveurs MCP (voir `mcp.json.dist`) |
| `.env` | URL d'Ollama, racine des projets, et les valeurs de repli |

`SHERPA_PROJECTS_ROOT` est monté dans le conteneur **au même chemin absolu**
que sur l'hôte. Les projets stockent des chemins absolus : si les deux côtés ne
s'accordent pas, les tools lisent des fichiers qui n'existent pas. `make doctor`
le vérifie.

## Sans droits root

La machine sur laquelle Sherpa a été écrit n'a pas de sudo. Tout ce qui suit
tient dans l'espace utilisateur :

```sh
# Ollama, sans installateur système
curl -L https://ollama.com/download/ollama-linux-amd64.tgz | tar -xz -C ~/.local
~/.local/bin/ollama serve &          # ou une unité systemctl --user
~/.local/bin/ollama pull qwen2.5-coder:7b
```

Docker demande en revanche que votre utilisateur soit **dans le groupe
`docker`** — c'est la seule chose qu'un administrateur doit faire une fois.

## Tests

```sh
make test                                                    # les suites hermétiques
docker compose run --rm app php tests/e2e_live.php           # contre un vrai modèle
docker compose run --rm app php tests/e2e_memory_live.php    # la mémoire, bout en bout
```

Les fichiers `*_test.php` tournent sans réseau ni modèle. Les `e2e_*_live.php`
demandent un Ollama qui répond et prennent plusieurs minutes sur CPU ; ils ne
sont pas lancés par `make test`.

## Serveurs MCP

Sherpa parle le Model Context Protocol : un serveur écrit par quelqu'un d'autre
peut ajouter ses tools. Copiez `mcp.json.dist` vers `~/.config/sherpa/mcp.json`.

Deux choses à savoir. La commande du serveur est lancée **depuis le conteneur
de Sherpa**, qui ne contient que PHP : un serveur en `npx` ou `uvx` demande
d'ajouter Node ou Python au `Dockerfile`. Et chaque tool distant coûte environ
150 tokens de contexte **à chaque requête** — n'en branchez que ce que vous
utilisez vraiment.

## Limites connues

- Un tour dure de deux secondes à quatre minutes selon le modèle et la machine.
  Sherpa est écrit pour cet écart, pas pour l'une de ses extrémités : les
  défenses qui rendent le bout lent supportable — Ctrl+C au niveau du tour,
  timeout d'inférence désactivé, compaction à deux étages dont le premier est
  gratuit — ne coûtent rien au bout rapide. Sherpa ne touche jamais le GPU
  lui-même (c'est Ollama qui le fait), donc passer sur une machine avec une
  carte ne demande aucune configuration Docker particulière.
- Ce qui s'adapte vraiment à la fenêtre, c'est le bloc mémoire du prompt
  système : une part de l'allocation plutôt qu'un nombre fixe, avec un plancher
  qui préserve le comportement des petites fenêtres et un plafond parce que ce
  bloc est spéculatif.
- Le modèle est libre, mais il doit savoir appeler des tools. Les variantes
  « coder » sont souvent moins fiables là-dessus que les modèles généralistes
  de même taille ; `App\Agent\Tool\TextToolCallParser` rattrape les appels
  écrits en prose, et devient inerte avec un modèle qui s'en sort seul.
- L'interface et les réponses sont en français, en dur.
- Un seul backend est implémenté (Ollama), mais il est derrière
  `PlatformInterface` : en ajouter un est une classe et une ligne de
  configuration.
