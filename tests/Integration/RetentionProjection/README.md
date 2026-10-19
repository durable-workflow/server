# PHP Server retention and projection consumer

This independent PHP Server consumer covers [Workflow #852](https://github.com/durable-workflow/workflow/issues/852).
It uses a normally installed Python SDK and real HTTP requests. It does not
change the native differential runner, frozen actors or advertised capabilities.

Use a disposable Server database with only this fixture's default namespace.
The final retention passes inspect that namespace. Record the exact Server
image, installed Workflow package, SDK version and this fixture commit.

Install `durable-workflow==2.6.1` into a fresh Python 3.12 virtual environment.
Set `DURABLE_WORKFLOW_SERVER_URL` and `DURABLE_WORKFLOW_AUTH_TOKEN` for the
isolated Server. Then run:

```sh
python consumer.py admit-prune state.json
```

The consumer completes one genuine workflow with exact int64/null/Unicode
output, confirms its list row, explicitly prunes that original through the
retention API, and checks health, missing-summary lag, drift metrics and list
absence. Each phase has a 30-second observation budget.

Against the same database, execute the supported maintenance command in each
mode, including its preview:

```sh
php artisan workflow:v2:rebuild-projections --missing --dry-run --json
php artisan workflow:v2:rebuild-projections --missing --json
php artisan workflow:v2:rebuild-projections --needs-rebuild --dry-run --json
php artisan workflow:v2:rebuild-projections --needs-rebuild --json
php artisan workflow:v2:rebuild-projections --dry-run --json
php artisan workflow:v2:rebuild-projections --json
```

Run the cold reader after maintenance:

```sh
python consumer.py verify state.json
```

It requires zero missing-summary drift/age, an absent list row and two further
automatic retention passes with no candidates. Keep the source and readable
outcome in GitHub. Remove the disposable database, state file, SDK environment
and runtime after checking the outcome. The stored run tombstone and original
pruning marker can be checked independently before deleting the database.

This recipe is preparation until executed on a named published tuple. A failed
original stays failed. It does not qualify native parity or a managed runtime.
