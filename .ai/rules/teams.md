---
paths:
  - 'app/Http/Requests/Teams/**, app/Rules/ValidTeamInvitation.php, app/Http/Controllers/Teams/**'
---

# Teams

## Invitations: assignable roles, verified mailbox, code never leaves the mail
Every role field a non-owner can submit uses TeamRole::assignable() (no Owner), for invitations exactly as for UpdateTeamMemberRequest; ValidTeamInvitation also rejects a stored owner invite. User implements MustVerifyEmail and ValidTeamInvitation requires hasVerifiedEmail(): matching the email string alone let anyone who registered an invitee's address first join the team. The invitation code is the bearer credential in the mail link, so teams.edit sends invitation ids and teams.invitations.destroy binds {invitation:id}; only the invitee's own pages (login, dashboard) ever see a code.
