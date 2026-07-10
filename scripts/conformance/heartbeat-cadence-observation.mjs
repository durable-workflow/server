function validTimestamp(value) {
  return typeof value === 'string' && Number.isFinite(Date.parse(value));
}

function uniqueTimestamps(values) {
  return values.filter((value, index) => validTimestamp(value) && values.indexOf(value) === index);
}

function intervalsBetween(timestamps) {
  const intervals = [];
  for (let index = 1; index < timestamps.length; index += 1) {
    intervals.push((Date.parse(timestamps[index]) - Date.parse(timestamps[index - 1])) / 1_000);
  }
  return intervals;
}

export function heartbeatCadenceObservation({
  cell,
  heartbeatRecords,
  serverHeartbeatTimestamps,
  advertisedSeconds,
}) {
  const acknowledgements = heartbeatRecords
    .filter((record) => Object.hasOwn(record, 'acknowledgement'))
    .map((record) => record.acknowledgement);
  const workerLogObservationTimestamps = uniqueTimestamps(
    heartbeatRecords.map((record) => record.observed_at),
  );
  const acknowledgementLogTimestamps = uniqueTimestamps(
    heartbeatRecords.map((record) => record.acknowledgement_logged_at),
  );
  const serverTimestamps = uniqueTimestamps(serverHeartbeatTimestamps);
  const usesServerReceiptTimestamps = cell === 'php';
  const cadenceTimestamps = usesServerReceiptTimestamps
    ? serverTimestamps
    : workerLogObservationTimestamps;
  const intervals = intervalsBetween(cadenceTimestamps);
  const bounded = intervals.length > 0 && intervals.every((interval) =>
    interval >= Math.max(0.5, advertisedSeconds * 0.5)
      && interval <= Math.max(advertisedSeconds * 2, advertisedSeconds + 2));

  return {
    advertised_heartbeat_interval_seconds: advertisedSeconds,
    cadence_observation_source: usesServerReceiptTimestamps
      ? 'server_persisted_last_heartbeat_at'
      : 'sdk_native_acknowledgement_timestamp',
    cadence_heartbeat_timestamps: cadenceTimestamps,
    sdk_emitted_heartbeat_timestamps: cadenceTimestamps,
    sdk_native_heartbeat_timestamps: usesServerReceiptTimestamps ? [] : workerLogObservationTimestamps,
    worker_log_observation_timestamps: workerLogObservationTimestamps,
    acknowledgement_log_timestamps: acknowledgementLogTimestamps,
    server_last_heartbeat_timestamps: serverTimestamps,
    inter_arrival_seconds: intervals,
    bounded_advertised_cadence: bounded,
    sdk_heartbeat_acknowledgement_count: acknowledgements.length,
    acknowledgements,
  };
}
