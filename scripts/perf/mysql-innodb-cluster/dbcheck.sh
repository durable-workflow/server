#!/usr/bin/env bash
# Database integrity after a failover run. Usage: dbcheck.sh <database> > rows.tsv
set -uo pipefail
db=$1
[[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || { echo "database must be a plain name" >&2; exit 2; }
M=mirror.gcr.io/library/mysql@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7
MYSQL_PWD=$(cat "${DW_MRC_STATE:?set DW_MRC_STATE}/mysql-password")
export MYSQL_PWD
timeout 60 docker run --rm --network dw-mrc --memory 128m -e MYSQL_PWD "$M" mysql -h dw-mrc-router -P 6446 -uroot -N "$db" -e "
SELECT 'runs by status', GROUP_CONCAT(CONCAT(status,'=',n)) FROM (SELECT status, COUNT(*) n FROM workflow_runs GROUP BY status) x;
SELECT 'workflow_tasks open', COUNT(*) FROM workflow_tasks WHERE status IN ('ready','leased');
SELECT 'activity_attempts running', COUNT(*) FROM activity_attempts WHERE status='running';
SELECT 'runs with !=1 WorkflowCompleted (completed runs)', COUNT(*) FROM workflow_runs r WHERE r.status='completed' AND (SELECT COUNT(*) FROM workflow_history_events e WHERE e.workflow_run_id=r.id AND e.event_type='WorkflowCompleted')<>1;
SELECT 'runs with !=2 ActivityCompleted (completed runs)', COUNT(*) FROM workflow_runs r WHERE r.status='completed' AND (SELECT COUNT(*) FROM workflow_history_events e WHERE e.workflow_run_id=r.id AND e.event_type='ActivityCompleted')<>2;
SELECT 'runs with !=1 TimerFired (completed runs)', COUNT(*) FROM workflow_runs r WHERE r.status='completed' AND (SELECT COUNT(*) FROM workflow_history_events e WHERE e.workflow_run_id=r.id AND e.event_type='TimerFired')<>1;
SELECT 'duplicate (run,sequence) history rows', COUNT(*) FROM (SELECT workflow_run_id, sequence FROM workflow_history_events GROUP BY 1,2 HAVING COUNT(*)>1) d;
SELECT 'history events by type', GROUP_CONCAT(CONCAT(event_type,'=',n) ORDER BY event_type) FROM (SELECT event_type, COUNT(*) n FROM workflow_history_events GROUP BY 1) y;
" 2>/dev/null | sed 's/\t/: /'
