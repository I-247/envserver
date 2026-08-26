---
paths:
  - app/Data/SecretAge.php
---

# Data

## Geen rotatiebeleid is niet hetzelfde als "nooit roteren"
De effectieve interval is variables.rotate_after_days ?? teams.default_rotate_after_days. Null op beide niveaus betekent dat er niets beweerd wordt over de variabele, dus SecretAge::isOverdue() is dan altijd false. Behandel null nooit als "verlopen" of als een impliciete standaardtermijn.

ReviewSecretAge haalt de datum van de nieuwste versie op met een gecorreleerde subquery (rotated_at), niet via de versions-relatie: het dashboard vraagt dit voor een heel team tegelijk. Lees die kolom met getAttribute('rotated_at') — het is geen kolom op het model en heeft dus geen cast.

SetTeamRotationPolicy vergelijkt de oude waarde vóór het opslaan in plaats van wasChanged() te gebruiken: na een eerdere update op hetzelfde model blijft wasChanged() true en schrijf je een auditregel voor een wijziging die niet plaatsvond.
