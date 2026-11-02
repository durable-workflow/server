#!/usr/bin/env bash
# Installed acceptance of the schedule daylight-saving contract (Server #938,
# #939; Core 2.15.13) against a published native image by digest.
#
#   schedule-dst-acceptance.sh IMAGE@sha256:<digest>
#
# Shared parity cannot carry these cases: published PHP Server 2.11.8 keeps
# the pre-2.15.13 behavior. Schedule backfill computes occurrences through
# the same next-occurrence path as firing, so a backfill across each change
# shows the contract without moving any clock:
#   - a specific-hour time repeated when clocks go back fires once;
#   - every-hour schedules keep both passes of the repeated hour;
#   - a skipped time fires once, shifted by the gap.
# Each case compares the complete list of fire times, with their offsets.
# The server runs on SQLite in a bounded container, on loopback only, with a
# throwaway token. Prints one JSON line; exits non-zero on any mismatch or
# refused request.
#
# For a source build that is already running (for example before a candidate
# is published), set DW_DST_ACCEPTANCE_URL and DW_DST_ACCEPTANCE_TOKEN and pass
# a label instead of an image; no container is started.
set -euo pipefail
shopt -s inherit_errexit
image=${1:?usage: schedule-dst-acceptance.sh IMAGE@sha256:<digest>}

if [[ -n "${DW_DST_ACCEPTANCE_URL:-}" ]]; then
  base=$DW_DST_ACCEPTANCE_URL
  token=${DW_DST_ACCEPTANCE_TOKEN:?set DW_DST_ACCEPTANCE_TOKEN with DW_DST_ACCEPTANCE_URL}
else
  [[ "$image" == *@sha256:* ]] || { echo 'pass the image by digest' >&2; exit 2; }
  name="dw-dst-acceptance-$$"
  port=${DW_DST_ACCEPTANCE_PORT:-18089}
  token="dst-acceptance-$RANDOM$RANDOM"
  cleanup() { docker stop --time 5 "$name" >/dev/null 2>&1 || true; }
  trap cleanup EXIT
  docker run -d --rm --init --name "$name" --cpus 1 --memory 512m --pids-limit 256 \
    -p "127.0.0.1:$port:8080" --user "$(id -u):$(id -g)" \
    --tmpfs "/state:uid=$(id -u),gid=$(id -g),mode=0700" \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/acceptance.sqlite -e DW_AUTH_TOKEN="$token" \
    "$image" >/dev/null
  base="http://127.0.0.1:$port"
fi

for _ in $(seq 1 60); do
  curl -fsS -o /dev/null "$base/api/ready" 2>/dev/null && break
  sleep 1
done
curl -fsS -o /dev/null "$base/api/ready"

headers=(-H "Authorization: Bearer $token" -H 'Content-Type: application/json'
  -H 'Accept: application/json' -H 'X-Durable-Workflow-Control-Plane-Version: 2')

# Schedule ids are unique per run, so a rerun against the same server works.
run="$$-$RANDOM"

# fire_times ID CRON ZONE START END: the backfilled fire times, one per line.
fire_times() {
  local id="$1-$run" cron=$2 zone=$3 start=$4 end=$5 body
  body=$(jq -cn --arg id "$id" --arg cron "$cron" --arg zone "$zone" \
    '{schedule_id:$id,spec:{cron_expressions:[$cron],timezone:$zone},
      action:{workflow_type:"acceptance.noop",task_queue:"dst-acceptance"},note:"dst acceptance"}')
  curl -fsS -X POST "$base/api/schedules" "${headers[@]}" -d "$body" >/dev/null
  curl -fsS -X POST "$base/api/schedules/$id/backfill" "${headers[@]}" \
    -d "$(jq -cn --arg s "$start" --arg e "$end" '{start_time:$s,end_time:$e}')" \
    | jq -er '.results[].fire_time'
}

failures=()
passed=()
# expect LABEL ID CRON ZONE START END WANTED...: the complete fire-time list,
# with offsets, must equal WANTED exactly. A refused request ends the run.
expect() {
  local label=$1 actual wanted
  if ! actual=$(fire_times "$2" "$3" "$4" "$5" "$6"); then
    echo "request failed for $label" >&2
    exit 1
  fi
  shift 6
  wanted=$(printf '%s\n' "$@")
  if [[ "$actual" == "$wanted" ]]; then
    passed+=("$label")
  else
    failures+=("$label: got [$(tr '\n' ' ' <<<"$actual")] want [$(tr '\n' ' ' <<<"$wanted")]")
  fi
}

# New York, 2026-11-01: 01:00-02:00 repeats. A daily 01:30 fires once.
expect daily-new-york-fold ny-fold '30 1 * * *' America/New_York 2026-10-31T00:00:00Z 2026-11-03T00:00:00Z \
  2026-10-31T01:30:00-04:00 2026-11-01T01:30:00-04:00 2026-11-02T01:30:00-05:00

# Kyiv, 2026-10-25: 03:00-04:00 repeats. A daily 03:30 fires once.
expect daily-kyiv-fold kyiv-fold '30 3 * * *' Europe/Kyiv 2026-10-24T00:00:00Z 2026-10-27T00:00:00Z \
  2026-10-24T03:30:00+03:00 2026-10-25T03:30:00+03:00 2026-10-26T03:30:00+02:00

# From exactly New York's transition instant (06:00Z): 01:30 EST is the
# repeated hour's second pass, so the next fire is the following day.
expect daily-new-york-from-transition ny-transition '30 1 * * *' America/New_York 2026-11-01T06:00:00Z 2026-11-02T12:00:00Z \
  2026-11-02T01:30:00-05:00

# Every half hour from exactly that instant keeps the second pass.
expect half-hourly-new-york-from-transition ny-half-transition '*/30 * * * *' America/New_York 2026-11-01T06:00:00Z 2026-11-01T07:00:00Z \
  2026-11-01T01:00:00-05:00 2026-11-01T01:30:00-05:00

# Every half hour keeps both passes of New York's repeated hour.
expect half-hourly-new-york-fold ny-half-hourly '*/30 * * * *' America/New_York 2026-11-01T04:45:00Z 2026-11-01T06:45:00Z \
  2026-11-01T01:00:00-04:00 2026-11-01T01:30:00-04:00 2026-11-01T01:00:00-05:00 2026-11-01T01:30:00-05:00

# New York, 2026-03-08: 02:30 does not exist; it fires once at 03:30 EDT.
expect daily-new-york-gap ny-gap '30 2 * * *' America/New_York 2026-03-07T00:00:00Z 2026-03-10T00:00:00Z \
  2026-03-07T02:30:00-05:00 2026-03-08T03:30:00-04:00 2026-03-09T02:30:00-04:00

if (( ${#failures[@]} )); then
  jq -cn --arg image "$image" --args '{outcome:"fail",image:$image,failures:$ARGS.positional}' "${failures[@]}"
  exit 1
fi
jq -cn --arg image "$image" --args '{outcome:"pass",image:$image,cases:$ARGS.positional}' "${passed[@]}"
