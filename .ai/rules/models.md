---
paths:
  - 'app/Models/{Variable,VariableVersion,Release,ReleaseItem,VariableAssignment}.php'
  - app/Models/Variable.php
---

# Models

## Twee lagen versiebeheer: versies zijn append-only, releases pinnen versies
VariableVersion is append-only. Update nooit een bestaande versie; UpdateVariableValue schrijft een nieuwe rij, of geeft de huidige terug als de waarde gelijk is (checksum-vergelijking).

Release + ReleaseItem pinnen exacte variable_version_id's. Daardoor is "haal release 42 op" reproduceerbaar. Verwijder nooit een release.

Rollback werkt als revert (nieuwe versies met de oude waarden), niet als pin. Pinnen zou het portaal één waarde laten tonen terwijl de omgeving een andere serveert, en de eerstvolgende auto-publish zou de rollback ongedaan maken. RollbackToRelease::sharedImpact() vertelt welke andere omgevingen meeveranderen — toon dat altijd.

isShared() wordt afgeleid uit de koppelingen, niet opgeslagen: een boolean die hetzelfde beweert kan verouderen.

ciphertext en checksum staan in #[Hidden]; plaintext lezen gaat alleen via de expliciete reveal().

## Eigenaarschap wordt opgeslagen, delen wordt afgeleid
variables.owner_project_id en variables.shareable zijn kolommen, isShared()/isSharedAcrossProjects() blijven afgeleid. Reden: delen is een feit over de huidige koppelingen (altijd herberekenbaar, dus een kolom kan alleen maar verouderen), terwijl eigenaarschap en het aanbod juist beslissingen zijn die niet uit de koppelingen volgen — en die het losmaken van de laatste koppeling moeten overleven.

Gebruik Variable::heirProject() om te bepalen welk project een variabele erft. Query vanaf Project en join naar variable_assignments. Doe dit nooit andersom: VariableAssignment::query()->select('projects.*') geeft een VariableAssignment terug met de kolommen van een project, dus ->id is dan het assignment-id en je schrijft stilzwijgend het verkeerde owner_project_id weg.
