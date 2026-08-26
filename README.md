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

## IP-whitelisting

Drie lijsten, alle drie optioneel en standaard uit. Een lege lijst betekent
"geen beperking", nooit "niemand mag erin".

| Lijst | Waar | Wat het afschermt |
|---|---|---|
| `KLUIS_IP_ALLOWLIST` | `.env` | de hele webapplicatie, inloggen inbegrepen |
| Team-allowlist | teaminstellingen | de pagina's van dat ene team |
| Environment-allowlist | omgeving bewerken | downloaden met een deploy-token |

De config-lijst is het net onder de rest: die staat op de server en is niet
vanuit de interface te verruimen, dus een overgenomen account kan hem niet
oprekken. Een team kan zichzelf daarbinnen verder inperken, nooit verder
oprekken. Het opslaan van een teamlijst waar je eigen adres niet op staat
wordt geweigerd; de teaminstellingenpagina blijft bovendien bereikbaar zodat
niemand zich definitief buitensluit.

De environment-lijst geldt alléén voor machines: een deployserver of CI die
met een deploy-token bij `/api/v1/deploy/*` aanklopt. Ontwikkelaars in de
browser of met `kluis pull` vallen onder de twee lijsten hierboven, want hun
adres wisselt. Een geweigerde pull komt in de audittrail te staan.

Elke lijst accepteert losse adressen en CIDR-ranges, IPv4 en IPv6.

```shell
KLUIS_IP_ALLOWLIST="203.0.113.4,10.0.0.0/8,2001:db8::/32"
```

Draait Kluis achter een load balancer of reverse proxy, zet dan
`KLUIS_TRUSTED_PROXIES`. Zonder dat ziet Laravel het adres van de proxy in
plaats van dat van de client en vergelijkt elke lijst het verkeerde adres.
Vertrouw alleen proxies die je zelf beheert: van een vertrouwde proxy wordt
`X-Forwarded-For` op zijn woord geloofd, en dat is een header die een client
kan schrijven.

## Drift tussen omgevingen

`kluis pull` en de releasegeschiedenis beantwoorden "wat is hier veranderd".
De vraag die je in de praktijk vaker stelt staat op de driftpagina van een
project: *wat heeft staging wel en productie niet, en waar draaien we twee
keer dezelfde waarde?*

De vergelijking gaat over `variable_versions.checksum` — een HMAC met de
datakey van het team — en niet over de waarde zelf. Twee omgevingen
vergelijken kost dus geen enkele ontsleuteling, en de pagina toont per rij een
groepsletter in plaats van die vingerafdruk: gelijke letter is gelijke waarde,
en de checksum verlaat de server nooit.

Een dubbele waarde wordt alleen als bevinding gemeld als een van de betrokken
omgevingen `auto_publish` uit heeft staan. Dat is de vlag die de applicatie al
gebruikt voor "deze omgeving promoot je bewust", en productie draagt hem
standaard. Matchen op de naam "production" zou een team dat zijn omgeving
anders noemt stil overslaan. `LOG_CHANNEL` dat in drie ontwikkelomgevingen
gelijk is, is geen bevinding, en een waarschuwing waar niemand iets mee kan
leert mensen de rest ook te negeren.

## Rotatie

De master key kon al roteren; over de secrets zelf wist Kluis niets. Een
access key van drie jaar oud zag er precies zo uit als eentje van vanmorgen.

| Waar | Wat |
|---|---|
| Teaminstellingen | `default_rotate_after_days`, het aantal dagen dat een waarde mag staan |
| Per variabele | `rotate_after_days`, dat de teamwaarde overschrijft |

Leeg op beide niveaus betekent **geen beleid**, en dat is iets anders dan
"nooit roteren": er wordt niets beweerd over de variabele, dus er wordt ook
niets over gemeld. Een buildnummer en een databasewachtwoord verouderen nu
eenmaal niet even snel, dus één teambreed getal zou of nutteloos of ruis zijn.

Verlopen secrets staan op het dashboard en krijgen een badge op de
omgevingspagina. Kluis roteert nooit zelf iets — het vertelt je alleen welke
waarden stilstaan.

## Webhooks

Elke auditregel kan naar een endpoint van het team. Ze hangen aan
`RecordAuditEvent` en niet aan de losse acties: de trail is al de enige plek
die alles weet, en een tweede lijst "gebeurtenissen die we óók versturen" zou
uit elkaar lopen zodra iemand een actie toevoegt.

| Soort | Body |
|---|---|
| `json` | de hele gebeurtenis, met `X-Kluis-Signature: sha256=…` over precies de verzonden bytes |
| `slack` | één `text`-regel, want meer leest Slack niet |

De payload draagt wat de trail draagt: namen, aantallen en slugs, nooit een
waarde. Het signeergeheim wordt door de server gemaakt, één keer getoond, en
staat versleuteld met `APP_KEY` op — bewust niet in de envelope van de kluis
zelf: het kwijtraken kost je een opnieuw ingestelde webhook, geen secret.

Alleen https, en niet naar een adres op het eigen netwerk van de server: een
endpoint dat naar `169.254.169.254` wijst maakt van de queue-worker een manier
om bij dingen te komen die alleen hij kan zien. Bezorging loopt via de queue
met drie pogingen; twintig mislukte gebeurtenissen op rij zetten het endpoint
uit, zodat een verdwenen URL niet eeuwig opnieuw geprobeerd wordt.

Een nieuw endpoint krijgt meteen zijn eigen aanmaakgebeurtenis binnen. Dat is
met opzet de goedkoopste "werkt deze URL" die er is.

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
kluis pull                                # tonen wat er wijzigt, dan bevestigen
kluis pull --constructive                 # ook nieuwe keys toevoegen
kluis pull --prune                        # ook keys weghalen die de release niet heeft
kluis pull --dry-run                      # alleen kijken, nooit schrijven
kluis pull --force                        # zonder vragen toepassen (deploy)
kluis check                               # faalt met exit 2 als je .env afwijkt
kluis run -- php artisan migrate --force  # injecteren zonder .env te schrijven
kluis seal                                # release versleuteld lokaal opslaan
```

`kluis seal` legt de release als `.env.kluis` op schijf, versleuteld met een
sleutel die uit het deploy-token wordt afgeleid. Daarna leest `kluis run` uit
dat bestand in plaats van bij de server langs te gaan: geen netwerk, geen
levend token, en nooit plaintext op schijf.

Op een deployserver:

```shell
export KLUIS_SERVER=https://kluis.example.com
export KLUIS_CLIENT_ID=...
export KLUIS_CLIENT_SECRET=...
kluis pull --constructive --force --out .env
```

`--force` is daar geen luxe: een deployserver heeft geen terminal om de
bevestiging aan te stellen, dus zonder die vlag stopt de pull met een
foutmelding in plaats van te gokken.

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
