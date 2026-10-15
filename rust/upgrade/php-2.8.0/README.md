# Exact PHP source-catalog variant

These small catalog deltas come from normal bootstrap of published Server
2.8.0, source `dd720e6b51844707be2885f8873c23de98c847a7`, image index
`sha256:cba7c4c6732529293ef6c2625a64785dcb21b4f7fe0013fdc74f1af466f534ac`.
The actual amd64 image configuration is
`sha256:4199bcbaf349a600cbb29a7ced6170b72c49a51828c39f0e7963dc102b642911`.

They contain only complete physical catalog definitions: service-call deadline
storage/index and worker registration token/incarnation storage/indexes. Every
removed definition must match the compiled older PHP layout exactly. Applying
the delta in memory yields another complete expected catalog; unknown or changed
objects still refuse. Nothing is applied to a source database, and native
migrations, their checksums and the frozen differential catalog stay unchanged.

SQLite definitions were read from an actual published-image database. MySQL
8.4.5, MariaDB 10.11.19 and PostgreSQL 17 definitions were collected after successful raw public
protocol preparation and explicit PHP SIGTERM/exit 0. The earlier partial MySQL
catalog read during bootstrap was discarded and is not fixture provenance.
Catalog inspection records no application row values or credentials.

This recognizes physical layout for read-only inventory only. It neither
identifies the installed package from a schema nor qualifies native execution,
stored representations, backup, writer quiescence or takeover of that layout.
WAL SQLite remains explicitly refused before opening; its definitions alone
do not qualify WAL inspection.
