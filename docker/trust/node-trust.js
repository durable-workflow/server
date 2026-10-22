// Refuses a Node runtime below the fixed 22.23.4 release, or one whose
// embedded root store still trusts a root withdrawn under GHSA-6q48-6c7w-9gw2.
// Node uses its embedded roots by default, independent of the OS store.
'use strict';
const tls = require('node:tls');
const { X509Certificate } = require('node:crypto');

const blocked = { 'AAA Certificate Services': 0, 'emSign ECC Root CA - G3': 0 };
for (const pem of tls.rootCertificates) {
    const name = /(?:^|\n)CN=([^\n]+)/.exec(new X509Certificate(pem).subject)?.[1];
    if (name in blocked) {
        blocked[name]++;
    }
}
const [major, minor, patch] = process.versions.node.split('.').map(Number);
const fixed = major === 22 && (minor > 23 || (minor === 23 && patch >= 4));
// Extra roots would join the embedded ones; report only whether any are set.
const extraRoots = process.env.NODE_EXTRA_CA_CERTS !== undefined;
console.log(JSON.stringify({ node: process.versions.node, roots: tls.rootCertificates.length, blocked, extra_ca_certs_set: extraRoots }));
process.exit(fixed && !extraRoots && Object.values(blocked).every((count) => count === 0) ? 0 : 1);
