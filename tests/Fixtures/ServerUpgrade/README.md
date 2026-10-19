# PHP upgrade source fixtures

These small fixtures exercise recognition of stored PHP data. They do not
authorize migration or claim that pending work resumes under Rust.

`php-2.5.20-external-references.json` was generated with normally installed
Workflow 2.5.20 classes. The recorded Composer reference is the image's path
package reference, distinct from the package's published tag. All three loaded
class hashes match the published 2.5.20 and 2.5.21 source. The two locations are
fictional. This is a genuine serializer output, without a provider object,
payload fetch or end-to-end upload claim.

To regenerate its stored strings with a normal package installation, run this
PHP through that installation's autoloader:

```php
<?php
require 'vendor/autoload.php';

use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

foreach ([
    ['s3://migration-fixture/result/%CE%BB?versionId=original-17', 'fixture-object'],
    ['file:///migration-fixture/日本語/empty', ''],
] as [$uri, $bytes]) {
    $reference = new ExternalPayloadReference(
        $uri, hash('sha256', $bytes), strlen($bytes), 'avro',
    );
    $envelope = ['codec' => 'avro', 'external_storage' => $reference->toArray()];
    $stored = ExternalPayloads::encodeStoredEnvelope($envelope);
    if (ExternalPayloads::storedEnvelope($stored) != $envelope) {
        throw new RuntimeException('Reference round trip failed.');
    }
    echo $stored, PHP_EOL;
}
```

The Rust reader tests distinguish this prefix from inline Avro and the native
opaque-reference prefix. Duplicate/unknown fields and unsupported versions
refuse. The reader returns metadata only, keeps provider locations out of
inspection reports, and is not yet wired to source-row conversion or takeover.

The optional `upgrade-payload-inspect` command applies that reader to the four
root run/activity columns listed in the storage audit. Use the normal inspection
database configuration and a SELECT-only role; SQLite still requires the supported
DELETE-journal source. `scripts/conformance/server-parity/upgrade-payload-source.php`
provides `seed`, `unsupported`, `malformed`, `oversized` and `restore` controls for
a new disposable PHP-bootstrapped schema. These are constructed rows using the
installed released serializer, not executed workflows. No provider object exists.
Compare source fingerprints before and after each inspection. An unqualified
metadata report must exit nonzero and identify its affected row without exposing
the stored reference. A successful metadata inspection still permits no takeover.
