<p align="center"><img src="public/sherpa.jpeg" alt="Sherpa, a coding agent for the terminal" width="720"></p>

# Sherpa

**English** · [Français](README.fr.md)

A coding agent for your terminal. Launch it in a project and ask in plain words:
it reads the code, searches the project's documentation, edits files, runs your
tests — and asks before anything that writes or executes.

It runs on a model **online**, through Anthropic's API or any OpenAI-compatible
one (OpenAI, Mistral, Groq, OpenRouter, Cloudflare Workers AI, vLLM, LM
Studio…), or **on your machine** with Ollama. Written in PHP 8.4 and Symfony 8; MIT-licensed.

```
$ cd ~/code/shop
$ sherpa
✓ Model: gpt-4o-mini (online, api.openai.com · 32,768 tokens · machine config)
✓ Project loaded: shop (PHP)

❯ Why is the invoice subtotal wrong when a line has a quantity above 1?
  → Tool: project_grep pattern=subtotal
  → Tool: file_read path=src/Billing/Invoice.php
Sherpa  Invoice::subtotal() adds each product's price once and never multiplies
        it by $line->quantity (src/Billing/Invoice.php, line 42)…
```

More in [docs/](docs/README.md): [concepts](docs/concepts.md),
[how-to guides](docs/how-to.md) and the [design choices](docs/design-choices.md),
with the measurements behind them.

## Install

### The executable

One file for Linux (x86_64, aarch64) and macOS (Apple Silicon) — no PHP, no
Docker, nothing else to install:

```sh
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh
```

The script picks the file for your machine from the latest
[release](../../releases), checks its SHA-256, and puts it in `~/.local/bin`
(it says so if that is not in your `PATH`). Run it again to update. A given
version, another directory, or removing it:

```sh
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh -s -- --version 0.1.5
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh -s -- --dir ~/bin
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/sherpa/main/install.sh | sh -s -- --uninstall
```

By hand, take `sherpa-<version>-<platform>` and its `.sha256` from the
[Releases](../../releases) page, check it, make it executable, and put it in
your `PATH`. On macOS, a file downloaded with a browser is quarantined and
refused at launch: `xattr -d com.apple.quarantine sherpa` lifts that.

### From the source

Needs Docker (to install the dependencies) and `make`:

```sh
git clone https://github.com/hbenabdallah/sherpa.git && cd sherpa
make install   # .env, the Docker image, the Composer dependencies
make php       # Sherpa's own static PHP, so it runs on your machine (no root)
make link      # a `sherpa` command in ~/.local/bin
make doctor    # checks everything, and says what is missing
```

With `make php`, Sherpa runs on your machine, where its commands reach your own
tools. Without it, the `sherpa` command falls back to the Docker container,
which only sees projects under `SHERPA_PROJECTS_ROOT` and holds nothing but PHP.

## First run

```sh
export SHERPA_API_KEY=…      # optional: without it, the first launch asks for the key
cd ~/code/my-project
sherpa
```

The first launch on a machine asks for the provider's address — for example
`https://api.openai.com/v1` — and lets you choose a chat model from the
provider's list, with its context window. Both go to
`~/.config/sherpa/config.yaml` and are not asked again. Press Enter at the
address to use a local model with Ollama instead.

Sherpa then looks, at the same provider, for a model that turns text into
vectors, to search your documentation by meaning. Nobody is asked: it reads the
provider's catalogue, or tries the known names on one word. A provider without
one (Anthropic has none) keeps keyword search, and says so.

**Several providers.** `/provider add` asks for an address, a short name
(`groq` for `api.groq.com`) and the key — typed hidden, tried at once — then
moves the session there and shows the provider's models. Nothing to export,
nothing to restart. `/provider groq` switches back and forth, `/provider` lists
them, `/provider key groq` replaces a key, and `sherpa -P groq` starts on one.
Each keeps its own model, and a provider never receives another one's key.

Keys go to `~/.config/sherpa/keys.yaml`, readable by you only — never to
`config.yaml`. A provider can instead name a variable (`key_env: GROQ_API_KEY`
in `config.yaml`), which wins when it is set.

