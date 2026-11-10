# Working on inly/core-web

This file is for agents changing core-web itself. The rules for agents working on a site that uses core-web
are in `resources/agents/AGENTS.md`, which ships with the package. How to test, format and release is in
`README.md`.

## QA before a pull request is ready

- **Every pull request gets a separate QA review before it is called ready, merged or released.** The reviewer is
  a subagent that did not write the change. Hand it the diff and what the pull request is for, never your own
  conclusions, and have it check correctness, security, tests, docs and the `## Unreleased` notes. Fix or answer
  each finding in the pull request.
- **Fixes made after QA are reviewed again, by a fresh reviewer**, before the pull request is called ready: hand
  it the diff since the last review and the findings the fixes answer. Repeat until a review finds nothing to fix.
  Ready means the last commit has been through QA.
- **The description ends with a `## QA` section**: what was reviewed and at which commit, what was found, and what
  was fixed or left and why.
- **Release only what has been through QA**, and only on the user's ask: check that every pull request in
  `## Unreleased` went through it.
