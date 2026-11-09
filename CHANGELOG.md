# Changelog

What changed in each release, newest first. Versions follow [semantic versioning](https://semver.org).

A pull request that changes what Sherpa does adds its entry under `## Unreleased`, in Added, Changed, Fixed or
Security, with its number. A release is a pull request of its own that renames that section to the version and the
date; once it is merged, the version is tagged on `main`, and the release's notes are this section
(`bin/release-notes.sh`), on its GitHub page and in `sherpa update`.

## 1.9.0 (2026-11-09)

### Added

- MCP elicitation: when an MCP server asks for more information during a tool call, Sherpa shows its form in the
  terminal (text, numbers, yes/no, choices and multi-select) and sends the answer back; cancelling it declines (#30).
- Discuss, a fifth answer to a confirmation: the call does not run, and what the user types goes back to the model
  instead of a plain refusal. In the terminal it is `[d]`; over ACP, the message is asked with a form (#32).

### Changed

- The startup warning summarizes standing grants, showing whole-tool exposure first, grouping MCP tools and counting
  shell command grants instead of printing each command (#31).
- Over ACP, Discuss is offered only to editors that can show a form to collect the message (#33).

### Security

- One-shot shell command grants can be kept for the session but not the project, and a Project answer cannot persist
  them (#31).

## 1.8.0 (2026-11-07)

### Added

- `session_search`: the model looks up what was decided, tried or dropped in the project's earlier conversations,
  which lasting facts don't keep. It searches what was asked and answered, not tool output nor the conversation in
  progress, by keywords ranked with BM25; each hit gives its conversation, date and `/resume <id>`, marked as said
  then, to check against the code. Read-only, so it runs without asking (#27).

## Earlier releases

Their notes, the commits each one brought, are on [GitHub Releases](https://github.com/hbenabdallah/sherpa/releases).
