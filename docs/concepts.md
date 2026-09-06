# Concepts

This page explains how Sherpa works, one part at a time. The reasons behind each
choice, and their measurements, are in [design choices](design-choices.md).

## The agent loop

Sherpa is a loop around a model that can call tools. Each turn goes like this:

1. Sherpa sends the whole conversation to the model, with the list of tools.
2. The model replies with text, one or more tool calls, or both.
3. If there are tool calls, Sherpa runs them (asking first when a tool writes
   or executes) and adds their results to the conversation. Then it goes back
   to step 1.
4. A reply with no tool call is the answer, and the turn ends.

The reply streams to the terminal as it is generated. Three things end a turn
early:

- **Ctrl+C.** The partial reply is not kept, because it may be half a tool
  call.
- **Repetition.** The model makes the same calls with the same arguments three
  turns in a row. At that point it is going in circles, and more turns will not
  tell it anything new. If it wrote a reply along the way, that reply is kept as
  the answer.
- **A hard cap of 40 steps.** It is a last resort. An ordinary request takes
  about ten steps (search, read, edit, test, read again…).

Some models, mostly small local ones, write a tool call as plain text instead
of in the structured field of the API. Sherpa recovers such a call when it names
a tool that exists and has well-formed arguments. Otherwise, a model that only
*talks about* JSON would trigger a tool.

The conversation stays well formed whatever happens: every tool call gets a
result, even when the turn stops before running it. The next request would be
rejected otherwise.

## Tools and permissions

The tools that read (`list_dir`, `project_grep`, `file_read`, `doc_search`,
memory, `context_recall`, `skill_load`) run without asking. The tools that
change something (`file_write`, `file_patch`, `shell_exec`) and every MCP tool
ask for confirmation first:

| Key | Grants |
|---|---|
| **a** | this call only |
| **s** | this tool, until the end of the session |
| **p** | this tool, always, in this project |
| **r** | nothing: the model is told the call was refused |

`/permissions` lists the grants that are in force and withdraws them.

The file tools are **confined to the project**, which is the directory Sherpa
was started in. A path outside it is refused, whether it is absolute, starts
with `../` or goes through a symbolic link. For the same reason, Sherpa refuses
to start in your home directory or at `/`.

Edits come with two safety nets:

- `file_patch` replaces one piece of text. When the exact text is not found, it
  tries a few common mistakes (backslashes doubled, different line endings,
  wrong indentation), but only when the correction matches in exactly one
  place. When nothing matches, the error quotes the closest text in the file.
- A PHP or JSON file that parses today is not allowed to stop parsing. The edit
  is refused, with the parser's error, while the model still knows what it
  meant to write.

