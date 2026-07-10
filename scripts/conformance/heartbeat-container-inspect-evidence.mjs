const SERVER_CONTAINER_IMAGE_INSPECT_FORMAT =
  '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}}}}';

function parseInspectObject(stdout) {
  try {
    const parsed = JSON.parse(String(stdout ?? '').trim());
    const inspected = Array.isArray(parsed) ? parsed[0] : parsed;
    return inspected && typeof inspected === 'object' ? inspected : null;
  } catch {
    return null;
  }
}

function publishedServerImageId(value) {
  const normalized = String(value ?? '');
  return /^sha256:[0-9a-f]{64}$/i.test(normalized) ? normalized : null;
}

function publishedServerImageReference(value) {
  const normalized = String(value ?? '');
  const publicTag = /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server:\d+\.\d+\.\d+$/;
  const publicDigest = /^(?:(?:docker\.io|index\.docker\.io)\/)?durableworkflow\/server(?::[^@]+)?@sha256:[0-9a-f]{64}$/i;
  return publicTag.test(normalized) || publicDigest.test(normalized) ? normalized : null;
}

/**
 * Persist only the two container-inspect fields used to verify the published
 * image. In particular, never retain Config.Env or raw command output because
 * runtime environment values can contain caller-supplied credentials.
 */
function safeContainerInspectCommandRecord(record) {
  const inspected = parseInspectObject(record?.stdout);
  const imageId = publishedServerImageId(inspected?.Image);
  const configuredReference = publishedServerImageReference(inspected?.Config?.Image);

  return {
    operation: 'docker container inspect image verification',
    status: Number.isInteger(record?.status) ? record.status : null,
    signal: /^SIG[A-Z0-9]+$/.test(String(record?.signal ?? '')) ? record.signal : null,
    raw_output_omitted: true,
    inspection: imageId || configuredReference
      ? {
          Image: imageId,
          Config: { Image: configuredReference },
        }
      : null,
  };
}

export {
  SERVER_CONTAINER_IMAGE_INSPECT_FORMAT,
  safeContainerInspectCommandRecord,
};
