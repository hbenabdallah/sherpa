# Security

## Reporting a vulnerability

Please report it privately, through GitHub: **Security → Report a
vulnerability** on this repository. Do not open a public issue for it.

Say what an attacker controls (a web page, a file in a repository, an MCP
server, the model's replies…), what they obtain, and how to reproduce it.
You will get an answer within a week. A fix ships in the next release, and
the advisory credits you unless you would rather it did not.

## Supported versions

Only the latest release gets security fixes. `sherpa update` installs it.

## What Sherpa defends against

Sherpa runs a language model that reads untrusted text — web pages, MCP
answers, the files of a repository — and can act on your machine. The model is
treated as able to be talked into anything. What protects you does not depend
on it behaving:

- **Commands are asked about**, and a grant names a command (`make test *`),
  never the tool. Commands that cannot be taken back (`rm -r`, `git push`,
  `sudo`, a download piped into a shell…) and commands whose words do not say
  what runs are asked about every time, whatever was granted.
- **Commands run in a sandbox** (bubblewrap on Linux, `sandbox-exec` on macOS):
  they write only to the project, `/tmp` and package caches, and cannot read
  the API keys Sherpa keeps.
- **File tools are confined to the project**, symbolic links included.
- **Secrets**: reading `.env`, private keys and the like is asked about every
  time, and API keys in what tools return are masked before they reach the
  provider.
- **Untrusted text** from the web and MCP servers reaches the model marked as
  data, and a fact written to memory after reading some is asked about first.

The [threat model](docs/threat-model.md) lists the assets, the attackers, each
defence and what it does not cover.
