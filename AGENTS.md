# Working on inly/core-web

This file is for agents changing core-web itself. The rules for agents working on a site that uses core-web
are in `resources/agents/AGENTS.md`, which ships with the package. How to test, format and release is in
`README.md`.

## QA before a pull request is ready

- **Check every behaviour claim before the first review.** A claim is a sentence in the diff, a code comment, a
  docs page or the pull request description that says how core-web, Statamic or Laravel behaves. Check each one
  against the source it describes (`src/`, `vendor/statamic/cms/`, `vendor/laravel/framework/`), never memory, and
  list each claim with the `file:line` that proves it.
- **Say a fact once.** The page in `docs/` owns how a feature behaves. A code comment is one line of why, pointing
  at that page when more is needed, and the pull request description and the `## Unreleased` note link the page
  instead of restating it. A fact written in three places is three edits and three things to review on every fix.
- **Fix a wrong sentence by cutting it back to what is true**, not by adding a qualifier: the added sentence is
  where the next finding comes from.
- **Every pull request gets a separate QA review before it is called ready, merged or released.** The reviewer is
  a separate agent that did not write the change (in Claude Code, a subagent). Hand it the diff, what the pull
  request is for and the claim list, never your own conclusions, and have it check correctness, security, tests,
  docs and, where a site sees the change, the `## Unreleased` notes. It opens each cited line rather than trusting
  the list. Fix or answer each finding in the pull request.
- **Fixes made after QA are reviewed again, by a fresh reviewer**, before the pull request is called ready: hand
  it the diff since the last review and the findings the fixes answer, and have it check only those, not the whole
  pull request again. Repeat until a review finds nothing to fix.
- **The description ends with a `## QA` section**: each review, the commit it reviewed, what it found, and what was
  fixed or left and why. The pull request is ready only when the last review names its last commit and none of
  its findings is still open.
- **A release runs only on the user's ask.** Before it, list what was merged since the last tag
  (`git log <last tag>..origin/main`, each subject ending in `(#N)`) and read each pull request's description: its
  `## QA` section must name the pull request's last commit (`gh pr view N --json commits`). If one does not, or a
  commit has no pull request, tell the user.
