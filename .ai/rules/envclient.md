---
paths:
  - 'cli/cmd/envclient/**'
  - cli/cmd/envclient/sync_commands.go
  - cli/cmd/envclient/check_commands.go
  - 'cli/internal/config/**'
---

# Envclient CLI

## CLI-uitvoer loopt altijd via internal/ui
Geen `fmt.Fprintf(cmd.OutOrStdout(), ...)` in commando's: haal een `*ui.Printer` op met `printer(cmd)` en gebruik Title/Done/Warn/Info/Note/Changes/Table/Field. Zo blijft de opmaak overal gelijk en zit de kleurbeslissing op één plek.

Kleur hangt af van de stroom, niet van een vlag: `ui.New` zet hem alleen aan voor een character device, en respecteert NO_COLOR, TERM=dumb en CLICOLOR_FORCE. `--no-color` kan hem alleen uitzetten, nooit forceren.

Stdout dat data is (de gerenderde .env van `envclient unseal`) gaat door `p.Plain()` en de begeleidende tekst naar stderr — een escape-code midden in een secret is stil kapot. Foutmeldingen gaan via `p.Error` naar stderr.

## envclient pull vraagt eerst om bevestiging, --force is voor deploys
`envclient pull` toont eerst wat er zou wijzigen (dezelfde renderer als --dry-run, via `pullPreview`) en schrijft pas na een expliciete "y". `--force` slaat de vraag over.

Op een deployserver is er geen terminal: `ui.Interactive(cmd.InOrStdin())` is dan false en de pull stopt met een foutmelding die naar --force wijst. Nooit stilzwijgend ja óf nee aannemen — het eerste overschrijft een .env die niemand zag, het tweede laat een deploy met verouderde variabelen doorlopen. Deployscripts en de README gebruiken daarom `envclient pull --constructive --force`.

## envclient check: exit 2 is drift, exit 1 blijft "kon niet draaien"
checkFailed draagt zijn eigen ExitCode() (2) en main.go herkent dat via een interface, print dan geen extra foutregel: het commando heeft zijn rapport al getoond. Exit 1 blijft gereserveerd voor "de check kon niet draaien" — geen netwerk, geen token, geen release. Houd dat onderscheid intact, CI leunt erop.

Sleutels die alleen lokaal bestaan tellen standaard niet mee, dezelfde belofte die `envclient pull` doet door nooit ongevraagd te prunen. --strict telt ze wel.

## Every command a deploy server can run must resolve credentials through fetchRelease
A deploy token never has a envclient.json, so `s.target` is empty and the regular `/api/v1/teams/.../release` route 403s for it (that route needs a user-scoped token, not a client-credentials one). `pull` and `run` already route through `fetchRelease(cmd, s, version)`, which calls `s.client.DeployRelease()` when `deployTokenSet()` instead of `s.client.Release(cmd.Context(), s.target, ...)`. Any new command meant to work in CI/on a deploy server (like `check`, which was fixed for this after shipping without it) must do the same — never call `s.client.Release` directly.

## Deploy tokens are read-only unless explicitly granted env:write; credentials also load from .envclientrc
Deploy-token env vars always take priority over a stored personal login in `accessToken()`. `push` routes through `pushVariables()` (mirrors `fetchRelease()`): with a deploy token it calls `s.client.DeployPush()` against `/api/v1/deploy/variables` (routes/api.php has a *separate* route group for this, gated by `ResolveDeployToken::using(ApiScope::EnvironmentWrite->value)`, so a token needs `env:write` specifically — `env:read` alone still 403s). Most tokens are read only: `DeployTokenController::store` only adds `env:write` when the portal's `can_push` checkbox was ticked; a client-supplied `scopes` array is never trusted directly. `--publish` has no deploy-scoped route at all and is refused locally with a deploy token, before any request.

`config.LoadDeployEnv` (called once in main.go before Execute) fills in ENVCLIENT_* env vars from `deployEnvFiles` (`.envclientrc`, then `.env`) in the cwd when not already exported — first file wins per key, a real export wins over both. `.envclientrc`'s name is deliberately distinct from both `envclient.json` (committed, no secrets) and `vault.FileName` = `.env.envclient` (the sealed vault). Credentials are allowed in `.env` (the same file `pull` writes) on purpose per explicit user request even after the prune risk was explained twice — `envfile.mergeKeys` is the other half of that: it never lists an `ENVCLIENT_*` key for pruning even when `--prune` is passed, so a credential kept there can't be deleted by the pull that used it. Keep both halves in sync if either changes.

`DeployController::push` has no personal author to attribute the change to, so the underlying `variable.created`/`variable.updated` audit entries are actor-less; `AuditAction::DeployTokenPushed` (subject = the `DeployToken`) is what names which token did it.
