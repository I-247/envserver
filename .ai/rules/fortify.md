---
paths:
  - 'app/Providers/FortifyServiceProvider.php, app/Actions/Fortify/**'
---

# Fortify

## Registration toggle must still honor pending team invitations
`config('envserver.registration_enabled')` (env `ENVSERVER_REGISTRATION_ENABLED`, default true) gates the public /register page and endpoint. When it's false, registration must still succeed for an email with a pending, unexpired `TeamInvitation` — that's the only way a brand new person can accept an invite. This is enforced in two places that must stay in sync: `FortifyServiceProvider::registrationEnabled()` (gates the GET /register view, redirects to login otherwise) and `CreateNewUser::hasPendingInvitation()` (gates the POST). The login page's "Sign up" link is hidden via the `canRegister` prop using the same logic.
