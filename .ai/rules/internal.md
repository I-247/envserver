---
paths:
  - 'cli/internal/**'
---

# Internal

## Secret files via securefile, server strings via the ui sanitizer
Every file that can hold a secret (pulled .env, seal/unseal output, credentials.json) is written with securefile.WriteFile: refuses symlinks and non-regular files, temp file in the same dir with 0600 + fsync + rename, so an existing 0644 file ends up 0600 and a crash never truncates it. Config dirs go through securefile.PrivateDir (0700). Never use os.WriteFile for these.

Anything the server (or another user via release messages) controls is printed through ui.Printer, which strips control/escape sequences centrally; p.Plain() stdout data is never altered. Discovery endpoints and the device verification URI must be same-origin as the configured server. Self-update caps download sizes and refuses a lower version even with --force.
