---
paths:
  - app/Http/Middleware/ResolveDeployToken.php
  - 'app/Http/Middleware/{EnsureIpIsAllowed,EnsureTeamIpIsAllowed,TrustProxies}.php'
---

# Middleware

## Passport toetst scopes niet per client — de allow-list op DeployToken doet dat
Passport valideert een gevraagde scope tegen Passport::tokensCan(), dus tegen wat de applicatie kent, niet tegen wat deze client mocht krijgen. Zonder de scopes-kolom op deploy_tokens kan een read-only deployclient bij het token-exchange gewoon env:write vragen en krijgen.

ResolveDeployToken controleert daarom twee dingen: de scope op het access token én DeployToken::allows(). Haal die tweede check nooit weg.

De koppeling client -> omgeving staat ook in deploy_tokens, niet in een scope-string: OAuth weet wat een token mag, niet waarop.

## IP-allowlists: config is het net, team kan alleen verder inperken
ENVSERVER_IP_ALLOWLIST staat in config en is bewust niet vanuit de interface te bewerken: een overgenomen account mag het net onder de applicatie niet oprekken. EnsureIpIsAllowed hangt daarom met `web(prepend:)` vóór de sessie — een geweigerd adres raakt geen sessie aan.

De team-allowlist (EnsureTeamIpIsAllowed) kan alleen verder inperken. teams.edit, teams.ip-allowlist.update, switch en leave staan er expres buiten: zonder die ontsnappingsroute moet een admin die van netwerk wisselt via de database worden teruggezet. SaveTeamIpAllowListRequest weigert bovendien een lijst waar het eigen adres niet op staat.

TrustProxies leest config('envserver.trusted_proxies') in proxies() en niet via $middleware->trustProxies() in bootstrap/app.php: die closure draait vóórdat config geladen is en config() gooit daar. Zonder ingestelde proxy vergelijkt elke allowlist het adres van de load balancer.
