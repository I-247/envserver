---
paths:
  - 'app/Http/Controllers/Environments/**'
---

# Controllers Environments

## Een .env-export is een bulk-reveal: wachtwoord elke keer, niet RequirePassword
EnvFileDownloadController zet elke waarde van een omgeving in één keer in plaintext op de schijf. Daarom hangt hij aan `viewSecrets` (dezelfde poort als VariableController::reveal), niet aan `view` of `manageVariables`, en schrijft hij een AuditAction::EnvFileDownloaded met alleen aantallen en slugs in de metadata — nooit een waarde.

De bevestiging is een `current_password`-veld in DownloadEnvFileRequest, bewust niet de RequirePassword-middleware zoals bij settings/security: die onthoudt een bevestiging `auth.password_timeout` lang (drie uur), waardoor de tweede export van de dag ongevraagd langs de poort loopt. De route draagt `throttle:6,1` omdat een wachtwoordveld anders een brute-force-oppervlak is.

Renderen gaat altijd via ResolveEnvironmentVariables::render(), nooit via een eigen loop over assignments: alleen die weg lost aliassen en gedeelde variabelen op dezelfde manier op als `envclient pull`, en gebruikt de phpdotenv-escaping uit support.md.

## Read a checkbox field with $request->boolean(), never the "boolean" validation rule
A native checked HTML checkbox submits the string "on" (Radix's Checkbox does too, via its default `value="on"`). Laravel's `boolean` validation rule only accepts `true, false, 0, 1, "0", "1"` — "on" fails it, and on a web (non-JSON) route that 302s back with a validation error that silently does nothing if the form never renders that field's error. DeployTokenController::store hit exactly this with `can_push`. Read a checkbox with `$request->boolean('field')` (FILTER_VALIDATE_BOOLEAN, accepts "on"/"1"/"true"/"yes"), not `$request->validate([...'boolean'])`.

## A single reveal needs a recent password confirmation (SecretAccessWindow)
VariableController::reveal answers 423 until the session confirmed its password within ENVSERVER_REVEAL_CONFIRMATION_MINUTES (default 10) via POST {current_team}/secrets/confirm (ConfirmSecretAccessController, throttle:6,1). This is its own session key (App\Support\SecretAccessWindow), deliberately not Laravel's password.confirm, which lasts hours. The 423 is checked before the audit event, so a refused reveal is not logged as a reveal. The variable-value dialog handles 423 by asking for the password in place and retrying. Tests call confirmSecretAccess() (tests/Pest.php) before hitting reveal. The .env export still asks for the password on every download, independent of this window.
