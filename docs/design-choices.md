# Design choices

These are the decisions taken while building Sherpa, with what they were based
on. When a measurement exists, it is quoted. When something was tried and did
not help, it is listed, so nobody has to try it again.

**How the numbers were taken.** Unless stated otherwise, they come from one
model, `glm-4.7-flash` on Cloudflare Workers AI, run on realistic tasks
(`make bench`) or on real questions (`tests/rag/`). The samples are small, and
one task can take 27 requests on one run and 34 on the next. Treat them as
evidence for a direction, not as benchmarks to compare models.

## Measure before deciding

Most of the early constants guessed at the machine. Examples: ten steps per
turn, how much shell output to keep, whether a summary was affordable. They
were all set on one CPU-only laptop.

They were replaced by measurements taken on every request:

- **Speed.** The server reports how long it took to read the prompt and to
  generate the reply. Sherpa turns that into tokens per second for this
  machine and this model.
- **Size.** The server reports how many tokens the prompt really had. Sherpa
  uses that to correct its own estimate.

A machine without a GPU is then just the slow end of a scale, not a special
case.

## The loop: stop on repetition, not on a count

At first, the only stop was ten steps. That cut honest work short: grep, read,
read, patch, test, read, patch, test and confirm is already nine steps. It also
still let a stuck model use all ten.

The actual fault is repetition, so that is what Sherpa detects: the same calls
with the same arguments, three turns in a row. The step cap moved to 40, as a
last resort.

Some models write their answer in the same message as a tool call, then repeat
the call. When the repetition stop fires, Sherpa keeps that text as the answer
instead of discarding it.

## Tool output is bounded

In one real session, a single grep matched a line of minified JSON of about
180,000 tokens. The next request went over the window and was refused, and the
turn was lost. Since then, every tool that returns text has a budget:

- a grep keeps the part of a long line around the match;
- a file read is capped at about a third of the window, and is read in pages
  after that.

It also changed the answers. On questions the documentation cannot answer, a
good answer says so, or finds the answer elsewhere in the project.

| | good answer | invented an answer | no answer |
|---|---|---|---|
| unbounded tools (12 runs) | 6 | 2 | 4 |
| bounded tools (12 runs) | 12 | 0 | 0 |

The "no answer" runs had either run out of tokens while exploring, or been
refused for size.
The two rows are not the exact same questions, but both are 12 runs on questions
the documentation does not answer, with the same model.

## Edits: correct slips, but never guess

In the first benchmark, `file_patch` failed on about one call in two (9 of 19,
then 8 of 17), and each failure cost a whole turn. Almost every failure was one
of a few slips:

- namespace backslashes escaped twice (`App\\\\User`);
- Windows line endings on one side only;
- the right lines at the wrong indentation.

Telling the model about these did not stop them. So `file_patch` corrects them
itself, **only after the exact text was not found, and only when the correction
matches in exactly one place**. A correction that could land in two places is a
guess. When nothing matches, the error quotes the closest text in the file.

After that change, failures dropped to **1 in 22**. Seven of those 22 edits
went through only thanks to a correction, because the model kept doubling
backslashes even when told not to.

On the same benchmark, one rename was scored as passed. It had written
`use App.User.UserRepository;` into a controller the tests never load. A parser
catches that in milliseconds. Now a PHP or JSON file that parses is not allowed
to stop parsing: the edit is refused with the parser's error. A file that was
already broken is not checked, so it can still be repaired in several steps.

## Tell the model how to run the tests

Once edits worked, the one real failure left was a check that checked nothing.
The model ran a test file that only defines functions, saw no output and took
that as a pass, while the README named the real command.

