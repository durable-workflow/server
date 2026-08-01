#!/usr/bin/env sh
set -eu

role="${1:-}"

case "$role" in
    worker)
        expected_command="artisan queue:work"
        ;;
    scheduler)
        expected_command="artisan schedule:evaluate"
        ;;
    matching)
        expected_command="artisan workflow:v2:repair-pass --loop"
        ;;
    *)
        echo "unknown Durable Workflow process healthcheck role: ${role:-<missing>}" >&2
        exit 2
        ;;
esac

# CLI services inherit the image's HTTP readiness check, but do not expose an
# HTTP listener. Inspect the container process namespace so Compose can wait for
# the actual long-running role without weakening the image-level readiness
# contract used by a plain `docker run`.
for process in /proc/[0-9]*; do
    pid="${process#/proc/}"
    if [ "$pid" = "$$" ] || [ ! -r "$process/cmdline" ]; then
        continue
    fi

    command_line="$(tr '\000' ' ' < "$process/cmdline" 2>/dev/null || true)"
    case "$command_line" in
        *"$expected_command"*)
            exit 0
            ;;
    esac
done

echo "Durable Workflow ${role} process is not running (expected ${expected_command})." >&2
exit 1