**The directory you launch from is the project.** A new one gets two questions —
its name, and whether its commands run in a Docker container — and is saved at
your first message. Sherpa refuses to start in your home directory or at `/`:
its file tools are confined to the project, and there that would be everything.

## Using it

Type a request; Sherpa works through it with its tools and answers. Ctrl+C stops
the current turn; at an empty prompt it quits.

| Tool | What it does | Asks first |
|---|---|---|
| `list_dir`, `project_grep`, `file_read` | find and read code | no |
| `doc_search` | search the project's documentation | no |
| `file_write`, `file_patch` | write or edit a file, with a preview | **yes** |
| `shell_exec` | run a command — in the project's container if it has one | **yes** |
| `memory_remember`, `memory_recall` | keep and find lasting facts about the project | no |
| `context_recall` | bring back tool output compacted out of the context | no |
| `skill_load` | load a skill (see below) | no |

A confirmation offers **[a]** allow once, **[s]** for the session, **[p]** always
for this project, **[r]** refuse. `/permissions` lists the standing grants and
withdraws them. A PHP or JSON file that would no longer parse is refused before
it is written.

| Command | |
|---|---|
| `/help` | every command |
| `/model`, `/backend` | change model, or move between online and local |
| `/provider` · `/provider add` · `/provider <name>` · `/provider key <name>` · `/provider remove <name>` | the API providers: list, add, switch, change a key, forget |
| `/docs` · `/docs <question>` · `/docs eval` | the documentation index · what the model would be given · search quality on your own questions |
| `/memory`, `/remember <k> <v>`, `/forget <k>` | the project's memory |
| `/sessions`, `/resume [n]` | saved conversations |
| `/context`, `/compact` | what fills the context window, and compacting it now |
| `/project`, `/project edit`, `/project forget` | the current project |
| `/permissions` · `/permissions revoke <tool>` · `/permissions clear` | the standing grants, and withdrawing them |
| `/mcp` · `/<server>:<prompt>` | the MCP servers with their resources and prompts · run one of their prompts |
| `/skills`, `/tools` | what the model can load, and what it can call |
| `/reset`, `/exit` | an empty conversation · leave (Ctrl+C at an empty prompt too) |

At the prompt, `/` opens a list of the commands, skills and MCP prompts, narrowed as you
type (↑↓ to choose, Tab to complete, Enter to run). A skill runs as a command:
`/<skill> [request]` hands the model its text with your request. **Page Up**
opens the whole session full screen, to scroll back through it (PgUp/PgDn,
↑↓, Home/End; `q`, Esc or Enter to come back).

Options: `-b api|ollama` (backend for this run), `-P <provider>` (a recorded
API provider, for this run), `-m <model>`, `-c <tokens>`
(context window), `-r [n]` (reopen the last conversation, or the n-th).

## What makes it work

**The project's memory.** Lasting facts — a convention, where the configuration
lives, which command runs the tests — are kept per project in SQLite, by the
model as it works and by a short pass at the end of a session. A digest goes into
every prompt, ranked by what you are working on now: the files git has touched,
the directory you launched from.

**The project's documentation.** Markdown, text, reStructuredText, AsciiDoc and
HTML files are indexed along their headings, never cutting a table or a code
block, and searched by keywords and by meaning together. The model is given the
whole section around each match, with its source to cite, and is told to say so
when the documentation has no answer. The index updates itself before each
search.

**The context window.** Every turn sends the whole conversation, so its size is
the cost and the latency. Old tool output is moved out of the window — and can be
brought back with `context_recall` — or the oldest turns are summarised,
whichever this machine does faster. The question being worked on is always kept
word for word. Tool output is bounded: a grep that hits a minified file returns
the part around the match, not a megabyte.

**Conversations** are saved after every turn, in the project's directory: a
closed terminal costs one turn, and `/resume` picks one back up.

**Skills** are Markdown files — `~/.config/sherpa/skills/*.md` for every project,
`.sherpa/skills/*.md` in one — whose first paragraph is listed to the model; it
loads the whole file when the subject comes up.

**MCP servers** declared in `~/.config/sherpa/mcp.json` — the `mcpServers` format
other clients use, with a `command` to start or a `url` to reach — add their
tools, which always ask before running. A server's resources are read by the
model through one more tool, and its prompts run as `/<server>:<prompt>`
commands. See `mcp.json.dist`.

