#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';

const EXPECTED_SCHEMA = 'durable-workflow.v2.platform-protocol-specs.catalog';
const EXPECTED_CATALOG_URL = 'https://durable-workflow.github.io/platform-protocol-specs.json';
const EXPECTED_AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-protocol-specs';
const EXPECTED_SPEC_ORIGIN = 'https://durable-workflow.github.io';
const EXPECTED_SPEC_PATH_PREFIX = '/platform-protocol-specs/';
const MAX_FINDINGS = 100;

const REQUIRED_TOP_LEVEL_FIELDS = [
  'schema',
  'version',
  'catalog_url',
  'authority_url',
  'formats',
  'owner_repos',
  'status_levels',
  'evolution_rules',
  'specs',
  'release_check',
];
const REQUIRED_SPEC_FIELDS = [
  'description',
  'format',
  'spec_id',
  'surface_family',
  'authority_manifest',
  'owner_repo',
  'object_families',
  'evolution_rule',
  'breaking_change_release',
  'status',
];
const FORBIDDEN_AUTHORITY_FIELDS = new Set([
  'spec_path',
  'owner_symbol',
  'implementation_symbol',
  'source_path',
  'test_path',
  'test_paths',
  'conformance_test',
  'conformance_path',
  'conformance_script',
  'schema_authority',
  'version_authority',
]);
const REPOSITORY_LOCAL_PATH = /(^|[\s`"'(])(?:\.\.?\/)?(?:app|config|docs|resources|routes|scripts|src|static|tests)\//;
const SOURCE_TREE_FILE_NAME = /\.(?:js|mjs|php|py|rs|ts)\b/i;

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sha256(source) {
  return crypto.createHash('sha256').update(source).digest('hex');
}

function readJson(path, label) {
  let source;

  try {
    source = fs.readFileSync(path, 'utf8');
  } catch (error) {
    throw new Error(`${label} could not be read from ${path}: ${error.message}`);
  }

  try {
    return {source, value: JSON.parse(source)};
  } catch (error) {
    throw new Error(`${label} is not valid JSON: ${error.message}`);
  }
}

function printable(value) {
  if (value === undefined) {
    return '<missing>';
  }

  const encoded = JSON.stringify(value);
  return encoded.length > 240 ? `${encoded.slice(0, 237)}...` : encoded;
}

function addFinding(findings, finding) {
  if (findings.length < MAX_FINDINGS) {
    findings.push(finding);
  }
}

function validateConsumerSafeCatalog(catalog, surface, findings) {
  if (!isRecord(catalog)) {
    addFinding(findings, {
      kind: 'invalid_catalog',
      surface,
      path: '$',
      message: `${surface} catalog must be a JSON object.`,
    });
    return;
  }

  for (const field of REQUIRED_TOP_LEVEL_FIELDS) {
    if (!(field in catalog)) {
      addFinding(findings, {
        kind: 'missing_required_field',
        surface,
        path: `$.${field}`,
        message: `${surface} catalog is missing required field $.${field}.`,
      });
    }
  }

  for (const [path, expected, actual] of [
    ['$.schema', EXPECTED_SCHEMA, catalog.schema],
    ['$.catalog_url', EXPECTED_CATALOG_URL, catalog.catalog_url],
    ['$.authority_url', EXPECTED_AUTHORITY_URL, catalog.authority_url],
  ]) {
    if (actual !== expected) {
      addFinding(findings, {
        kind: 'identity_mismatch',
        surface,
        path,
        expected,
        actual,
        message: `${surface} catalog ${path} expected ${printable(expected)}, got ${printable(actual)}.`,
      });
    }
  }

  if (!Number.isInteger(catalog.version) || catalog.version < 1) {
    addFinding(findings, {
      kind: 'invalid_catalog_version',
      surface,
      path: '$.version',
      expected: 'positive integer',
      actual: catalog.version,
      message: `${surface} catalog $.version must be a positive integer, got ${printable(catalog.version)}.`,
    });
  }

  if (!isRecord(catalog.specs) || Object.keys(catalog.specs).length === 0) {
    addFinding(findings, {
      kind: 'missing_capability_records',
      surface,
      path: '$.specs',
      message: `${surface} catalog $.specs must contain capability records.`,
    });
  } else {
    for (const [name, spec] of Object.entries(catalog.specs)) {
      const specPath = `$.specs.${name}`;
      if (!isRecord(spec)) {
        addFinding(findings, {
          kind: 'invalid_capability_record',
          surface,
          path: specPath,
          message: `${surface} catalog capability ${specPath} must be an object.`,
        });
        continue;
      }

      for (const field of REQUIRED_SPEC_FIELDS) {
        if (!(field in spec)) {
          addFinding(findings, {
            kind: 'missing_required_field',
            surface,
            path: `${specPath}.${field}`,
            message: `${surface} catalog capability ${specPath} is missing field ${field}.`,
          });
        }
      }

      if (!Array.isArray(spec.object_families) || spec.object_families.length === 0) {
        addFinding(findings, {
          kind: 'missing_object_families',
          surface,
          path: `${specPath}.object_families`,
          message: `${surface} catalog capability ${specPath} must declare object families.`,
        });
      } else {
        spec.object_families.forEach((family, index) => {
          const familyPath = `${specPath}.object_families[${index}]`;
          const fields = isRecord(family) ? Object.keys(family).sort() : [];
          if (!isRecord(family) || JSON.stringify(fields) !== JSON.stringify(['name', 'owner_repo'])) {
            addFinding(findings, {
              kind: 'object_family_field_set_mismatch',
              surface,
              path: familyPath,
              expected_fields: ['name', 'owner_repo'],
              actual_fields: fields,
              message: `${surface} catalog ${familyPath} must expose only name and owner_repo; got [${fields.join(', ')}].`,
            });
          }
        });
      }

      if (spec.status === 'planned') {
        if ('spec_url' in spec) {
          addFinding(findings, {
            kind: 'unexpected_public_spec_reference',
            surface,
            path: `${specPath}.spec_url`,
            actual: spec.spec_url,
            message: `${surface} planned capability ${specPath} must not advertise spec_url.`,
          });
        }
      } else {
        let specUrl;
        try {
          specUrl = new URL(spec.spec_url);
        } catch {
          specUrl = null;
        }

        if (
          specUrl === null
          || specUrl.origin !== EXPECTED_SPEC_ORIGIN
          || !specUrl.pathname.startsWith(EXPECTED_SPEC_PATH_PREFIX)
          || specUrl.search !== ''
          || specUrl.hash !== ''
        ) {
          addFinding(findings, {
            kind: 'invalid_public_spec_reference',
            surface,
            path: `${specPath}.spec_url`,
            expected: `${EXPECTED_SPEC_ORIGIN}${EXPECTED_SPEC_PATH_PREFIX}*`,
            actual: spec.spec_url,
            message: `${surface} catalog ${specPath}.spec_url must be a direct public specification URL; got ${printable(spec.spec_url)}.`,
          });
        }
      }
    }
  }

  walkCatalog(catalog, '$', (value, path, key) => {
    if (FORBIDDEN_AUTHORITY_FIELDS.has(key)) {
      addFinding(findings, {
        kind: 'repository_local_authority_field',
        surface,
        path,
        message: `${surface} catalog exposes forbidden repository-local authority field ${path}.`,
      });
    }

    if (
      typeof value === 'string'
      && (
        REPOSITORY_LOCAL_PATH.test(value)
        || SOURCE_TREE_FILE_NAME.test(value)
        || value.includes('::')
        || value.includes('\\')
      )
    ) {
      addFinding(findings, {
        kind: 'repository_local_authority_value',
        surface,
        path,
        actual: value,
        message: `${surface} catalog exposes a repository-local path or implementation symbol at ${path}: ${printable(value)}.`,
      });
    }
  });
}

function walkCatalog(value, path, visit, key = '') {
  visit(value, path, key);

  if (Array.isArray(value)) {
    value.forEach((entry, index) => walkCatalog(entry, `${path}[${index}]`, visit, String(index)));
    return;
  }

  if (isRecord(value)) {
    for (const [childKey, child] of Object.entries(value)) {
      walkCatalog(child, `${path}.${childKey}`, visit, childKey);
    }
  }
}

function compareCatalogs(expected, actual, path, findings) {
  if (Array.isArray(expected) || Array.isArray(actual)) {
    if (!Array.isArray(expected) || !Array.isArray(actual)) {
      addFinding(findings, {
        kind: 'type_mismatch',
        path,
        public_value: expected,
        server_value: actual,
        message: `Catalog drift at ${path}: public type and server type differ.`,
      });
      return;
    }

    if (expected.length !== actual.length) {
      addFinding(findings, {
        kind: 'array_length_mismatch',
        path,
        public_length: expected.length,
        server_length: actual.length,
        message: `Catalog drift at ${path}: public length ${expected.length}, server length ${actual.length}.`,
      });
    }

    for (let index = 0; index < Math.min(expected.length, actual.length); index += 1) {
      compareCatalogs(expected[index], actual[index], `${path}[${index}]`, findings);
    }
    return;
  }

  if (isRecord(expected) || isRecord(actual)) {
    if (!isRecord(expected) || !isRecord(actual)) {
      addFinding(findings, {
        kind: 'type_mismatch',
        path,
        public_value: expected,
        server_value: actual,
        message: `Catalog drift at ${path}: public type and server type differ.`,
      });
      return;
    }

    const publicFields = Object.keys(expected).sort();
    const serverFields = Object.keys(actual).sort();
    const missing = publicFields.filter(field => !(field in actual));
    const unexpected = serverFields.filter(field => !(field in expected));
    if (missing.length > 0 || unexpected.length > 0) {
      addFinding(findings, {
        kind: 'field_set_mismatch',
        path,
        missing_server_fields: missing,
        unexpected_server_fields: unexpected,
        public_fields: publicFields,
        server_fields: serverFields,
        message: `Catalog field set drift at ${path}: missing on server [${missing.join(', ')}], unexpected on server [${unexpected.join(', ')}].`,
      });
    }

    for (const field of publicFields.filter(field => field in actual)) {
      compareCatalogs(expected[field], actual[field], `${path}.${field}`, findings);
    }
    return;
  }

  if (expected !== actual) {
    addFinding(findings, {
      kind: 'value_mismatch',
      path,
      public_value: expected,
      server_value: actual,
      message: `Catalog drift at ${path}: public ${printable(expected)}, server ${printable(actual)}.`,
    });
  }
}

function writeOutput(name, value) {
  if (process.env.GITHUB_OUTPUT) {
    fs.appendFileSync(process.env.GITHUB_OUTPUT, `${name}=${value}\n`);
  }
}

function main() {
  const serverDiscoveryPath = process.env.SERVER_DISCOVERY_PATH;
  const publicCatalogPath = process.env.PUBLIC_CATALOG_PATH;
  const evidencePath = process.env.PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE;
  const expectedWorkflowRef = process.env.WORKFLOW_PACKAGE_REF;
  const expectedWorkflowCommit = process.env.WORKFLOW_PACKAGE_COMMIT;
  const expectedWorkflowSource = process.env.WORKFLOW_PACKAGE_SOURCE;

  if (!serverDiscoveryPath || !publicCatalogPath || !evidencePath) {
    throw new Error('SERVER_DISCOVERY_PATH, PUBLIC_CATALOG_PATH, and PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE are required.');
  }
  if (!expectedWorkflowSource || !expectedWorkflowRef || !expectedWorkflowCommit) {
    throw new Error('WORKFLOW_PACKAGE_SOURCE, WORKFLOW_PACKAGE_REF, and WORKFLOW_PACKAGE_COMMIT are required.');
  }

  const serverDocument = readJson(serverDiscoveryPath, 'server discovery response');
  const publicDocument = readJson(publicCatalogPath, 'public protocol catalog');
  const discovery = serverDocument.value;
  const publicCatalog = publicDocument.value;
  const serverCatalog = isRecord(discovery) ? discovery.platform_protocol_specs : undefined;
  const provenance = isRecord(discovery) ? discovery.package_provenance : undefined;
  const findings = [];

  validateConsumerSafeCatalog(publicCatalog, 'public', findings);
  validateConsumerSafeCatalog(serverCatalog, 'server', findings);

  if (isRecord(publicCatalog) && isRecord(serverCatalog)) {
    compareCatalogs(publicCatalog, serverCatalog, '$', findings);
  }

  if (!isRecord(provenance)) {
    addFinding(findings, {
      kind: 'missing_workflow_package_provenance',
      path: '$.package_provenance',
      message: 'Server discovery must expose Workflow package provenance during release conformance.',
    });
  } else {
    for (const [field, expected] of Object.entries({
      source: expectedWorkflowSource,
      ref: expectedWorkflowRef,
      commit: expectedWorkflowCommit,
    })) {
      if (provenance[field] !== expected) {
        addFinding(findings, {
          kind: 'workflow_package_provenance_mismatch',
          path: `$.package_provenance.${field}`,
          expected,
          actual: provenance[field],
          message: `Workflow package provenance ${field} expected ${printable(expected)}, got ${printable(provenance[field])}.`,
        });
      }
    }
  }

  const evidence = {
    schema: 'durable-workflow.server.release-protocol-catalog-conformance',
    schema_version: 1,
    checked_at: new Date().toISOString(),
    release_tag: process.env.RELEASE_TAG || null,
    server_image: process.env.SERVER_IMAGE || null,
    public_catalog_url: process.env.PUBLIC_CATALOG_URL || EXPECTED_CATALOG_URL,
    expected_workflow_package: {
      name: 'durable-workflow/workflow',
      source: expectedWorkflowSource,
      version: expectedWorkflowRef,
      commit: expectedWorkflowCommit,
    },
    lifecycle: process.env.PROTOCOL_CATALOG_BOOTSTRAP_OUTCOME ? {
      storage: process.env.PROTOCOL_CATALOG_STORAGE_KIND || null,
      bootstrap: process.env.PROTOCOL_CATALOG_BOOTSTRAP_OUTCOME,
      discovery: process.env.PROTOCOL_CATALOG_DISCOVERY_OUTCOME || null,
    } : undefined,
    diagnostics: process.env.PROTOCOL_CATALOG_BOOTSTRAP_LOG || process.env.PROTOCOL_CATALOG_SERVER_LOG ? {
      bootstrap_log: process.env.PROTOCOL_CATALOG_BOOTSTRAP_LOG || null,
      server_log: process.env.PROTOCOL_CATALOG_SERVER_LOG || null,
    } : undefined,
    observations: {
      public_catalog: isRecord(publicCatalog) ? {
        schema: publicCatalog.schema ?? null,
        version: publicCatalog.version ?? null,
        sha256: sha256(JSON.stringify(publicCatalog)),
        source_sha256: sha256(publicDocument.source),
        capability_records: isRecord(publicCatalog.specs) ? Object.keys(publicCatalog.specs).length : 0,
      } : null,
      server_catalog: isRecord(serverCatalog) ? {
        schema: serverCatalog.schema ?? null,
        version: serverCatalog.version ?? null,
        sha256: sha256(JSON.stringify(serverCatalog)),
        capability_records: isRecord(serverCatalog.specs) ? Object.keys(serverCatalog.specs).length : 0,
      } : null,
      package_provenance: isRecord(provenance) ? provenance : null,
    },
    outcome: findings.length === 0 ? 'pass' : 'fail',
    findings,
  };

  fs.writeFileSync(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`);

  if (findings.length > 0) {
    writeOutput('protocol_catalog_conformance_outcome', 'failure');
    const summary = findings.slice(0, 20).map(finding => `- ${finding.message}`).join('\n');
    process.stderr.write(`Protocol catalog conformance failed with ${findings.length} finding(s):\n${summary}\n`);
    process.exitCode = 1;
    return;
  }

  writeOutput('protocol_catalog_conformance_outcome', 'success');
  writeOutput('protocol_catalog_version', String(publicCatalog.version));
  console.log(
    `Verified published server discovery and public protocol catalog independently: `
      + `schema ${publicCatalog.schema}, version ${publicCatalog.version}, `
      + `${Object.keys(publicCatalog.specs).length} capability records, Workflow ${expectedWorkflowRef} at ${expectedWorkflowCommit}.`,
  );
}

