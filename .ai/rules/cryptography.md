---
paths:
  - 'app/Cryptography/**'
---

# Cryptography

## Master key staat los van APP_KEY, datakey is team-scoped
ENVSERVER_MASTER_KEY is bewust niet APP_KEY: APP_KEY roteren kost alleen sessies, de master key kwijtraken kost elk secret.

De data encryption key zit per team, niet per project. Reden: een variabele kan over projecten gedeeld worden, en een sleutel per project zou bij elke deling her-encryptie afdwingen.

Ciphertext-payloads dragen een versieprefix ("v1.nonce.tag.payload") zodat een toekomstig schema naast het huidige kan bestaan. Voeg nooit een nieuw algoritme toe zonder de prefix te verhogen.

Alleen App\Cryptography mag MasterKeyProvider aanraken; een arch-test in tests/Feature/ArchTest.php dwingt dat af.
