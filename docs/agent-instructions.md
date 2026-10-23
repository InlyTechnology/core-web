# Agent instructions

`resources/agents/AGENTS.md` holds the rules an agent working on a site
needs for each feature, versioned with the code. A site imports it from its
own `CLAUDE.md`:

```markdown
@vendor/inly/core-web/resources/agents/AGENTS.md
```

A change to how a site should use a feature updates that file in the same
pull request.
