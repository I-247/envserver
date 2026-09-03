---
paths:
  - app/Actions/Variables/PushVariables.php
  - 'app/Actions/Variables/**'
---

# Variables

## CLI-push, .env-import in het portaal en een push-capable deploy token delen één pad
PushVariables is het enige pad dat een map key/value op een omgeving toepast: `envclient push` (persoonlijke login), de import-modal in het portaal, én `DeployController::push` voor een deploy token met de `env:write`-scope (opt-in, de meeste tokens hebben hem niet). Die laatste roept `handle()` aan zonder `$author` — een null-actor is dus een geldige, bewuste staat, niet een bug. ConflictStrategy::Keep laat bestaande sleutels ongemoeid (telt als `skipped`), Overwrite is de default zodat de CLI zich niet anders gedraagt dan voorheen.

Een conflict wordt bepaald op de effectieve sleutel uit ResolveEnvironmentVariables, dus een alias telt mee. Matchen op variable.key zou de verkeerde variabele overschrijven.

## Delen is opt-in en verliest nooit stilzwijgend een waarde
Een variabele is pas te delen als het eigenaar-project hem via SetVariableShareable aanbiedt (variables.shareable). Die poort staat op drie plekken: ShareVariableRequest (nette foutmelding), ShareVariableWithEnvironment::assertOffered() (beschermt CLI/seeders/toekomstige callers die geen FormRequest passeren) en de where('shareable', true) in SharedVariableController::shareable() (anders lekken sleutelnamen in de dialog). Alle drie nodig; haal er geen weg.

Twee dingen zijn met opzet niet retroactief:
1. Het aanbod intrekken haalt de variabele niet weg bij projecten die hem al gebruiken.
2. Losmaken of een project verwijderen verwijdert een gedeelde variabele nooit; DetachVariableFromEnvironment en DeleteProject dragen het eigenaarschap over via Variable::heirProject().

In beide gevallen geldt dezelfde regel: een project verliest nooit een draaiende waarde door een beslissing die elders is genomen.
