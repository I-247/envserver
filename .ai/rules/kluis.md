---
paths:
  - 'cli/cmd/kluis/**'
  - cli/cmd/kluis/sync_commands.go
  - cli/cmd/kluis/check_commands.go
---

# Kluis

## CLI-uitvoer loopt altijd via internal/ui
Geen `fmt.Fprintf(cmd.OutOrStdout(), ...)` in commando's: haal een `*ui.Printer` op met `printer(cmd)` en gebruik Title/Done/Warn/Info/Note/Changes/Table/Field. Zo blijft de opmaak overal gelijk en zit de kleurbeslissing op één plek.

Kleur hangt af van de stroom, niet van een vlag: `ui.New` zet hem alleen aan voor een character device, en respecteert NO_COLOR, TERM=dumb en CLICOLOR_FORCE. `--no-color` kan hem alleen uitzetten, nooit forceren.

Stdout dat data is (de gerenderde .env van `kluis unseal`) gaat door `p.Plain()` en de begeleidende tekst naar stderr — een escape-code midden in een secret is stil kapot. Foutmeldingen gaan via `p.Error` naar stderr.

## kluis pull vraagt eerst om bevestiging, --force is voor deploys
`kluis pull` toont eerst wat er zou wijzigen (dezelfde renderer als --dry-run, via `pullPreview`) en schrijft pas na een expliciete "y". `--force` slaat de vraag over.

Op een deployserver is er geen terminal: `ui.Interactive(cmd.InOrStdin())` is dan false en de pull stopt met een foutmelding die naar --force wijst. Nooit stilzwijgend ja óf nee aannemen — het eerste overschrijft een .env die niemand zag, het tweede laat een deploy met verouderde variabelen doorlopen. Deployscripts en de README gebruiken daarom `kluis pull --constructive --force`.

## kluis check: exit 2 is drift, exit 1 blijft "kon niet draaien"
checkFailed draagt zijn eigen ExitCode() (2) en main.go herkent dat via een interface, print dan geen extra foutregel: het commando heeft zijn rapport al getoond. Exit 1 blijft gereserveerd voor "de check kon niet draaien" — geen netwerk, geen token, geen release. Houd dat onderscheid intact, CI leunt erop.

Sleutels die alleen lokaal bestaan tellen standaard niet mee, dezelfde belofte die `kluis pull` doet door nooit ongevraagd te prunen. --strict telt ze wel.
