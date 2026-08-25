---
paths:
  - 'app/Models/{Variable,VariableVersion,Release,ReleaseItem,VariableAssignment}.php'
---

# Models

## Twee lagen versiebeheer: versies zijn append-only, releases pinnen versies
VariableVersion is append-only. Update nooit een bestaande versie; UpdateVariableValue schrijft een nieuwe rij, of geeft de huidige terug als de waarde gelijk is (checksum-vergelijking).

Release + ReleaseItem pinnen exacte variable_version_id's. Daardoor is "haal release 42 op" reproduceerbaar. Verwijder nooit een release.

Rollback werkt als revert (nieuwe versies met de oude waarden), niet als pin. Pinnen zou het portaal één waarde laten tonen terwijl de omgeving een andere serveert, en de eerstvolgende auto-publish zou de rollback ongedaan maken. RollbackToRelease::sharedImpact() vertelt welke andere omgevingen meeveranderen — toon dat altijd.

isShared() wordt afgeleid uit de koppelingen, niet opgeslagen: een boolean die hetzelfde beweert kan verouderen.

ciphertext en checksum staan in #[Hidden]; plaintext lezen gaat alleen via de expliciete reveal().