## Measured, not assumed

A choice was kept when a measurement said so (the full account, including what
was rejected, is in [design choices](docs/design-choices.md)):

- **Documentation search.** Right section first: keywords 72 % and meaning 94 %
  on a test corpus, but 74 % and 70 % on a real project's documentation (54 real
  questions). Each misses what the other finds, so both are used, fused: 94 % and
  76 %. `make rag-eval` reruns it; `/docs eval` measures it on your project.
- **Questions the documentation cannot answer.** With tool output unbounded, the
  agent handled 6 of 12 well and invented 2 answers; bounded, 12 of 12 and none
  invented. A prompt rule meant to help did not, and was left out.
- **File edits.** `file_patch` first failed on one call in two, over the same few
  slips — backslashes escaped twice, line endings, indentation. Correcting a slip
  only when it has one unambiguous reading brought that to 1 in 22.
- **A provider under load.** On a busy free tier, the provider broke off replies
  with "overloaded" inside the stream: 11 of 20 benchmark sessions were lost to
  it. Asking again, when nothing had reached the screen yet, took the pass from
  9/20 to 16/20, and no session was lost to the provider.

## Configuration

| Where | What |
|---|---|
| `~/.config/sherpa/config.yaml` | backend, API providers (address, model), prices (to show a cost) |
| `~/.config/sherpa/keys.yaml` | the providers' keys, readable by you only |
| `~/.config/sherpa/projects.yaml` | known projects and their standing permissions |
| `~/.config/sherpa/projects/<project>/` | memory, conversations, documentation index |
| `~/.config/sherpa/skills/`, `~/.config/sherpa/mcp.json` | skills and MCP servers for every project |
| `~/.cache/sherpa/` | compiled at first launch by the executable; safe to delete |

| Variable | |
|---|---|
| `SHERPA_API_KEY` | the key of a provider that has none saved nor a variable of its own |
| `SHERPA_BACKEND` | `api` or `ollama`, over what `config.yaml` says |
| `SHERPA_API_URL`, `SHERPA_API_MODEL`, `SHERPA_API_CONTEXT` | the API's address, model and context window, over `config.yaml` |
| `SHERPA_EMBEDDING_MODEL` | force an embedding model |
| `SHERPA_TEMPERATURE`, `SHERPA_MAX_TOKENS` | sampling temperature (default 0.15) and the ceiling on one reply (default 4096) |
| `OLLAMA_URL` | where Ollama answers (default `http://localhost:11434`) |
| `OLLAMA_MODEL`, `OLLAMA_CTX`, `OLLAMA_KEEP_ALIVE` | Ollama's model, context window, and how long it stays loaded (default `30m`) |
| `SHERPA_MAX_SESSION_TOKENS` | stop a session past this many tokens |
| `SHERPA_FACT_EXTRACTION=off` | no memory pass at the end of a session |

From the source, the same settings can live in `.env` (see `.env.dist`).

## Development

```sh
make test       # every suite, in Docker, against fakes — no model is called
make lint       # the shell scripts
make bench      # realistic tasks against a real model (costs a little)
make release VERSION=1.2.0    # the single executable for this machine, checked end to end
make release-all VERSION=1.2.0    # the executable for every platform
```

`make release` packs Sherpa into a phar appended to the static PHP `micro`
runtime of the platform (`PLATFORM=linux-aarch64` builds another one), pinned by
checksum, then runs it against a fake provider (`tests/release/smoke.sh`) when
the platform is this machine's. A tag `v1.2.0` builds every platform in CI, runs
each executable on its own platform, and publishes them with their SHA-256 and
`THIRD-PARTY-NOTICES.md` in the Releases.

## Known limits

- The executable exists for Linux (x86_64, aarch64) and Apple Silicon Macs; not
  for Intel Macs or Windows.
- The model has to support tool calling: Sherpa is a loop of tool calls, and says
  so when a model cannot make them.
- The embedding model has to come from the same provider as the chat model.

## Licence

MIT — see [LICENSE](LICENSE). The executable also bundles PHP and Composer
packages under their own licences, listed in `THIRD-PARTY-NOTICES.md` with each
release.
