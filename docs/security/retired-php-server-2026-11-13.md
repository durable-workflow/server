# Retired PHP Server security notice, 13 November 2026

PHP Server 2.11.8 is retired and unpatched for the Laravel advisories below.
Its published image contains Laravel framework 13.34.0. No new PHP Server
release is planned. Existing PHP version tags and comparison images remain
unchanged. Updating a source checkout's Composer dependencies does not patch
an installed image.

The supported native Server uses a Rust runtime and is outside this Laravel
dependency finding. Use the [native deployment guidance](../server-reference.md)
and a qualified [published native release](https://github.com/durable-workflow/server/releases/latest)
for new installations.

## Affected dependency and conditions

The upstream fixes covering these advisories are Laravel 12.72.0 and 13.41.0
or later in their respective release lines:

| Advisory | Reviewed PHP Server exposure |
| --- | --- |
| [Task-output injection, GHSA-63hm-g96r-2m2q](https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q) | The dependency is affected. No shipped application call to Laravel `Concurrency::run()` was found. The advisory requires that call path and a task that writes attacker-controlled output. |
| [Numeric-parameter signed-URL injection, GHSA-6rv8-qj5v-p8ch](https://github.com/laravel/framework/security/advisories/GHSA-6rv8-qj5v-p8ch) | The dependency is affected. No shipped signed-URL generation path was found. The advisory requires application code to pass attacker-influenced numeric parameters into signed-URL generation. |
| [Temporary signed-URL expiration bypass, GHSA-v5ch-g375-82r6](https://github.com/laravel/framework/security/advisories/GHSA-v5ch-g375-82r6) | The dependency is affected and the framework's signed storage routes are present. Exposure is conditional on obtaining or issuing a valid temporary signed URL and the advisory's request-parsing conditions. |

The frozen configuration enables local-disk serving. Laravel therefore
registers signed `GET /storage/{path}` and `PUT /storage/{path}` routes even
though the shipped application does not issue their temporary signed URLs.
Operator extensions that issued those links use the affected verification
path. This assessment does not demonstrate an exploitable default Server
journey. Review custom routes and extensions separately.

## Operator action

Stop exposing the retired PHP service and plan migration to the supported
native runtime. Until the PHP service is retired:

1. Block all `/storage/*` requests at ingress, including both GET and PUT.
   Verify the rule on the actual HTTP endpoint. These framework routes are
   separate from the authenticated `/api/external-payloads/v1` transport.
2. Stop issuing and reusing local-disk temporary download or upload URLs.
   Review extensions that generate signed routes or call Laravel Concurrency.
3. Preserve a restorable backup of the database and external-payload storage.
   Keep every PHP role stopped during any migration that writes the database.

Blocking these routes reduces the identified storage-route exposure. It does
not install the upstream fixes or establish that custom extensions are safe.

An existing PHP database must not be opened by native startup as an assumed
conversion. The [backup-first takeover runbook](../rust-server-upgrade-takeover.md)
states the exact layouts, refused state, backend/version limits and historical
published-image evidence. Rehearse only the applicable procedure on a restored
copy and preserve the original backup. That runbook's published takeover
evidence is not a new qualification of PHP-to-native conversion by the latest
native release. Subsequent native schema upgrades have their own
[stop, backup, explicit upgrade and restore requirements](../server-reference.md).

See [Server #1214](https://github.com/durable-workflow/server/issues/1214) for
the exact published-artifact inventory, independent exposure assessment and
retirement decision. The PHP publisher remains disabled.
