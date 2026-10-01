---
paths:
  - 'app/Actions/Search/**'
---

# Search

## Global search: names and keys only, and group every orWhere
SearchTeam (the ⌘K palette, GET {current_team}/search) searches names, slugs, variable keys and alias_key — never values, which are encrypted. Deploy tokens only appear for users with TeamPermission::ManageDeployToken. LIKE patterns escape % and _ with "!" (escape '!'), because a backslash escape needs different quoting on SQLite, MySQL and Postgres. Always wrap orWhere/orWhereRaw in a nested where(): inside whereHas() a bare orWhere is ORed with the relation's join condition and matches every row (this happened; GlobalSearchTest "never searches or returns variable values" guards it).
