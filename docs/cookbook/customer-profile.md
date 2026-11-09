---
title: Customer profile
weight: 40
description: Tell Assistant what a customer has in your service and how their account is doing.
---

# Customer profile

Every Cbox service knows part of the customer: ID knows the organization, Billing knows the plan and invoices, Sites knows projects, sites and domains, Status knows incidents. The customer profile lets each service declare that part once, in the same shape, so Assistant can build one picture of the customer.

Assistant uses the profile to:

1. offer "Which site or domain is this about?" choices on the customer's support request form (pickable resources),
2. show a cross-product customer card to staff in the inbox,
3. feed triage: account value, an open incident, a recent change,
4. give the AI conversation a short, current context.

Assistant fetches the profile with one signed `GET /ai-assistant/profile` per service, always as the real Cbox user. Your guards and list closures run as that user, so the profile shows what the customer is allowed to see in your service, and nothing more.

## A complete example

A Sites service with sites and a pickable list of domains, plus account signals. Register it in a service provider's `boot()`.

```php
use App\Models\Domain;
use App\Models\Site;
use App\Support\Memberships;
use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Profile\Lifecycle;
use Cbox\AiAssistant\Connector\Profile\ResourceItem;
use Cbox\AiAssistant\Connector\Profile\ResourceStatus;
use Cbox\AiAssistant\Connector\Profile\SignalFlag;
use Cbox\AiAssistant\Connector\Profile\Signals;
use Cbox\AiAssistant\Connector\Support\ToolContext;

$member = fn (ToolContext $ctx): bool => $ctx->user !== null
    && $ctx->accountId() !== null
    && app(Memberships::class)->isMember($ctx->user, $ctx->accountId());

AiAssistant::resource('sites_sites')
    ->label('Sites')
    ->singular('Site')
    ->guard($member)
    ->list(fn (ToolContext $ctx, array $filters) => Site::query()
        ->where('organization_id', $ctx->accountId())
        ->when($filters['q'] ?? null, fn ($query, $q) => $query->where('name', 'like', "%{$q}%"))
        ->latest('updated_at')
        ->lazy()
        ->map(fn (Site $site) => new ResourceItem(
            id: (string) $site->id,
            label: $site->name,
            status: $site->is_down ? ResourceStatus::Error : ResourceStatus::Ok,
            statusLabel: $site->is_down ? 'Down' : null,
            url: route('sites.show', $site),
            attributes: ['php' => $site->php_version, 'region' => $site->region],
            metrics: ['deploys_30d' => $site->deploys_last_30_days],
            updatedAt: $site->updated_at,
        )));

AiAssistant::resource('sites_domains')
    ->label('Domains')
    ->singular('Domain')
    ->pickable()
    ->guard($member)
    ->list(fn (ToolContext $ctx, array $filters) => Domain::query()
        ->where('organization_id', $ctx->accountId())
        ->when($filters['q'] ?? null, fn ($query, $q) => $query->where('hostname', 'like', "%{$q}%"))
        ->lazy()
        ->map(fn (Domain $domain) => [
            'id' => (string) $domain->id,
            'label' => $domain->hostname,
            'status' => $domain->certificate_expires_at?->isBefore(now()->addDays(14)) ? 'warning' : 'ok',
            'status_label' => $domain->certificate_expires_at ? 'Certificate until '.$domain->certificate_expires_at->toDateString() : null,
            'url' => route('domains.show', $domain),
            'parent' => 'sites_sites:'.$domain->site_id,
        ]));

AiAssistant::signals(function (ToolContext $ctx) use ($member): Signals {
    if (! $member($ctx)) {
        return Signals::make(); // share nothing
    }

    $organization = $ctx->user->organizations()->findOrFail($ctx->accountId());

    return Signals::make()
        ->lifecycle(Lifecycle::Active)
        ->plan($organization->plan_name)
        ->mrr($organization->mrr_minor, 'EUR')
        ->customerSince($organization->created_at)
        ->usage('sites', $organization->sites()->count(), $organization->site_limit)
        ->flag(...array_filter([
            $organization->sites()->where('is_down', true)->exists() ? SignalFlag::SiteDown : null,
            $organization->hasExpiringCertificates() ? SignalFlag::CertificateExpiring : null,
        ]))
        ->change('Deployed '.$organization->lastDeploy->branch, $organization->lastDeploy->finished_at, route('deploys.show', $organization->lastDeploy));
});
```

Use service-prefixed keys (`sites_domains`, `billing_invoices`) so profiles from several services never collide.

