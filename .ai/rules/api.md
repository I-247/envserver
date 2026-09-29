---
paths:
  - 'app/Http/Controllers/Api/**'
---

# Api

## Any API response carrying values needs viewSecrets, not view
`view` on a project only means "is a team member" — Viewers pass it. Every endpoint returning plaintext values (release, env, pending with reveal) must authorize viewSecrets, the same gate as a reveal in the portal; otherwise envclient pull becomes a way around the portal's permission.
