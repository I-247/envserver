---
paths:
  - 'cli/internal/vault/**'
---

# Vault

## Vault-sleutel komt uit het deploy-token, niet uit een eigen secret
De lokale `.env.kluis` wordt versleuteld met een sleutel die via HKDF-SHA256 uit KLUIS_CLIENT_SECRET komt, met de client-id als `info`-label. Reden: wie de omgeving mag pullen mag het bestand openen, en er is geen tweede secret om te beheren. Een tokenrotatie sluit het oude bestand vanzelf af.

HKDF en bewust geen PBKDF2/Argon2: het token-secret is al hoge-entropie willekeur uit Passport, dus een trage KDF koopt niets. KLUIS_VAULT_KEY (hex, base64 of lange tekst) is de uitweg voor een laptop zonder client secret; ook dat gaat door HKDF heen, dus het is nooit rechtstreeks de AES-sleutel.

Formaat is `kluis-vault.v1.<header>.<nonce>.<ciphertext>` — dezelfde versieprefix-afspraak als App\Cryptography. Verhoog de versie bij elk nieuw algoritme. De header is niet versleuteld maar wél AAD, en draagt een key-id (tweede HKDF met een ander label) zodat een verkeerde sleutel als ErrWrongKey herkenbaar is in plaats van als generieke decryptiefout. Project- en omgevingsnaam horen ín de ciphertext, niet in de header.
