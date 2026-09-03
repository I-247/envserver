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

## Deploy tokens can only pull; credentials also load from .envclientrc
`envclient push` refuses upfront (before any request) when `deployTokenSet()` is true — a deploy token has no write route server-side (see routes/api.php's `deploy` group: read-only), and deploy-token env vars always take priority over a stored personal login in `accessToken()`, so "just run envclient login" doesn't fix it unless ENVCLIENT_CLIENT_ID/SECRET are also unset. Keep this guard in any future write command.

`config.LoadDeployEnv` (called once in main.go before Execute) fills in ENVCLIENT_* env vars from `.envclientrc` in the cwd when not already exported. That filename is deliberately distinct from both `envclient.json` (committed, no secrets) and `vault.FileName` = `.env.envclient` (the sealed vault) — and deliberately not the `.env` file `pull` manages, since `pull --prune` would delete a credential kept there.
