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

## v2 payloads are bound to their row; always pass the context and key version
Since v2 every payload authenticates a context as AAD: TeamKeyManager::valueContext(team, variable, version) for values, teamKeyContext(team, keyVersion) for wrapped keys. Without it a DB writer could copy DB_PASSWORD's ciphertext into another variable and it would decrypt. Encrypt and decrypt must pass the same context; VariableVersion::reveal() also passes team_key_version so a value is read with the key it was written with, never "current".

v1 (no context) is still read for old rows but never written; envserver:upgrade-encryption (run by a migration) re-wraps team keys and re-encrypts v1 values — the only sanctioned in-place rewrite of a VariableVersion. provision() locks the team row and returns an existing key; rewrap() covers retired keys and round-trip checks before saving.
