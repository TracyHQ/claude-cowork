#!/usr/bin/env bash
# Why the showcase-navigation apply reads an item's children with a LOCKING read (MariaDB 11, two
# connections, #__menu's own unique index): inside a REPEATABLE-READ transaction a plain SELECT answers
# from the snapshot its first read took, so a child another connection commits afterwards stays
# invisible and the T3 sub of its parent could still be dropped. `FOR UPDATE` reads the latest committed
# rows, and its gap lock on (client_id, parent_id) makes a new child under that parent wait for our commit.
# With innodb_snapshot_isolation ON (MariaDB 11.8's default) the locking read refuses instead — error 1020,
# "Record has changed since last read" — so the batch rolls back and writes nothing: never a stale answer.
#
#   tests/e2e/showcase-nav-lock.sh            # needs docker; leaves nothing behind
set -euo pipefail

P=cowork-e2e-snav-lock
PW=e2e-root-pw
FAILED=0
OUT_A="$(mktemp "${TMPDIR:-/tmp}/$P.XXXXXX")"
cleanup() { docker rm -f -v "$P-db" >/dev/null 2>&1 || true; rm -f "$OUT_A"; }
trap cleanup EXIT

expect() {
  if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1"; echo "       got : $2"; echo "       want: $3"; FAILED=$((FAILED + 1)); fi
}
db() { docker exec -i "$P-db" mariadb -uroot -p"$PW" probe -N "$@"; }

docker run -d --name "$P-db" -e MARIADB_ROOT_PASSWORD="$PW" -e MARIADB_DATABASE=probe mariadb:11 >/dev/null
for _ in $(seq 1 60); do docker exec "$P-db" mariadb -uroot -p"$PW" -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
db <<'SQL'
CREATE TABLE menu (
  id int NOT NULL AUTO_INCREMENT, client_id tinyint NOT NULL DEFAULT 0, parent_id int unsigned NOT NULL DEFAULT 1,
  alias varchar(400) NOT NULL, language char(7) NOT NULL DEFAULT '*', published tinyint NOT NULL DEFAULT 0, params text NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY idx_client_id_parent_id_alias_language (client_id, parent_id, alias(100), language)
) ENGINE=InnoDB;
INSERT INTO menu (id, parent_id, alias, published, params) VALUES (101, 1, 'home', 1, '{}'), (145, 101, 'home-red', 1, '{"menu_show":"0"}'), (200, 1, 'other', 1, '{}'), (201, 150, 'next-parent', 1, '{}');
SQL

# Connection A takes its snapshot, waits while B commits a shown child under 101, then reads both ways.
committed_after() {
  db -e "DELETE FROM menu WHERE alias = 'our-own-home'"
  db -e "SET SESSION innodb_snapshot_isolation = $1; BEGIN; SELECT COUNT(*) FROM menu; SELECT SLEEP(3);
    SELECT CONCAT('plain:', COUNT(*)) FROM menu WHERE client_id = 0 AND parent_id = 101;
    SELECT CONCAT('locking:', COUNT(*)) FROM menu WHERE client_id = 0 AND parent_id = 101 FOR UPDATE; COMMIT;" > "$OUT_A" 2>&1 &
  local a=$!
  sleep 1
  db -e "INSERT INTO menu (parent_id, alias, published, params) VALUES (101, 'our-own-home', 1, '{}')"
  wait "$a" || true
  grep -oE '^(plain|locking):[0-9]+|ERROR 1020' "$OUT_A" | sort | tr '\n' ' '
}
echo "== a child committed after the transaction's first read"
expect "snapshot isolation off: the plain read misses it, the locking read sees it" "$(committed_after OFF)" "locking:2 plain:1 "
expect "snapshot isolation on: the plain read misses it, the locking read refuses" "$(committed_after ON)" "ERROR 1020 plain:1 "

echo "== a child inserted while the locking read is held"
db -e "DELETE FROM menu WHERE alias = 'our-own-home'"
db -e "BEGIN; SELECT id FROM menu WHERE client_id = 0 AND parent_id = 101 FOR UPDATE; SELECT SLEEP(4); COMMIT;" >/dev/null &
A=$!
sleep 1
BLOCKED=$(db -e "SET SESSION innodb_lock_wait_timeout = 2; INSERT INTO menu (parent_id, alias, published, params) VALUES (101, 'our-own-home', 1, '{}')" 2>&1 | grep -c 'Lock wait timeout' || true)
expect "an insert under that parent waits for the commit" "$BLOCKED" 1
OTHER=$(db -e "SET SESSION innodb_lock_wait_timeout = 2; INSERT INTO menu (parent_id, alias, published, params) VALUES (200, 'elsewhere', 1, '{}'); SELECT 'inserted'" 2>&1 | tail -1)
expect "an insert under another parent does not" "$OTHER" inserted
wait "$A"

echo
if [ "$FAILED" -gt 0 ]; then echo "$FAILED check(s) failed"; exit 1; fi
echo "every check passed"