Tool output is **bounded**. A grep that matches a line of minified JSON returns
the part around the match, and a long file is read in pages ("read on with
offset=N"). One unbounded result can fill the context window on its own.

## The context window

Every request sends the whole conversation, so the conversation's size is what
you pay for and what you wait for. Sherpa keeps track of it:

- **Estimate, then correct.** Sherpa has no tokenizer, so it estimates tokens
  from characters and corrects the ratio with the counts the server reports.
  After a couple of turns, the estimate matches the real count.
- **Compact before sending.** Past about three quarters of the window, Sherpa
  makes room, in one of two ways:
  - **Move old tool output out.** It is replaced by a short stub with an id,
    and `context_recall` brings it back. This costs nothing.
  - **Summarise the oldest turns** through the model. This keeps the
    conclusions, but costs one extra pass of the model.

  Sherpa measures how fast the model reads a prompt on this machine, and uses
  that to choose. On a GPU, a summary takes a second. On a laptop CPU, it can
  take minutes, and Sherpa does not make you wait for it.
- **The question in progress is never summarised.** It stays word for word, so
  the model does not work from a paraphrase.
- **Compact rarely, but deeply.** Rewriting the history invalidates the
  provider's prompt cache, so each compaction goes well below the threshold.

`/context` shows what fills the window. `/compact` compacts it now.

## The project's memory

Each project has a small SQLite database of lasting facts. These are things
like a convention, where the configuration lives, or which command runs the
tests. Facts arrive two ways: the model records them with `memory_remember` as
it works, and a short pass at the end of a session picks up those it did not
record.

A fact is never overwritten. A correction replaces it, and the old value stays
on record.

A digest of the facts goes into every system prompt, within a fixed budget of
characters. The facts are ranked by:

- **Relevance to what you are doing now** (half the weight). This comes from
  the files git shows as recently changed and the directory you started from.
  It costs nothing: the ranking is known before you type anything.
- **How often the model looks a fact up.**
- **How recent it is.**

Facts that do not fit in the digest are named, so the model knows it can look
them up.

The system prompt also names the project's **test command**, when one is
declared somewhere: a Composer script, a make target, a test runner's
configuration or, as a last resort, the README.

## Documentation search

`doc_search` searches the project's documentation. Sherpa reads Markdown, plain
text, reStructuredText, AsciiDoc and HTML.

- **Indexing.** Documents are cut along their headings. A long section is cut
  between paragraphs, and a table or a code block is never cut. Each piece
  repeats the end of the one before it. The index is a SQLite database beside
  the memory, with no vector server to run. It updates itself before each
  search, by checking each file's size, date and hash.
- **Search.** Sherpa searches two ways at once:
  - by keywords (BM25, and the same with word stems);
  - by meaning (embeddings from the provider).

  The two rankings are merged with Reciprocal Rank Fusion, and the keyword
  rankings count as one vote together. Without embeddings, Sherpa uses keyword
  search alone and says so.
- **Results.** The model gets the whole section around each match, with its
  source (`path › section`). It is told to cite that source, and to say so when
  the documentation does not answer.

Sherpa looks for the embedding model by itself: it reads the provider's list of
models, or tries the known names on one word each.

## Sessions

The conversation is saved after every turn, in the project's directory under
`~/.config/sherpa/projects/`. If the terminal closes, you lose one turn at most.
`/sessions` lists the saved conversations, and `/resume` or `sherpa -r` reopens
one.

## Skills and MCP

A **skill** is a Markdown file of instructions:

- `~/.config/sherpa/skills/` for every project;
- `.sherpa/skills/` for one project.

The model only sees each skill's first paragraph, and loads the whole file with
`skill_load` when the subject comes up. You can also run a skill directly with
`/<skill> [request]`.

**MCP servers** add tools from outside. They are declared in
`~/.config/sherpa/mcp.json`, in the format other MCP clients use. A server can
be a process Sherpa starts, or an address it reaches over HTTP. Their tools
always ask before they run.

A server can also offer two other things, which Sherpa picks up when it
starts:

- **Resources**: data the server publishes under a URI, such as a database
  schema or a page of a wiki. Each server that has some gets one more tool,
  `mcp__<server>__read_resource`. Its description lists the resources, so the
  model knows what it can read. Like the server's other tools, it asks before
  it runs.
- **Prompts**: request templates the server fills in. Each one runs as a
  command, `/<server>:<prompt>`, and the server's text becomes your message.

Resource templates (URIs with parameters) are not supported yet.

## Backends

- **API (the default).** Sherpa talks to any API that speaks the OpenAI
  `/chat/completions` dialect. That covers OpenAI, Mistral, Groq, OpenRouter,
  Cloudflare Workers AI, vLLM, LM Studio, and gateways such as LiteLLM. It also
  speaks Anthropic's own API natively, with prompt caching. You can record
  several providers and switch between them during a session. One provider's
  key is never sent to another.
- **Ollama.** A model on your own machine. Nothing leaves it.

A machine uses one backend at a time, for all its projects. The address in use
is shown when a session starts and in `/context`, so you always know where your
code is being sent.