Sherpa now looks up the test command once and puts it in the system prompt. It
reads what can be executed first (a Composer script, a make target, a test
runner's configuration) and the README last. The prompt also says that "a
command that prints nothing has checked nothing".

The right test command on the first try went from 0/3, 1/3 and 3/6 on three
earlier runs to **6/6**.

This fixes the tooling, not the model's judgement. The model can still write a
weak test, such as a "with accents" test that contains no accents, and that
test will pass. Only checks the model does not write can catch that.

## Keeping the context window small

Every token in the window is paid for, and waited for, on every request. The
choices:

- **Compact before sending, not after an error.** On Ollama, overflowing the
  window is silent. llama.cpp drops tokens from the *start* of the prompt,
  where the identity, the memory and the skills live. The agent then seems to
  forget who it is.
- **Moving tool output out comes before summarising, unless summarising is
  fast here.** Moving output out costs nothing, and `context_recall` brings it
  back. A summary costs one pass of the model, which is a second on a GPU and
  minutes on a CPU. Sherpa picks by measured speed. When the speed is not
  measured yet, it picks the cheap option.
- **Compact rarely, but deeply.** Every rewrite of the history invalidates the
  provider's prompt cache. Each compaction therefore lands well below the
  threshold that started it.
- **Never summarise the question being worked on.** A long exchange once
  folded the user's own request into the summary. The model then worked from a
  paraphrase, and the session could not be saved, because it no longer had a
  user message to take a title from. The request now stays word for word.

## Memory: never overwrite, rank by situation

- **A fact's key is a label, not an identity.** Asked to name two unrelated
  things, models reach for the same obvious word. With unique keys and an
  update-or-insert, that silently lost data. Now nothing is overwritten: a
  correction replaces a fact, and the old value stays on record.
- **The digest in the prompt is ranked by the situation first.** The system
  prompt is built before anyone asks anything, but git already says which files
  were changed recently, and the launch directory says where you are working. A
  fact about the payment code is worth quoting in full on the day someone works
  there. Situation counts for half, and how often a fact is looked up and how
  recent it is count for a quarter each. The digest has a fixed budget of
  characters, and facts that do not fit are named rather than dropped silently.
- **An end-of-session pass is only a backup.** The model is expected to record
  facts as it works. The pass after `/exit` catches what it forgot, and it has to
  be cheap, because someone is waiting to get their shell back.

## Documentation search

**Storage.** SQLite, one database per project, beside the memory. A project's
documentation is a few thousand passages at most. FTS5 provides BM25, and the
embeddings are one more column. There is no vector database to install or run.

**Cutting.** Documents are cut along their headings, never across a table or a
code block, and each piece overlaps the one before it. Cutting at a fixed size
splits a sentence or a table in two, and leaves both halves meaningless.

**Ranking.** Two sets of questions measured each strategy:

- a test corpus of 32 questions;
- the documentation of a real project, with 54 questions written by the
  documentation's owner.

Each cell gives recall@1 %, recall@5 % and MRR:

| strategy | test corpus (32) | real project (54) |
|---|---|---|
| keywords (BM25 + stems) | 72 / 91 / 0.78 | 74 / 96 / 0.84 |
| vectors alone | 94 / 100 / 0.96 | 70 / 93 / 0.80 |
| **vectors fused with keywords (default)** | **94 / 100 / 0.96** | **76 / 96 / 0.85** |
| flat fusion of BM25 and vectors | 91 / 97 / 0.93 | 80 / 96 / 0.87 |
| flat fusion of BM25, stems and vectors | 84 / 97 / 0.90 | 80 / 96 / 0.87 |
| fusion with k = 2 | 88 / 97 / 0.91 | 81 / 100 / 0.89 |
| interleaving | 94 / 97 / 0.95 | 70 / 100 / 0.83 |

Keywords and vectors each find what the other misses. The default is the only
strategy that loses nothing on either set. Some strategies do better on the
real project, but they lose on the test corpus. The rule was that a change must
not lose on either set.

**Rankings, not scores.** Fusion adds 1 / (60 + rank) over the lists a passage
appears in. A BM25 score and a cosine similarity are not on the same scale, and
no weighting between them survives a change of corpus. The two keyword rankings
count as a single vote: side by side with the vectors, they would outvote them.

**Real questions decide.** An earlier set of questions drafted by the tool's
author made "vectors alone" look best (88 / 96). The questions of the
documentation's owner reversed that. The strategy was then chosen on their
questions, and that is why `/docs eval` exists: it measures the search on your
own questions.

**Where it stands.** On 15 answerable questions, run through the whole agent,
the agent searched the documentation every time, got the right section back
15 times out of 15, and cited the right file 15 times out of 15. Read by hand,
13 of the 15 answers were right. The two misses were not search failures: one
answer misread a product name that the right passage contained, and the other
ran into the token cap. On 4 questions the documentation does not answer, the
agent said so cleanly 3 times.

**Not done: a reranker.** A cross-encoder that reorders the top 30 results
could recover the two questions still missed in the top five. On both, the
vectors rank the right section first, but BM25 does not find it at all, and the
fusion buries it. The upside is at most 2 of 54 questions, while every search
would cost one more call to the provider. The retrieval step is also not where
the answers fail today.

## Tried and rejected

| Idea | What happened |
|---|---|
| A prompt rule: "if the documentation does not answer, say so, look in the code and configuration, and flag general knowledge" | On 6 questions × 3 runs, it did not reduce invented answers: 1 in 12 with the rule, 0 in 12 without it. Bounding the tools was what fixed this. |
| Adding each document's first sentence to its passages before computing embeddings | Worse on both sets (91 % against 94 % on the test corpus), and it fixed nothing. |
| Different wordings of the prompt line that suggests `list_dir` | The model's behaviour changed (it greps first), but it used no fewer requests or tokens. |
| Flat fusion of every ranking | Better on the real project, worse on the test corpus (see the table above). |

## Distribution: one file

Sherpa is written in PHP 8.4 with Symfony. A PHP tool usually asks its users to
install PHP. The release is instead a phar appended to a static PHP runtime
(`micro`), pinned by checksum:

- one file per platform (Linux x86_64 and aarch64, macOS on Apple Silicon),
  24 MB on Linux;
- nothing else to install;
- 0.8 s for the first start, 0.28 s after that.

Symfony's container is compiled into `~/.cache/sherpa` at the first start.

The phar is the same on every platform: each executable is the same archive
behind that platform's `micro`, so all of them are built on one machine, and each
is then run end to end on its own platform before a release is published.

Some details that had to change for the executable:

- The PHP binary *is* Sherpa inside the executable, so the syntax check
  parses in-process (`token_get_all` with `TOKEN_PARSE`) instead of calling
  `php -l`.
- Symfony finds no classes in a bare `phar://` directory, so services are
  declared with a glob.
- There is no `GLOB_BRACE` on musl.

The executable still contains readable PHP. It is a way to distribute Sherpa,
not a way to hide the code, which is MIT-licensed anyway.

## Where your code goes

Sending code to a provider is your choice. Sherpa does not refuse, and does not
ask for consent at every turn. It makes the destination visible instead: the
address is shown when a session starts and in `/context`, and a key is only
ever sent to the provider it was given for. A local model through Ollama
keeps everything on the machine.
