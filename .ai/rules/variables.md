---
paths:
  - app/Actions/Variables/PushVariables.php
  - 'app/Actions/Variables/**'
---

# Variables

## CLI-push en .env-import in het portaal delen één pad
PushVariables is het enige pad dat een map key/value op een omgeving toepast: zowel `kluis push` als de import-modal in het portaal. ConflictStrategy::Keep laat bestaande sleutels ongemoeid (telt als `skipped`), Overwrite is de default zodat de CLI zich niet anders gedraagt dan voorheen.

Een conflict wordt bepaald op de effectieve sleutel uit ResolveEnvironmentVariables, dus een alias telt mee. Matchen op variable.key zou de verkeerde variabele overschrijven.

## Delen is opt-in en verliest nooit stilzwijgend een waarde
Een variabele is pas te delen als het eigenaar-project hem via SetVariableShareable aanbiedt (variables.shareable). Die poort staat op drie plekken: ShareVariableRequest (nette foutmelding), ShareVariableWithEnvironment::assertOffered() (beschermt CLI/seeders/toekomstige callers die geen FormRequest passeren) en de where('shareable', true) in SharedVariableController::shareable() (anders lekken sleutelnamen in de dialog). Alle drie nodig; haal er geen weg.

Twee dingen zijn met opzet niet retroactief:
1. Het aanbod intrekken haalt de variabele niet weg bij projecten die hem al gebruiken.
2. Losmaken of een project verwijderen verwijdert een gedeelde variabele nooit; DetachVariableFromEnvironment en DeleteProject dragen het eigenaarschap over via Variable::heirProject().

In beide gevallen geldt dezelfde regel: een project verliest nooit een draaiende waarde door een beslissing die elders is genomen.
