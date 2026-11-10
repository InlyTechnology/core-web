# Working on inly/core-web

This file is for agents changing core-web itself. The rules for agents working on a site that uses core-web
are in `resources/agents/AGENTS.md`, which ships with the package. How to test, format and release is in
`README.md`.

## QA before a pull request is ready

- **Check every behaviour claim before each review.** A claim is a sentence the pull request adds or changes, in
  the diff or the description, that says how core-web, Core or a package it runs on behaves. Check each one against
  the code that does it (core-web's own, Core's, or the package's under `vendor/`), never memory, and list it with
  the `file:line` that proves it.
- **Say a fact once.** The page in `docs/` owns how a feature behaves. A comment in code or config says what its
  line is for and why, and points at that page where the reader needs to know how the feature behaves. The pull
  request description and the `## Unreleased` notes say what changed and link the page rather than explaining it
  again: three places are three edits on every fix.
- **Fix a wrong or unproved sentence by rewriting it to what the code shows, or by deleting it**, never by adding
  a qualifier: the added sentence is where the next finding comes from.
- **Every pull request gets a separate QA review before it is called ready, merged or released.** The reviewer is
  a separate agent that did not write the change (in Claude Code, a subagent). Hand it the diff, what the pull
  request is for and the claim list, never your own conclusions, and have it open each cited line rather than
  trust the list, look for claims the list leaves out, and check correctness, security, tests, docs and, where a
  site sees the change, the `## Unreleased` notes. Fix or answer each finding in the pull request.
- **Fixes made after QA are reviewed again, by a fresh reviewer**, before the pull request is called ready: hand
  it the diff since the last review, the findings the fixes answer and the claims the fixes add or change, and have
  it check only those, not the whole pull request again. Repeat until a review finds nothing to fix.
- **The description ends with a `## QA` section**: each review, the commit it reviewed, what it found, and what was
  fixed or left and why. The pull request is ready only when the last review names its last commit and none of
  its findings is still open.
- **A release runs only on the user's ask.** Before it, list what was merged since the last tag
  (`git log <last tag>..origin/main`, each subject ending in `(#N)`) and read each pull request's description: its
  `## QA` section must name the pull request's last commit (`gh pr view N --json commits`). If one does not, or a
  commit has no pull request, tell the user.