## Resources

`AiAssistant::resource(string $key): ResourceBuilder` registers a resource immediately. The key must match `[a-z][a-z0-9_]{0,40}`.

| Method | Meaning |
|---|---|
| `label(string $plural)` | Plural label shown to staff and customers. Defaults to the key. |
| `singular(string $singular)` | Singular label. Defaults to the plural label. |
| `pickable(bool $pickable = true)` | Assistant may offer the items as a choice on the customer's support request form. |
| `guard(Closure(ToolContext): bool)` | Must return exactly `true`. Without a guard the resource is never served. |
| `list(Closure(ToolContext, array $filters): iterable)` | Returns `ResourceItem` objects or arrays in the same shape. |

`$filters` holds the request's `filter[...]` values as strings (at most 200 characters each). Assistant sends `filter[q]` while a customer types in a pickable field; filter on it when you can. You may ignore it.

Return a lazy collection or a generator for large lists. The SDK reads at most 51 items: it serves 50 and reports `truncated: true`, without draining the rest.

### Items

| Field | Type | Rule |
|---|---|---|
| `id` | string | Required, at most 200 characters. Integers in arrays are accepted and cast. |
| `label` | string | Required, cut to 200 characters. |
| `status` | `ok`, `warning`, `error`, `inactive` or null | Any other value is dropped. |
| `status_label` | string or null | Cut to 120 characters. |
| `url` | HTTPS URL or null | Any other scheme is dropped. |
| `parent` | `resourcekey:id` or null | A malformed reference is dropped. |
| `attributes` | map of string to scalar or null | The first 8 are kept; strings are cut to 200 characters. |
| `metrics` | map of string to int or float | The first 8 numeric values are kept. |
| `updated_at` | date or null | Sent as ISO 8601. Arrays accept `updated_at` or `updatedAt`. |

An item without an id or label is a bug in the list closure. That resource is reported through `report()` and served as unavailable.

## Signals

`AiAssistant::signals(Closure(ToolContext): Signals|array)` contributes account-level facts. Signals have no separate guard: the closure runs as the principal and must check access itself. Return an empty `Signals::make()` to share nothing.

| Key | Fluent method | Rule |
|---|---|---|
| `lifecycle` | `lifecycle(Lifecycle\|string)` | `trial`, `active`, `past_due`, `canceling`, `canceled` |
| `plan` | `plan(string)` | Cut to 120 characters |
| `mrr` | `mrr(int $amountMinor, string $currency)` | `{amount_minor, currency}`; amount ≥ 0 in minor units, ISO 4217 currency (uppercased) |
| `customer_since` | `customerSince(DateTimeInterface\|string)` | Sent as `YYYY-MM-DD` |
| `seats` | `seats(int)` | ≥ 0 |
| `usage` | `usage(string $meter, int\|float $used, int\|float\|null $limit = null, ?string $unit = null)` | `{meter, used, limit, unit}`; at most 10 |
| `flags` | `flag(SignalFlag\|string ...$flags)` | `payment_failed`, `invoice_overdue`, `incident_open`, `site_down`, `certificate_expiring`, `deploy_failed`, `limit_reached`, `trial_ending`; duplicates ignored |
| `recent_changes` | `change(string $what, DateTimeInterface\|string $at, ?string $url = null)` | `{what, at, url}`; text cut to 120 characters, only the last 7 days, newest 10 served, HTTPS links only |
| `facts` | `fact(string $key, scalar $value)` | At most 10; strings cut to 200 characters |

`Signals::fromArray()` reads the same keys. Unknown keys and invalid values are dropped, not reported, so a slightly wrong signal never costs the profile.

Several closures are merged in registration order. A single value (lifecycle, plan, MRR, customer since, seats) from a later closure replaces an earlier one; usage, flags, changes and facts are combined and capped again. A closure that throws, or returns something other than `Signals` or an array, is reported and skipped.

## Endpoint

`GET /ai-assistant/profile` is signed and verified like discovery: platform EdDSA signature, audience, at most five minutes lifetime and the connector handshake. Because it is read-only it may omit `jti`; a present `jti` is still single-use.

| Query | Meaning |
|---|---|
| `resources=sites_sites,sites_domains` | Only these resources. Default: all. Unknown keys are ignored. |
| `filter[q]=shop` | Passed to every list closure. |

