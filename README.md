# Sherpa

Un agent de développement en terminal, écrit en PHP 8.4 / Symfony 8, qui parle à
un modèle servi localement. Il lit et modifie le code d'un projet, exécute des
commandes, garde en mémoire ce qu'il apprend d'un projet d'une session à
l'autre, et demande avant tout ce qui écrit.

**Local, pas lent.** Sherpa vise une machine de travail — processeur et carte
graphique — et traite l'absence de carte comme le bas de la même échelle, pas
comme un mode à part. Il n'y a pas de réglage « machine rapide » : Ollama
renvoie le débit réel à chaque réponse, Sherpa le lit, et les décisions qui en
dépendent se prennent sur une mesure plutôt que sur une constante. Voir
[Ce que Sherpa mesure](#ce-que-sherpa-mesure).

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
sans le dire.

C'était à l'utilisateur de surveiller `ollama ps`. Sherpa le fait désormais
lui-même : après la première réponse il interroge le serveur et signale un
débordement, parce que c'est le seul événement qui annule silencieusement
l'intérêt d'une carte graphique.

```
⚠ Le modèle déborde sur le processeur (62 % GPU, le reste débordé sur le CPU).
  La fenêtre demandée (131 072 tokens) occupe de la VRAM en plus des poids :
  réduisez-la avec /model pour que tout tienne sur la carte.
```

`/context` le redit à la demande, à côté du débit mesuré.

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

`/context` affiche le modèle en cours, l'origine du choix, le budget de tokens,
et le débit réellement mesuré sur cette machine.

**Ctrl+C** annule le tour en cours sans perdre la conversation ; une seconde
pression quitte.

## Ce que Sherpa mesure

Sherpa a été écrit sur une machine sans carte graphique, et une dizaine de
constantes l'encodaient sans le dire : combien d'itérations un tour peut
prendre, combien de sortie de commande vaut la peine d'être gardée, s'il est
envisageable de demander au modèle de résumer son propre historique. Chacune
était une supposition sur la vitesse, figée en nombre.

La mesure était pourtant déjà sur le fil. Ollama termine chaque réponse par
`prompt_eval_duration` et `eval_duration`, à côté des compteurs de tokens ;
Sherpa les lisait sans les garder. Deux divisions en font des tokens par
seconde, à chaque tour, sans sonde ni benchmark ni option.

```
$ /context
…
Machine:
  prefill                1847 tokens/s
  génération             38.4 tokens/s
  résidence du modèle    100 % GPU
  relecture du contexte  ~7.2 s
```

Ce qui en découle :

| Décision | Avant | Maintenant |
|---|---|---|
| Résumer l'historique, ou élider les sorties d'outils | Élider d'abord, toujours : une passe d'inférence était réputée chère | Résumer d'abord quand la passe tient dans ~20 s, sinon élider. Élider est gratuit mais détruit le contenu, et le modèle relit le fichier au tour suivant |
| Fenêtre non mesurée | — | Branche économe, celle que veut une machine lente |

Les deux bouts de l'échelle passent par le même code ; ce qui change est un
nombre de secondes, pas un mode.

### Compacter rarement et profondément

Ollama réutilise le préfixe commun entre deux requêtes : un tour ordinaire ne
repaie le prefill que sur les tokens ajoutés. Mais la compaction réécrit
l'historique **par le début**, ce qui invalide ce cache et fait relire toute la
fenêtre au tour suivant. C'est la plus grosse dépense récurrente d'une longue
session, et elle se paie **par compaction**.

Déclenchement et cible étaient le même nombre, donc chaque compaction libérait
juste assez pour repasser sous le seuil — et le tour d'après le recroisait.
Elles sont maintenant distinctes : on déclenche à 75 % de l'allocation, on
compacte jusqu'à 50 %. Même travail, payé bien moins souvent.

### Tourner en rond n'est pas travailler

La boucle s'arrêtait à dix itérations. Sur une machine sans carte c'était une
clémence — dix tours à trois minutes font une demi-heure. Mais c'était un
plafond sur le **travail** : chercher, lire, lire, corriger, lancer les tests,
lire l'échec, corriger, relancer, vérifier fait déjà neuf étapes d'une demande
ordinaire.

Le plafond dur est passé à 40, et ce qui arrête vraiment un modèle bloqué est
détecté directement : **trois appels d'outil identiques d'affilée** et le tour
s'arrête, sur n'importe quelle machine. Des arguments qui changent sont du
travail, pas une boucle.

## Retrouver sans redemander

Une recherche pilotée par le modèle coûte **un tour complet** : un appel
d'outil, un résultat réinjecté, et un prefill de tout l'historique. Le goulot de
Sherpa n'est donc pas la qualité de la recherche mais le **nombre
d'allers-retours** — et les trois mécanismes ci-dessous n'en coûtent aucun.

### La compaction ne détruit plus rien

Une sortie d'outil élidée partait à la poubelle : le seul chemin de retour vers
son contenu était de relancer l'outil, donc un tour et un prefill de plus. Elle
part maintenant dans un index plein texte tenu en mémoire pour la durée de la
session, et le talon laissé dans la conversation cite l'identifiant :

```
[… sortie de file_read élidée pour économiser le contexte : 412 lignes, 18 300 caractères.
  Toujours consultable sans relancer l'outil : context_recall(search: "…") ou context_recall(id: 3).]
```

`context_recall` rend les lignes qui correspondent, numérotées, avec leur
contexte — pas le fichier entier, ce qui annulerait la compaction qui l'a mis
là. L'élision cesse d'être une perte pour devenir de la pagination.

Le stockage est **en mémoire, pas sur disque** : c'est le contenu d'une
conversation, et un extrait d'un fichier modifié depuis est pire que pas
d'extrait. Il meurt avec le processus ; `/reset` et un changement de modèle le
vident plus tôt.

### Le prompt système est classé par la situation

`digest()` classait par récence, faute de question à laquelle se raccrocher. Il
n'y a effectivement pas de *phrase* au moment où le prompt est construit — mais
il y a ce que `git` a touché depuis la dernière session et le répertoire d'où
l'utilisateur a lancé (`SHERPA_CWD`). Un fait qui parle de `src/Payment/` est
cité en entier le matin où quelqu'un travaille dans `src/Payment/`, et réduit à
une ligne dans l'index de clés les autres matins.

Aucune inférence : juste du tri. Hors d'un dépôt git, le classement retombe sur
ce qu'il faisait avant.

### Un fait gagne sa place

Un `memory_recall` explicite compte : le fait remonte dans le digest la session
suivante. Construire le prompt ne compte pas — sinon tout serait « utilisé » tout
le temps et le signal ne mesurerait rien.

`/memory` affiche au passage la proportion de recherches restées sans résultat.
C'est la mesure qui tranchera une question laissée ouverte : une recherche vide
sur un projet qui a des faits, c'est le modèle qui demande « connexion
utilisateur » quand le fait est classé sous `auth_strategy`. Si ce taux reste
bas, BM25 suffisait. S'il grimpe, c'est l'argument chiffré pour des embeddings —
mesuré plutôt que supposé.

## Où sont les choses

| | |
|---|---|
| `~/.config/sherpa/config.yaml` | le modèle et la fenêtre choisis **sur cette machine** |
| `~/.config/sherpa/projects.yaml` | les projets connus, et leurs autorisations permanentes |
| `~/.config/sherpa/projects/<slug>/memory.db` | ce que Sherpa a retenu de ce projet |
| *(en mémoire, non persisté)* | les sorties d'outils retirées du contexte de la session en cours |
| `~/.config/sherpa/skills/*.md` | des instructions chargées à la demande |
| `~/.config/sherpa/mcp.json` | les serveurs MCP (voir `mcp.json.dist`) |
| `.env` | URL d'Ollama, racine des projets, durée de résidence du modèle, et les valeurs de repli |

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
  timeout d'inférence désactivé, compaction à deux étages — ne coûtent rien au
  bout rapide, et ce qui rend le bout rapide utile — fenêtre large, sorties
  d'outils généreuses, résumé plutôt qu'élision — retombe sur ses planchers au
  bout lent. Sherpa ne touche jamais le GPU lui-même (c'est Ollama qui le
  fait), donc passer sur une machine avec une carte ne demande aucune
  configuration Docker particulière.
- Les plafonds sont des **planchers** : bloc mémoire du prompt système, sortie
  de `shell_exec`, résultats de `project_grep`, transcription lue à la sortie.
  Chacun est une part de l'allocation plutôt qu'un nombre fixe, avec la valeur
  d'origine comme plancher — une petite fenêtre se comporte exactement comme
  avant, une grande cesse d'être tronquée à des tailles choisies pour 32k.
- Les appels d'outils d'un même tour s'exécutent en série. Paralléliser les
  outils en lecture seule est envisageable, mais le seul vraiment lent est
  `project_grep` : `file_read` et `memory_recall` sont sous la milliseconde, et
  `shell_exec` demande une confirmation modale qui doit rester séquentielle. Le
  gain ne payait pas encore la complexité sur l'ordre des résultats.
- Le modèle est libre, mais il doit savoir appeler des tools. Les variantes
  « coder » sont souvent moins fiables là-dessus que les modèles généralistes
  de même taille ; `App\Agent\Tool\TextToolCallParser` rattrape les appels
  écrits en prose, et devient inerte avec un modèle qui s'en sort seul.
- L'interface et les réponses sont en français, en dur.
- Un seul backend est implémenté (Ollama), mais il est derrière
  `PlatformInterface` : en ajouter un est une classe et une ligne de
  configuration.
