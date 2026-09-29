---
paths:
  - 'app/Http/Requests/Teams/**, app/Rules/ValidTeamInvitation.php, app/Http/Controllers/Teams/**'
---

# Teams

## Invitations: assignable roles, verified mailbox, code never leaves the mail
Every role field a non-owner can submit uses TeamRole::assignable() (no Owner), for invitations exactly as for UpdateTeamMemberRequest; ValidTeamInvitation also rejects a stored owner invite. User implements MustVerifyEmail and ValidTeamInvitation requires hasVerifiedEmail(): matching the email string alone let anyone who registered an invitee's address first join the team. The invitation code is the bearer credential in the mail link, so teams.edit sends invitation ids and teams.invitations.destroy binds {invitation:id}; only the mail link and the login/register page it lands on ever carry the plain code. The database stores sha256(code) (TeamInvitation::hashCode, look up with ->withCode()); the plain value exists only as $invitation->plainCode on the request that created it and in the TeamInvitation notification, which is ShouldBeEncrypted because it sits in the queue. Accept/decline bind on id: the verified matching email is the proof there, not the code.
