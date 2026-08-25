# Kluis

Centraal beheer van environment- en securityvariabelen, met versiebeheer,
variabelen die je over meerdere projecten deelt, en een CLI die je servers
tijdens een deploy van een verse `.env` voorziet.

## Hoe het in elkaar zit

```
Team ──< Project ──< Environment ──< Release ──< ReleaseItem
  │                       │                          │
  └──< Variable ──< VariableVersion <────────────────┘
           └──< VariableAssignment >── Environment
```

Twee lagen versiebeheer, en dat is met opzet:

- **`VariableVersion`** is de geschiedenis van één variabele. Waardes worden
  nooit overschreven, alleen toegevoegd.
- **`Release`** is een onveranderlijke momentopname van een hele omgeving, met
  per regel een exacte `variable_version_id` erin gepind. Daardoor levert
  release 42 over een maand hetzelfde bestand op als vandaag, en is
  terugdraaien een opzoeking in plaats van een reconstructie.

Een variabele hangt via `VariableAssignment` aan N omgevingen. Eén wijziging
bereikt ze dus allemaal. Omgevingen met `auto_publish` krijgen meteen een
nieuwe release; de rest — standaard productie — blijft op "pending" staan tot
je bewust promoot.

## Encryptie

```
KLUIS_MASTER_KEY
      └─ wrapt ─> team_keys.wrapped_dek   (per team)
                        └─ AES-256-GCM ─> variable_versions.ciphertext
```

De datakey zit op **team**-niveau, niet per project: een variabele moet over
projecten gedeeld kunnen worden, en een sleutel per project zou bij elke
deling her-encryptie afdwingen.

De master key staat los van `APP_KEY`. `APP_KEY` roteren kost je hooguit alle
sessies; de sleutel die de datakeys wrapt kwijtraken kost je elk secret.

Roteren gaat zo:

```shell
php artisan kluis:master-key --force   # oude sleutel schuift door naar PREVIOUS
php artisan kluis:rewrap               # alle team-keys op de nieuwe sleutel
# daarna kan KLUIS_PREVIOUS_MASTER_KEYS leeg
```

`kluis:rewrap` hoeft geen enkel secret opnieuw te versleutelen — alleen de
gewrapte datakey per team. Dat is waar de envelope voor bedoeld is.

## Toegang

| Wie | Hoe |
|---|---|
| Browser | Fortify-sessies |
| `kluis login` op een laptop | Passport device grant |
| Deployserver of CI | Passport client credentials, via een deploy-token |

Een deploy-token koppelt een OAuth-client aan precies één omgeving. Dat staat
in een eigen tabel en niet in een scope-string: OAuth weet wél wat een token
mag, maar niet waarop. Het token draagt bovendien een eigen lijst toegestane
scopes, omdat Passport een gevraagde scope toetst aan wat de applicatie kent
en niet aan wat de client mocht krijgen.

## Aan de slag

```shell
composer setup     # deps, sleutels, migraties, CLI-client, assets
composer dev       # server, queue, logs en vite
```

`composer setup` genereert `APP_KEY`, `KLUIS_MASTER_KEY`, de Passport-sleutels
en de OAuth-client waarmee de CLI inlogt.

## De CLI

Zie [`cli/README.md`](cli/README.md). Kort:

```shell
kluis login
kluis init --server ... --team ... --project ... --environment ...
kluis pull                                # alleen bestaande keys bijwerken
kluis pull --constructive                 # ook nieuwe toevoegen
kluis run -- php artisan migrate --force  # injecteren zonder .env te schrijven
```

Op een deployserver:

```shell
export KLUIS_SERVER=https://kluis.example.com
export KLUIS_CLIENT_ID=...
export KLUIS_CLIENT_SECRET=...
kluis pull --constructive --out .env
```

## Tests

```shell
php artisan test --compact     # Pest 5
composer ci:check              # pint, phpstan, eslint, prettier, tsc, tests
cd cli && go test ./...
```

## Databases

SQLite volstaat om te beginnen. Voor productie is Postgres beter: er wordt
gelijktijdig geschreven vanuit het portaal én vanuit de CLI, en `jsonb` past
bij de audit-metadata. Het schema is Postgres-compatibel gehouden.