```json
{
  "resources": {
    "sites_domains": {
      "label": "Domains",
      "items": [
        {
          "id": "41",
          "label": "shop.acme.dk",
          "status": "warning",
          "status_label": "Certificate until 2026-11-20",
          "url": "https://sites.cbox.dk/domains/41",
          "parent": "sites_sites:7",
          "attributes": {},
          "metrics": {},
          "updated_at": null
        }
      ],
      "truncated": false
    },
    "sites_sites": { "label": "Sites", "error": "unavailable" }
  },
  "signals": {
    "lifecycle": "active",
    "plan": "Pro",
    "mrr": { "amount_minor": 4900, "currency": "EUR" },
    "flags": ["certificate_expiring"],
    "recent_changes": [
      { "what": "Deployed main", "at": "2026-11-09T09:12:00+00:00", "url": "https://sites.cbox.dk/deploys/88" }
    ]
  }
}
```

A resource whose guard denies is left out. A resource whose list throws is reported through `report()` and returned as `{"label": …, "error": "unavailable"}`; the others still return. The endpoint does not answer 500 because one resource fails. `signals` is `null` when the service registers no signals closure, and an object (possibly empty) when it does.

The signed manifest adds two keys; existing keys are unchanged:

```json
{
  "resources": [
    { "key": "sites_sites", "label": "Sites", "singular": "Site", "pickable": false },
    { "key": "sites_domains", "label": "Domains", "singular": "Domain", "pickable": true }
  ],
  "signals": true
}
```

## Support access elevation

By default staff see only what the customer sees: the profile request carries the customer as principal, and your closures answer for that customer.

A staff member can ask for more in Assistant by writing a reason. The access is time-limited and logged in Assistant. The signed principal then carries:

```json
"support_access": {
  "staff_id": "stf_12",
  "staff_name": "Ada Support",
  "reason": "Customer reports the domain stopped resolving after a DNS change",
  "until": "2026-11-09T12:30:00+00:00"
}
```

`$ctx->supportAccess()` returns a `SupportAccess` (`staffId`, `staffName`, `reason`, `until`) only when:

- `staff_id` and `staff_name` are present,
- `reason` has at least 10 characters after trimming,
- `until` (ISO 8601 or a Unix timestamp) is in the future and at most 60 minutes ahead.

Otherwise it returns null and the request is an ordinary customer request.

The SDK never widens anything by itself. An elevation is a verified claim; your guard or list closure decides whether it changes what is served, for example including archived sites:

```php
->list(fn (ToolContext $ctx, array $filters) => Site::query()
    ->where('organization_id', $ctx->accountId())
    ->when($ctx->supportAccess() === null, fn ($query) => $query->whereNull('archived_at'))
    ->lazy()
    ->map(...))
```

Whenever a profile request carries a valid elevation, the SDK dispatches `Cbox\AiAssistant\Connector\Events\SupportAccessUsed` with `access`, `surface` (`profile`), `keys` (the resources whose guard passed), `subject` and `accountId`. Signed tool calls and inbox components dispatch the same event after their guard passes, with `surface` `tool` or `inbox` and the tool or component key. Listen to it to keep your own record, so both sides log the elevation:

```php
Event::listen(SupportAccessUsed::class, fn (SupportAccessUsed $event) => AuditLog::record(
    'support.elevated_access',
    actor: $event->access->staffId,
    subject: $event->accountId,
    context: ['reason' => $event->access->reason, 'surface' => $event->surface, 'keys' => $event->keys],
));
```

## Testing

`InteractsWithAssistant` fetches the profile exactly as Assistant would:

```php
public function test_members_see_their_domains(): void
{
    $this->getAssistantProfile(['sub' => $user->external_id, 'account_id' => $org->id, 'verified' => true])
        ->assertOk()
        ->assertJsonPath('resources.sites_domains.items.0.label', 'shop.acme.dk');
}

public function test_other_organizations_see_nothing(): void
{
    $this->getAssistantProfile(['sub' => $user->external_id, 'account_id' => $otherOrg->id, 'verified' => true])
        ->assertOk()
        ->assertJsonMissingPath('resources.sites_domains');
}

public function test_elevated_access_is_logged(): void
{
    Event::fake([SupportAccessUsed::class]);

    $this->getAssistantProfile($principal, ['resources' => 'sites_domains'], $this->assistantSupportAccess());

    Event::assertDispatched(SupportAccessUsed::class);
}
```

`assistantSupportAccess(reason, minutes, staffId, staffName)` builds a valid thirty-minute claim by default; pass an empty reason or `minutes: 61` to test the rejections.
