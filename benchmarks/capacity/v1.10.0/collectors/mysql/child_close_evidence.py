"""Attribute native MySQL database work to each committed child close."""

import argparse
import csv
from dataclasses import dataclass
import json
from pathlib import Path
import re
import sys
from typing import Iterable, Iterator


GTID = re.compile(r"^SET @@SESSION\.GTID_NEXT= '([\da-f-]{36}:\d+)'")
CHILD_UPDATE = re.compile(r"^### UPDATE `(\w+)`\.`workflow_child_calls`$")
ROW_VALUE = re.compile(r"^###   @(\d+)=(.*)$")


@dataclass(frozen=True)
class Close:
    gtid: str
    schema: str
    call_id: int


def committed_closes(
    lines: Iterable[str], schema: str, status_column: int
) -> Iterator[Close]:
    """Read only synthetic child-call identities from decoded ROW binlog events."""
    gtid = None
    call_id = None
    in_child_update = False
    after_image = False
    transaction = []
    for line in lines:
        line = line.rstrip("\n")
        if line.startswith("SET @@SESSION.GTID_NEXT"):
            match = GTID.match(line)
            gtid = match[1] if match else None
            transaction.clear()
            in_child_update = False
        elif line.startswith(("### UPDATE", "### INSERT", "### DELETE")):
            match = CHILD_UPDATE.fullmatch(line)
            in_child_update = gtid is not None and match is not None and match[1] == schema
        elif line.startswith("COMMIT"):
            yield from transaction
            transaction.clear()
            gtid = None
            in_child_update = False
        elif in_child_update:
            if line == "### WHERE":
                after_image = False
                call_id = None
            elif line == "### SET":
                after_image = True
            else:
                value = ROW_VALUE.match(line)
                if value is None:
                    continue
                column, field = int(value[1]), value[2]
                if not after_image and column == 1:
                    call_id = int(field)
                elif after_image and column == status_column and field == "'completed'":
                    if call_id is None:
                        raise ValueError("completed child call has no primary key in its before image")
                    transaction.append(Close(gtid, schema, call_id))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--schema", required=True)
    parser.add_argument("--status-column", type=int, required=True)
    parser.add_argument("--expected-closes", type=int, required=True)
    parser.add_argument("--statements", type=Path, help="TSV output from child_close_statements.sql")
    args = parser.parse_args()
    if not re.fullmatch(r"[a-zA-Z0-9_]+", args.schema) or args.status_column < 2:
        parser.error("expected a fixture schema and the ordinal position of the child-call status")
    if args.expected_closes < 1:
        parser.error("--expected-closes must be positive")
    try:
        closes = list(committed_closes(sys.stdin, args.schema, args.status_column))
        require_exact_closes(closes, args.expected_closes)
        if args.statements is not None:
            with args.statements.open(newline="") as handle:
                rows = list(child_close_metrics(closes, csv.reader(handle, delimiter="\t")))
            for row in rows:
                print(json.dumps(row, separators=(",", ":")))
        else:
            writer = csv.writer(sys.stdout, delimiter="\t", lineterminator="\n")
            writer.writerows((close.gtid, close.schema, close.call_id) for close in closes)
    except ValueError as exc:
        parser.error(str(exc))


def require_exact_closes(closes: list[Close], expected: int) -> None:
    if len(closes) != expected:
        raise ValueError(f"expected {expected} committed child closes, found {len(closes)}")
    if len({close.call_id for close in closes}) != expected:
        raise ValueError("a child call closed more than once")
    if len({close.gtid for close in closes}) != expected:
        raise ValueError("each child close must have a separate committed transaction")


def child_close_metrics(closes: list[Close], rows: Iterable[list[str]]) -> Iterator[dict]:
    wanted = {close.gtid: close for close in closes}
    by_gtid = {}
    for row in rows:
        if len(row) != 12:
            raise ValueError("invalid statement metrics row")
        gtid, schema, *raw = row
        if gtid not in wanted:
            continue
        if gtid in by_gtid or schema != wanted[gtid].schema:
            raise ValueError("duplicate statement transaction or mismatched schema")
        (statements, examined, sent, affected, errors, first_examined, first_sent,
         first_affected, first_elapsed_ps, first_lock_ps) = map(int, raw)
        if statements < 2 or errors or (first_examined, first_sent, first_affected) != (1, 1, 0):
            raise ValueError("incomplete child-close statement history or changed transition statement")
        by_gtid[gtid] = {
            "sql_statements": statements,
            "rows_examined": examined,
            "rows_sent": sent,
            "rows_affected": affected,
            "transition_statement_ms": first_elapsed_ps / 1_000_000_000,
            "transition_statement_lock_time_ms": first_lock_ps / 1_000_000_000,
        }

    if len(by_gtid) != len(closes):
        raise ValueError("statement history is missing one or more committed child closes")
    for index, close in enumerate(closes, 1):
        yield {"close_index": index, **by_gtid[close.gtid]}


if __name__ == "__main__":
    main()
