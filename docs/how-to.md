# How-to guides

Short recipes. For what each part does, see [concepts](concepts.md).

- [Use several API providers](#use-several-api-providers)
- [Reach Claude, or any model behind a gateway](#reach-claude-or-any-model-behind-a-gateway)
- [Run on a local model with Ollama](#run-on-a-local-model-with-ollama)
- [Work on a project that runs in a container](#work-on-a-project-that-runs-in-a-container)
- [Write a skill](#write-a-skill)
- [Add an MCP server](#add-an-mcp-server)
- [Measure documentation search on your project](#measure-documentation-search-on-your-project)
- [Keep an eye on cost](#keep-an-eye-on-cost)

## Use several API providers

```
❯ /provider add
  API address: https://api.groq.com/openai/v1
  Name [groq]:
  API key (hidden; Enter if it needs none):
```

Sherpa checks the key at once and switches the session to the new provider.
Then it shows the provider's models, and you pick one.

| | |
|---|---|
| `/provider` | lists the providers, and shows which one is in use |
| `/provider groq` | switches to it; each provider keeps its own model |
| `/provider key groq` | replaces its key |
| `/provider remove groq` | forgets it |
| `sherpa -P groq` | starts a session on it, without changing the default |

Keys are kept in `~/.config/sherpa/keys.yaml`, which only you can read. To keep
a key in your own secret store instead, give the provider a variable to read in
`~/.config/sherpa/config.yaml`:

```yaml
api:
  provider: groq
  providers:
    groq:
      url: https://api.groq.com/openai/v1
      key_env: GROQ_API_KEY
      model: <a model id from the provider>
      context: 32768
```

## Reach Claude, or any model behind a gateway

Sherpa speaks the OpenAI `/chat/completions` dialect. A gateway that translates
it, such as OpenRouter or a LiteLLM proxy you run yourself, gives you models
from other vendors, Claude included. Add it like any other provider, with the
gateway's address and key, then choose the model by its gateway id.

Note that Anthropic has no embedding model. Behind such a gateway, the
documentation search may fall back to keywords, and Sherpa says so when it does.

## Run on a local model with Ollama

1. Install [Ollama](https://ollama.com) and pull a model that supports tool
   calling:

   ```sh
   ollama pull qwen2.5-coder:7b
   ```

2. Start Sherpa on it:
   - on the first run, press Enter when asked for the provider's address;
   - later, use `/backend ollama` or `sherpa -b ollama`.

3. Choose the model and its context window with `/model`.

If Ollama is not at `http://localhost:11434`, set `OLLAMA_URL`.

Things to know about local models:

- **The context window is what you wait for.** On a CPU, the model reads a few
  dozen to a few hundred tokens a second, so a full 32k window takes minutes.
  Sherpa measures the speed and compacts without summarising when a summary
  would be slow.
- **Small coder models often write their tool calls as plain text.** Sherpa
  recovers them. If tool calls are still the problem, a general instruct model
  of the same size usually does better than one tuned for code.

## Work on a project that runs in a container

When a project's commands only work inside its container (the right PHP,
Node or database), tell Sherpa. It will run `shell_exec` there with
`docker exec`.

- In a new directory, the second question at launch is "Docker environment?".
  The default answer is yes when the directory has a compose file or matching
  containers are running. Then give the container's name.
- For an existing project, use `/project edit`.

The working directory inside the container is found from the container's
mounts. Sherpa's own file tools keep working on your machine.

This needs Sherpa running **on your machine**, either as the single executable
or from the source after `make php`. The Docker fallback of a source install
has neither a docker client nor the socket, and says so.

## Write a skill

A skill is a Markdown file. Its name is the file name, and its first paragraph
is what the model reads to decide whether to load it:

```markdown
# release

How to cut a release of this project: changelog, version bump, tag.

1. Update CHANGELOG.md: move "Unreleased" under the new version…
2. …
```

Save it in one of these places:

- `.sherpa/skills/release.md` inside the project, to commit it with the project;
- `~/.config/sherpa/skills/release.md`, for every project.

`/skills` lists the skills Sherpa found. The model loads a skill when the
subject comes up. You can also run one yourself, for example
`/release 2.4.0`.

Keep the first paragraph specific. A vague one gets the skill loaded when it is
not needed, and that costs context.

## Add an MCP server

Copy [`mcp.json.dist`](../mcp.json.dist) to `~/.config/sherpa/mcp.json` and
keep the servers you want. A server is either started as a process or reached
at an address:

```json
{
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "/path/to/share"]
    },
    "remote": {
      "url": "https://example.test/mcp",
      "headers": { "Authorization": "Bearer YOUR_TOKEN" }
    }
  }
}
```

A configuration written for another MCP client works as it is.
`"enabled": false` turns a server off without deleting it. `/mcp` shows each
server's state, its resources and its prompts, and why a server did not start.

To run a server's prompt, type it as a command. It also appears in the `/`
menu:

```
❯ /tracker:review focus=security the login fix
```

The arguments work like this:

- `name=value` sets an argument by its name. Use quotes when the value holds
  spaces.
- The rest of the line goes to the first argument that is still empty, so a
  prompt with a single argument takes the line as you typed it.
- A missing required argument is refused, with the usage line.

## Measure documentation search on your project

Documentation search is only as good as it is on *your* documents. Write down
real questions and the section that answers each one, in
`.sherpa/rag-questions.json` inside the project:

```json
[
  {"kind": "procedure", "q": "How do we deploy to staging?",
   "expect": [{"path": "docs/deploy.md", "section": "Staging"}]},
  {"kind": "reference", "q": "Which port does the API listen on?",
   "expect": [{"path": "README.md", "section": ""}]}
]
```

The fields are:

- `section`: part of the expected heading. Leave it empty to mean "anywhere in
  this file".
- `kind`: groups the results. Use it to separate exact questions from
  rephrased ones.

Then run `/docs eval`. For each search strategy, it reports:

- **recall@1**: how often the right section comes first;
- **recall@5**: how often it is in the top five;
- **MRR**: the mean reciprocal rank of the right section.

It also lists each question that missed, and what came first instead. Twenty
questions already tell you a lot.

`/docs <question>` shows exactly what the model would receive for one question.

Write the questions the way people really ask them. When the questions were
drafted by the tool's author, one strategy looked best. When they were the
questions of the documentation's owner, a different one won. See
[design choices](design-choices.md#documentation-search).

## Keep an eye on cost

Sherpa counts the tokens of every request. It shows them in the status bar, in
`/context` and when the session ends. To see a cost in money, declare your
provider's price per million tokens in `~/.config/sherpa/config.yaml`:

```yaml
prices:
  'provider/model-id': { input: 0.15, output: 0.60, cached_input: 0.03, currency: '$' }
```

Sherpa ships no prices, because they change too often to be right.

To put a hard stop on a session, set `SHERPA_MAX_SESSION_TOKENS=500000`.
Sherpa stops the session once it reaches that many tokens, and warns you as it
gets close.
