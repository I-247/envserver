---
paths:
  - routes/settings.php
---

# Routes

## Team lockout switches stay outside their own enforcement
A team can narrow its own access two ways: the IP allow list and the two-factor requirement. Both switches, plus teams.edit, teams.switch and teams.leave, live in the route group that carries only EnsureTeamMembership — deliberately without EnsureTeamIpIsAllowed or EnsureTeamTwoFactorRequirementIsMet.

Move them into the guarded group and an admin who narrowed the allow list before moving networks, or who turned on the second factor and then lost their authenticator, can only be let back in from the database.

EnsureTeamTwoFactorRequirementIsMet redirects to security.edit rather than aborting 403. That route must stay outside every team-scoped group, otherwise the redirect loops into the same check.
