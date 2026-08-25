---
paths:
  - app/Support/EnvFileRenderer.php
---

# Support

## .env-escaping volgt phpdotenv exact — niet zelf bedenken
De regels komen uit vendor/vlucas/phpdotenv/src/Parser/EntryParser.php:

- Binnen dubbele quotes is alleen \" \\ \$ \n \r \t \f \v geldig. Een andere escape is een parse error, geen letterlijke backslash.
- Een onge-escapete $ betekent variabele-interpolatie, óók binnen dubbele quotes. Een wachtwoord als p$ssw0rd wordt anders stil iets anders.
- Enkele quotes kennen geen escapes, dus daar past nooit een ' in. Gebruik altijd dubbele quotes.

De Go-CLI (cli/internal/envfile) implementeert dezelfde regels; wijzig ze nooit aan één kant. Beide kanten hebben een round-trip-test over dezelfde lastige waarden.
