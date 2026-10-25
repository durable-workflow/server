# October 23, Waterline service mode against native

- **What ran.** The published `durableworkflow/waterline:2.12.3` image ran in
  service mode, as `SERVICE_MODE.md` describes, with operator access. It was
  pointed first at native and then at published Server 2.11.1, both on
  SQLite.
- **Data.** A completed, a failed, a cancelled, a terminated and a waiting
  workflow; one that completed an activity; a schedule; and a standalone
  activity.
- **Calls.** Every Waterline read route was called, plus query, signal,
  repair and cancel through Waterline:
  - stats, health and capacity evidence;
  - the five flow lists;
  - instance and run detail, history export and the activity result;
  - the service catalog lists;
  - schedules, one schedule and its history.
- **Found.** Waterline's Services pages answered 501 against native and 200
  against PHP. The PHP SDK reads the catalog only when cluster info
  advertises `service_catalog_read_contract` version 1. Native served the
  eight catalog routes but did not advertise them. Native's cluster info now
  carries PHP's contract unchanged, and the pages load.
- **Result.** Every route now gets the same status from both servers:
  - Capacity evidence is unavailable on both. Published PHP does not expose
    that contract either.
  - A query with no polling worker is refused on both with
    `query_worker_unavailable`.
  - The other routes answer 200 on both.
- **Not covered.** This checks Waterline's JSON routes, not its rendered
  pages. Response bodies differ in fields tracked elsewhere: timestamp
  precision, identifier case and several describe fields.
