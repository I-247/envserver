---
paths:
  - 'app/Jobs/DeliverWebhook.php, app/Http/Requests/Teams/SaveWebhookEndpointRequest.php, app/Support/PublicAddress.php'
---

# Teams Support

## Outbound webhook requests go through PublicAddress
Never check a webhook host by its spelling: 127.1, 2130706433, [::ffff:127.0.0.1] and names resolving to 10.x all bypass a string list. PublicAddress::addressesFor() resolves via HostResolver and requires every address to be global (FILTER_FLAG_GLOBAL_RANGE plus NAT64/6to4/multicast). It runs on save and again in DeliverWebhook on every send; the job pins curl to the checked address (CURLOPT_RESOLVE via PublicAddress::pin), uses withoutRedirecting(), and counts a 3xx as a failure.

Tests must call fakeDns() (tests/Pest.php) before creating an endpoint, otherwise they hit real DNS and hooks.example.com does not resolve.