try {
  main();
} catch (error) {
  writeOutput('protocol_catalog_conformance_outcome', 'failure');
  if (process.env.PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE) {
    fs.writeFileSync(
      process.env.PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE,
      `${JSON.stringify({
        schema: 'durable-workflow.server.release-protocol-catalog-conformance',
        schema_version: 1,
        checked_at: new Date().toISOString(),
        release_tag: process.env.RELEASE_TAG || null,
        server_image: process.env.SERVER_IMAGE || null,
        public_catalog_url: process.env.PUBLIC_CATALOG_URL || EXPECTED_CATALOG_URL,
        expected_workflow_package: {
          name: 'durable-workflow/workflow',
          source: process.env.WORKFLOW_PACKAGE_SOURCE || null,
          version: process.env.WORKFLOW_PACKAGE_REF || null,
          commit: process.env.WORKFLOW_PACKAGE_COMMIT || null,
        },
        lifecycle: process.env.PROTOCOL_CATALOG_BOOTSTRAP_OUTCOME ? {
          storage: process.env.PROTOCOL_CATALOG_STORAGE_KIND || null,
          bootstrap: process.env.PROTOCOL_CATALOG_BOOTSTRAP_OUTCOME,
          discovery: process.env.PROTOCOL_CATALOG_DISCOVERY_OUTCOME || null,
        } : undefined,
        diagnostics: process.env.PROTOCOL_CATALOG_BOOTSTRAP_LOG || process.env.PROTOCOL_CATALOG_SERVER_LOG ? {
          bootstrap_log: process.env.PROTOCOL_CATALOG_BOOTSTRAP_LOG || null,
          server_log: process.env.PROTOCOL_CATALOG_SERVER_LOG || null,
        } : undefined,
        outcome: 'fail',
        findings: [{kind: 'runner_failure', message: error.message}],
      }, null, 2)}\n`,
    );
  }
  process.stderr.write(`Protocol catalog conformance could not run: ${error.message}\n`);
  process.exitCode = 1;
}
