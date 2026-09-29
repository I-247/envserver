---
paths:
  - routes/settings.php
  - routes/api.php
---

# Routes

## Team lockout switches stay outside their own enforcement
A team can narrow its own access two ways: the IP allow list and the two-factor requirement. Both switches, plus teams.edit, teams.switch and teams.leave, live in the route group that carries only EnsureTeamMembership — deliberately without EnsureTeamIpIsAllowed or EnsureTeamTwoFactorRequirementIsMet.

Move them into the guarded group and an admin who narrowed the allow list before moving networks, or who turned on the second factor and then lost their authenticator, can only be let back in from the database.

EnsureTeamTwoFactorRequirementIsMet redirects to security.edit rather than aborting 403. That route must stay outside every team-scoped group, otherwise the redirect loops into the same check.

## Personal API tokens get the same fences as the portal
The auth:api group carries EnsureIpIsAllowed (operator list), and the team routes EnsureTeamIpIsAllowed + EnsureTeamTwoFactorRequirementIsMet (which aborts 403 for api/* instead of redirecting). Without them a stolen `envclient login` token worked from any network and ignored the team's 2FA requirement. Deploy-token routes deliberately do not get the operator/team lists: deploy servers live elsewhere and use the environment's own allow list via ResolveDeployToken.

The whole v1 prefix is throttle:api (AppServiceProvider::configureRateLimiting, ENVSERVER_API_REQUESTS_PER_MINUTE). Token lifetime is ENVSERVER_API_TOKEN_DAYS (default 30), not Passport's one year.
