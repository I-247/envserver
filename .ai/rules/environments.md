---
paths:
  - 'app/Actions/Environments/**'
  - app/Actions/Environments/CompareEnvironments.php
---

# Environments

## Environments: slug bevriezen en opruimen bij verwijderen
De slug wordt alleen bij aanmaken gezet (Environment::generateUniqueSlug) en nooit hergenereerd bij hernoemen: deploy tokens en de CLI adresseren een environment op slug, dus een nieuwe slug snijdt draaiende servers af. Zelfde afspraak als bij Project.

DeleteEnvironment spiegelt DeleteProject, met één verschil: het project overleeft meestal. Een variabele die deze omgeving loslaat gaat alleen naar een ander project als het eigenaarsproject zelf geen enkele koppeling meer overhoudt — vandaar de `$heir->is($project)`-guard, anders draagt het project aan zichzelf over en schrijf je een misleidend auditevent.

De whereDoesntHave('releaseItems')-guard beschermt releases búiten deze omgeving. Releases van de omgeving zelf cascaderen mee, dus een variabele die alleen daar gepind stond wordt wél opgeruimd; dat is geen schending van "verwijder nooit een release" uit models.md maar hetzelfde geaccepteerde geval als projectverwijdering.

## Drift vergelijkt checksums, en "guarded" betekent auto_publish uit
variable_versions.checksum is een HMAC met de datakey van het team, dus gelijke checksum = gelijke waarde binnen dat team. Vergelijk omgevingen daarmee; ontsleutel nooit om te vergelijken.

De checksum mag de server niet verlaten. CompareEnvironments zet hem per rij om in een groepsnummer (1, 2, 3...) en alleen dat gaat naar de frontend.

Een dubbele waarde is alleen een bevinding als een van de omgevingen auto_publish uit heeft staan. Match nooit op de naam "production": auto_publish is de bestaande vlag voor "bewust promoten" en werkt ook voor teams die hun omgevingen anders noemen.
