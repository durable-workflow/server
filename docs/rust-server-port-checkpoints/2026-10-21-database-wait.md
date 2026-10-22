# October 21, waiting for the database at startup

- **Found by.** The Sample App's update scenario against native, on a fresh
  VM. MySQL's health check passed while its entrypoint ran a temporary
  socket-only server. Native connected just after that server stopped and
  before the real one listened, got `Connection refused` and exited, and
  Compose reported `dependency failed to start`.
- **PHP's behavior.** The published image's entrypoint runs
  `server:assert-php-database --allow-unavailable` and starts Apache while
  the database is unavailable. Requests fail until the database answers,
  but the server keeps running.
- **Native's behavior now.** When the database cannot be reached yet,
  native logs one line and retries with a backoff from 250 ms up to 5 s,
  then logs that the database is reachable. Cases that count as not reachable
  yet: the connection is refused or drops, the host doesn't resolve yet, the
  pool times out, or PostgreSQL answers SQLSTATE 57P03 (starting up). A
  refused login or a configuration error still stops the process.
  `SIGTERM` or `SIGINT` while waiting exits cleanly, even during a
  connection attempt.
- **Stop signal.** The published image declares `STOPSIGNAL SIGWINCH`.
  Native does not stop on it, so this is recorded as a known gap for a
  native image.
- **Verification.**
  - A unit test pins which errors are awaited.
  - Locally, native started four seconds before a fresh MySQL 8.0 container.
    It waited, connected, and `/api/health` answered 200.
  - Native waiting on an unresolvable host exited 0 within 0.4 s of
    `SIGTERM`.
