# Working on inly/core-web

This file is for agents changing core-web itself. The rules for agents working on a site that uses core-web
are in `resources/agents/AGENTS.md`, which ships with the package. How to test, format and release is in
`README.md`.

## QA before a pull request is ready

- **Every pull request gets a separate QA review before it is called ready, merged or released.** The reviewer is
  a separate agent that did not write the change (in Claude Code, a subagent). Hand it the diff and what the pull
  request is for, never your own conclusions, and have it check correctness, security, tests, docs and, where a
  site sees the change, the `## Unreleased` notes. Fix or answer each finding in the pull request.
- **Fixes made after QA are reviewed again, by a fresh reviewer**, before the pull request is called ready: hand
  it the diff since the last review and the findings the fixes answer. Repeat until a review finds nothing to fix.
- **The description ends with a `## QA` section**: each review, the commit it reviewed, what it found, and what was
  fixed or left and why. The pull request is ready only when the last review names its last commit.
- **A release runs only on the user's ask.** Before it, check that every pull request merged since the last tag
  (`git log <last tag>..origin/main`) has a `## QA` section naming its last commit; if one does not, tell the user.
