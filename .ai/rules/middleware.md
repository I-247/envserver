---
paths:
  - app/Http/Middleware/ResolveDeployToken.php
---

# Middleware

## Passport toetst scopes niet per client — de allow-list op DeployToken doet dat
Passport valideert een gevraagde scope tegen Passport::tokensCan(), dus tegen wat de applicatie kent, niet tegen wat deze client mocht krijgen. Zonder de scopes-kolom op deploy_tokens kan een read-only deployclient bij het token-exchange gewoon env:write vragen en krijgen.

ResolveDeployToken controleert daarom twee dingen: de scope op het access token én DeployToken::allows(). Haal die tweede check nooit weg.

De koppeling client -> omgeving staat ook in deploy_tokens, niet in een scope-string: OAuth weet wat een token mag, niet waarop.
