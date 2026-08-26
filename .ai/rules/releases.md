---
paths:
  - 'app/Actions/Releases/**'
---

# Releases

## Bulk-schrijvers publiceren via PublishAutomaticReleases::batch()
UpdateVariableValue en AttachVariableToEnvironment roepen elk PublishAutomaticReleases aan. Wie in een lus variabelen schrijft (CLI push, .env-import) krijgt daardoor een release per sleutel op elke omgeving met auto_publish. Wikkel zulke lussen in PublishAutomaticReleases::batch(): de automatische publishes worden opgespaard en na afloop één keer per omgeving uitgevoerd. Gooit de callback, dan wordt er niets gepubliceerd (de flush staat na de try, niet in de finally).

De action is daarom `scoped` gebonden in AppServiceProvider. Zonder die binding krijgt elke geneste action een eigen instantie en ziet die de open batch niet — haal dat niet weg.

RollbackToRelease lost hetzelfde probleem anders op: het gaat langs UpdateVariableValue heen en schrijft direct via WriteVariableVersion, en publiceert daarna zelf één keer.
