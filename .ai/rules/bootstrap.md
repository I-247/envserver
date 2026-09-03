---
paths:
  - bootstrap/app.php
---

# Bootstrap

## API-routes staan buiten ConvertEmptyStringsToNull
Voor Envserver is een lege waarde echte data: `AWS_BUCKET=` in een .env is een sleutel mét een lege waarde, niet een sleutel zonder waarde. Laravel's ConvertEmptyStringsToNull maakt er standaard null van, waarna `variables.*` => ['present', 'string'] in PushVariablesRequest een 422 gooit op elke lege sleutel.

Daarom staat in withMiddleware een `convertEmptyStringsToNull(except: [fn (Request $r) => $r->is('api/*')])`. Haal die uitzondering niet weg: zonder is `envclient push` van een normale .env kapot. Op web-routes blijft de conversie wél aan.
