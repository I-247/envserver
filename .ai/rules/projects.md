---
paths:
  - 'app/Actions/Projects/**'
---

# Projects

## Verweesde variabelen opruimen: twee voorwaarden, geen van beide optioneel
DeleteProject ruimt variabelen op die door de projectverwijdering hun laatste koppeling verliezen. "Nul assignments" is daarvoor niet genoeg als criterium:

1. CreateVariable maakt een variabele zonder assignment (koppelen gaat apart via AttachVariableToEnvironment). Een net aangemaakte, nog niet gekoppelde variabele heeft dus ook nul assignments — vandaar dat alleen kandidaten meegaan die aan dit project gekoppeld wáren.
2. release_items.variable_id cascadeert. Een losgekoppelde variabele kan nog gepind staan in een release van een ander project; die verwijderen holt die release uit en breekt de reproduceerbaarheid uit .ai/rules/models.md. Daarom ook whereDoesntHave('releaseItems').

Losmaken via VariableController::destroy ruimt bewust niets op: de variabele kan elders nog in gebruik zijn.
