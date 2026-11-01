#!/usr/bin/env bash
# Disposable 3-member MySQL 8.4 InnoDB Cluster + Router with fixed IPs and
# static host entries (names keep resolving after a member dies, as on hosts).
# A random password for this cluster is written to $DW_MRC_STATE/mysql-password
# and passed to containers through the environment, never on a command line.
set -euo pipefail
here=${DW_MRC_STATE:?set DW_MRC_STATE to a scratch directory}
M=mirror.gcr.io/library/mysql@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7
R=container-registry.oracle.com/mysql/community-router@sha256:d704471c2bb78fa833790dc197959c004d09af5aa18f5f153ff40e7268f54043
hosts=(--add-host dw-mrc-db1:172.30.77.11 --add-host dw-mrc-db2:172.30.77.12 --add-host dw-mrc-db3:172.30.77.13 --add-host dw-mrc-router:172.30.77.20)

umask 077
if [[ ! -s "$here/mysql-password" ]]; then
  head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24 > "$here/mysql-password"
fi
MYSQL_ROOT_PASSWORD=$(cat "$here/mysql-password")
export MYSQL_ROOT_PASSWORD MYSQL_PWD="$MYSQL_ROOT_PASSWORD" MYSQL_PASSWORD="$MYSQL_ROOT_PASSWORD" DW_MRC_PASSWORD="$MYSQL_ROOT_PASSWORD"

timeout 30 docker network create --subnet 172.30.77.0/24 dw-mrc >/dev/null 2>&1 || true
for i in 1 2 3; do
  timeout 60 docker run -d --name "dw-mrc-db$i" --hostname "dw-mrc-db$i" --network dw-mrc --ip "172.30.77.1$i" "${hosts[@]}" \
    --memory 1g -e MYSQL_ROOT_PASSWORD -e MYSQL_ROOT_HOST=% "$M" --server-id="$i" --gtid-mode=ON \
    --enforce-gtid-consistency=ON --report-host="dw-mrc-db$i" --innodb-buffer-pool-size=128M --max-connections=200 >/dev/null
done
for i in 1 2 3; do
  timeout 180 bash -c "until docker exec -e MYSQL_PWD dw-mrc-db$i mysqladmin -uroot ping --silent 2>/dev/null; do sleep 3; done"
done
sleep 25

cat > "$here/build.js" <<'EOF'
var pw = os.getenv('DW_MRC_PASSWORD');
function uri(host) { return {scheme: 'mysql', user: 'root', password: pw, host: host, port: 3306}; }
['dw-mrc-db1', 'dw-mrc-db2', 'dw-mrc-db3'].forEach(function (h) { dba.configureInstance(uri(h), {restart: false}); });
var c = dba.createCluster('dw');
shell.options['dba.restartWaitTimeout'] = 300;
c.addInstance(uri('dw-mrc-db2'), {recoveryMethod: 'clone'});
c.addInstance(uri('dw-mrc-db3'), {recoveryMethod: 'clone'});
c.rescan({addUnmanaged: true, removeObsolete: true});
var t = c.status().defaultReplicaSet.topology;
for (var k in t) { print(k + ' ' + t[k].memberRole + ' ' + t[k].status + '\n'); }
EOF
timeout 30 docker cp "$here/build.js" dw-mrc-db1:/tmp/build.js
# Clone restarts the joiner's mysqld; the container exits, so start it again.
( for _ in $(seq 1 200); do
    for i in 2 3; do
      if [[ "$(docker inspect -f '{{.State.Status}}' "dw-mrc-db$i" 2>/dev/null)" == exited ]]; then docker start "dw-mrc-db$i" >/dev/null; fi
    done
    sleep 3
  done ) &
watcher=$!
printf '%s\n' "$DW_MRC_PASSWORD" | timeout 900 docker exec -i -e LC_ALL=C -e DW_MRC_PASSWORD dw-mrc-db1 \
  mysqlsh --uri root@dw-mrc-db1:3306 --passwords-from-stdin --js --file /tmp/build.js 2>&1 | grep -E 'PRIMARY|SECONDARY|ERROR|Error' || true
kill "$watcher" 2>/dev/null || true
timeout 60 docker exec -e MYSQL_PWD -e DW_MRC_PASSWORD dw-mrc-db1 sh -c \
  'mysql -uroot -e "CREATE USER IF NOT EXISTS '"'"'dw'"'"'@'"'"'%'"'"' IDENTIFIED BY '"'"'$DW_MRC_PASSWORD'"'"';"' 2>/dev/null
timeout 60 docker run -d --name dw-mrc-router --hostname dw-mrc-router --network dw-mrc --ip 172.30.77.20 "${hosts[@]}" --memory 256m \
  -e MYSQL_HOST=dw-mrc-db1 -e MYSQL_PORT=3306 -e MYSQL_USER=root -e MYSQL_PASSWORD -e MYSQL_INNODB_CLUSTER_MEMBERS=3 \
  -e MYSQL_CREATE_ROUTER_USER=0 "$R" mysqlrouter >/dev/null
timeout 120 bash -c 'until docker logs dw-mrc-router 2>&1 | grep -q "listening on .0.0.0.0:6446"; do sleep 3; done'
echo cluster-and-router-up
