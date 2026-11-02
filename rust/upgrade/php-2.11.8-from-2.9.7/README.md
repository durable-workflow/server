# Exact PHP source-catalog variant: Server 2.11.8 migrated from 2.9.7

These deltas apply to the [PHP 2.11.3 layout migrated from
2.9.7](../php-2.11.3-from-2.9.7/README.md). They make the same change as the
fresh [PHP 2.11.8 delta](../php-2.11.8/README.md); only the parent differs.

The same delta was captured as the difference between two databases that
published Server 2.9.7 created (image index
`sha256:30c34c5f3256a812fbccbe505a068309b58b0c14d01ffff0465d88604cb36d50`):
one then migrated by published 2.11.7 (image index
`sha256:be7c2288ff935396262d743590530b204fac11b76582e56a525cc81a8da840d3`),
and one migrated by published 2.11.8 (image index
`sha256:3848d5d5cd1b23bd2881e3fdecea64cc44b72e6fb812e577c2db6d0872d065dd`), on
SQLite, MySQL 8.0, MariaDB 10.11 through the `mysql` connection, and
PostgreSQL 17. Native's `upgrade-inspect` named each real 2.11.8 database
`php-2.11.8-from-2.9.7`. No application row values or credentials are
recorded. Takeover converts it as it converts fresh 2.11.8.
