export const CURRENT_WORKER_PROTOCOL_VERSION = '1.19';

const PORTABLE_CAPABILITIES = ['local_activities', 'worker_sessions', 'sticky_execution'];
const PORTABLE_CAPABILITY_MINIMUM_PROTOCOL_VERSION = '1.18';
const UNSUPPORTED_REASON = 'not_implemented_by_direct_conformance_probe';

export function currentWorkerProtocolHeaders(headers = {}) {
  return {
    ...headers,
    'X-Durable-Workflow-Protocol-Version': CURRENT_WORKER_PROTOCOL_VERSION,
  };
}

export function currentWorkerRegistration(payload) {
  const workflowTypes = declarationList(payload?.supported_workflow_types, 'supported_workflow_types');
  const activityTypes = declarationList(payload?.supported_activity_types, 'supported_activity_types');
  const capabilities = declarationList(payload?.capabilities ?? [], 'capabilities');
  for (const field of ['worker_id', 'task_queue', 'runtime', 'sdk_version']) {
    nonEmptyString(payload?.[field], field);
  }

  const registration = {
    max_concurrent_workflow_tasks: workflowTypes.length > 0 ? 10 : 0,
    max_concurrent_activity_tasks: activityTypes.length > 0 ? 10 : 0,
    task_slots: {
      workflow_available: workflowTypes.length > 0 ? 10 : 0,
      activity_available: activityTypes.length > 0 ? 10 : 0,
      session_available: 0,
    },
    ...payload,
    supported_workflow_types: workflowTypes,
    supported_activity_types: activityTypes,
    capabilities,
    capability_manifest: unsupportedCapabilityManifest(),
  };

  assertCurrentWorkerRegistration(registration);
  return registration;
}

export function assertCurrentWorkerRegistration(payload) {
  for (const field of ['worker_id', 'task_queue', 'runtime', 'sdk_version']) {
    nonEmptyString(payload?.[field], field);
  }
  for (const field of ['supported_workflow_types', 'supported_activity_types', 'capabilities']) {
    declarationList(payload?.[field], field);
  }

  const manifest = payload?.capability_manifest;
  if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
    throw new Error('direct conformance registration requires capability_manifest');
  }
  for (const capability of PORTABLE_CAPABILITIES) {
    const entry = manifest[capability];
    if (!entry || typeof entry !== 'object' || typeof entry.supported !== 'boolean') {
      throw new Error(`direct conformance capability_manifest.${capability}.supported must be boolean`);
    }
    if (entry.minimum_protocol_version !== PORTABLE_CAPABILITY_MINIMUM_PROTOCOL_VERSION) {
      throw new Error(`direct conformance capability_manifest.${capability} has a stale minimum protocol version`);
    }
    const explanation = entry.supported ? entry.implementation : entry.reason;
    nonEmptyString(explanation, `capability_manifest.${capability}.explanation`);
  }
}

export function workflowTaskCompletionPayload(task, commands) {
  const normalized = taskFence(task);
  if (!Array.isArray(commands) || commands.length === 0) {
    throw new Error('direct conformance workflow completion requires commands');
  }
  commands.forEach((command, index) => assertCommandPayloads(command, index));

  return { ...normalized, commands };
}

export function workflowTaskFailurePayload(task, message, type) {
  const normalized = taskFence(task);
  nonEmptyString(message, 'failure.message');
  nonEmptyString(type, 'failure.type');

  return {
    ...normalized,
    failure: { message, type },
  };
}

export function avroResultFromTaskArguments(task) {
  const argumentsPayload = task?.arguments;
  if (isAvroEnvelope(argumentsPayload)) {
    return argumentsPayload;
  }
  if (typeof argumentsPayload === 'string' && argumentsPayload !== '') {
    assertNotJsonShaped(argumentsPayload, 'task.arguments');
    if (task?.payload_codec !== 'avro') {
      throw new Error('direct conformance task.arguments did not declare payload_codec=avro');
    }
    return argumentsPayload;
  }

  throw new Error('direct conformance task.arguments did not contain an official Avro payload');
}

function unsupportedCapabilityManifest() {
  return Object.fromEntries(PORTABLE_CAPABILITIES.map((capability) => [capability, {
    supported: false,
    minimum_protocol_version: PORTABLE_CAPABILITY_MINIMUM_PROTOCOL_VERSION,
    reason: UNSUPPORTED_REASON,
  }]));
}

function taskFence(task) {
  nonEmptyString(task?.task_id, 'task.task_id');
  nonEmptyString(task?.lease_owner, 'task.lease_owner');
  if (!Number.isInteger(task?.workflow_task_attempt) || task.workflow_task_attempt < 1) {
    throw new Error('direct conformance task.workflow_task_attempt must be a positive integer');
  }

  return {
    lease_owner: task.lease_owner,
    workflow_task_attempt: task.workflow_task_attempt,
  };
}

function assertCommandPayloads(command, index) {
  if (!command || typeof command !== 'object' || Array.isArray(command)) {
    throw new Error(`direct conformance commands.${index} must be an object`);
  }
  nonEmptyString(command.type, `commands.${index}.type`);

  const payloadFields = {
    complete_workflow: ['result'],
    complete_update: ['result'],
    record_side_effect: ['result'],
    continue_as_new: ['arguments'],
    schedule_activity: ['arguments'],
    start_child_workflow: ['arguments'],
  }[command.type] ?? [];
  for (const field of payloadFields) {
    if (Object.hasOwn(command, field)) {
      assertAvroPayload(command[field], `commands.${index}.${field}`);
    }
  }
  if (command.exception && Object.hasOwn(command.exception, 'details')) {
    assertAvroPayload(command.exception.details, `commands.${index}.exception.details`);
  }
}

function assertAvroPayload(payload, field) {
  if (isAvroEnvelope(payload)) {
    assertNotJsonShaped(payload.blob, `${field}.blob`);
    return;
  }
  if (typeof payload === 'string' && payload !== '') {
    assertNotJsonShaped(payload, field);
    return;
  }
  throw new Error(`direct conformance ${field} must contain an Avro Value payload`);
}

function isAvroEnvelope(value) {
  return value
    && typeof value === 'object'
    && !Array.isArray(value)
    && value.codec === 'avro'
    && typeof value.blob === 'string'
    && value.blob !== '';
}

function assertNotJsonShaped(value, field) {
  const trimmed = value.trim();
  let jsonShaped = /^[\[{\"]/.test(trimmed);
  if (!jsonShaped) {
    try {
      JSON.parse(trimmed);
      jsonShaped = true;
    } catch {
      jsonShaped = false;
    }
  }
  if (jsonShaped) {
    throw new Error(`direct conformance ${field} contains a JSON-shaped payload instead of Avro`);
  }
}

function declarationList(value, field) {
  if (!Array.isArray(value)) {
    throw new Error(`direct conformance ${field} must be a list`);
  }
  value.forEach((entry) => nonEmptyString(entry, field));
  if (new Set(value).size !== value.length) {
    throw new Error(`direct conformance ${field} must not contain duplicates`);
  }
  return [...value];
}

function nonEmptyString(value, field) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`direct conformance ${field} must be a non-empty string`);
  }
}
