---
paths:
  - 'app/Actions/Webhooks/**'
---

# Webhooks

## Webhooks hangen aan RecordAuditEvent, niet aan losse acties
DispatchAuditWebhooks wordt aangeroepen vanuit RecordAuditEvent::handle(). Voeg nooit een tweede lijst "gebeurtenissen die we ook versturen" toe: de audittrail is de enige plek die alles weet, en een parallelle lijst loopt uit elkaar zodra iemand een AuditAction toevoegt.

Een endpoint met een lege events-filter krijgt daarom ook acties die later aan de enum worden toegevoegd. Dat is het gewenste gedrag, niet een gat.

De payload geeft AuditEvent::metadata ongewijzigd door. Dat mag alleen omdat die metadata per afspraak namen, aantallen en slugs bevat en nooit een waarde — zet dus nooit plaintext in audit-metadata.

Een nieuw endpoint ontvangt zijn eigen WebhookEndpointCreated-event. Tests die endpoints aanmaken hebben daarom Http::fake() nodig, en moeten Queue::fake() ná het aanmaken zetten.
