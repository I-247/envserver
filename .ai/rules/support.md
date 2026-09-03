---
paths:
  - app/Support/EnvFileRenderer.php
  - 'app/Support/EnvFile*.php'
  - app/Support/IpAllowList.php
---

# Support

## .env-escaping volgt phpdotenv exact — niet zelf bedenken
De regels komen uit vendor/vlucas/phpdotenv/src/Parser/EntryParser.php:

- Binnen dubbele quotes is alleen \" \\ \$ \n \r \t \f \v geldig. Een andere escape is een parse error, geen letterlijke backslash.
- Een onge-escapete $ betekent variabele-interpolatie, óók binnen dubbele quotes. Een wachtwoord als p$ssw0rd wordt anders stil iets anders.
- Enkele quotes kennen geen escapes, dus daar past nooit een ' in. Gebruik altijd dubbele quotes.

De Go-CLI (cli/internal/envfile) implementeert dezelfde regels; wijzig ze nooit aan één kant. Beide kanten hebben een round-trip-test over dezelfde lastige waarden.

## Lezen van een .env gaat via phpdotenv, nooit via eigen regex
EnvFileParser delegeert aan Dotenv\Parser\Parser: dat is precies de parser waar EnvFileRenderer voor schrijft, dus zelf quoting-regels nabouwen laat de twee kanten uit elkaar lopen. De unit-test doet een round trip renderer -> parser over dezelfde lastige waarden als de Go-CLI-test.

Interpolatie wordt bewust niet opgelost: ${APP_URL}/api gaat letterlijk de envclient in. Een bare naam zonder = telt als lege waarde; een dubbele sleutel houdt de laatste waarde, zoals het laden van het bestand zou doen.

## Een lege IP-allowlist betekent "geen beperking", nooit "niemand mag erin"
IpAllowList::allows() geeft true zodra de lijst leeg is. Alle drie de allowlists (config, team, environment) zijn opt-in; als leeg als "blokkeer alles" werd gelezen, sluit een team zich buiten op het moment dat het het veld leegmaakt.

Daarom slaat toStorage() ook null op in plaats van []: één waarde voor "uit", niet twee die hetzelfde betekenen.

Matching gaat via Symfony's IpUtils::checkIp — die zit al in Laravel en doet IPv4 én IPv6 met CIDR. Schrijf geen eigen prefix-rekenwerk.

Een request zonder resolvebaar IP wordt geweigerd zodra de lijst wél iets beperkt: van een onbekend adres kun je niet aantonen dat het op de lijst staat.
