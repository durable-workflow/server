# PHP Server accepted-signal terminal boundary

This independent PHP image consumer covers
[Workflow #857](https://github.com/durable-workflow/workflow/issues/857).
Use the portable fixture shipped by the normally installed Core dependency:

```sh
python vendor/durable-workflow/workflow/tests/Integration/SignalTerminalBoundary/consumer.py
```

Install `durable-workflow==2.6.1` into a fresh Python virtual environment. Set
`SERVER_URL` and `AUTH_TOKEN` for a disposable PHP Server database, then run the
fixture once with `NAMESPACE=default` and again with a separately created named
namespace. Do not run this qualification against customer data. Record the
immutable Server image, Core version/reference, SDK version and fixture revision.

Each namespace has six real originals: complete/CAN × declared/legacy-known/
legacy-unknown. A real HTTP request barrier admits a typed int64/null/Unicode
signal after the workflow has replayed but before its terminal submission.
Declared signals must reach their handler once. Missing declarations must stay
unknown; those accepted signals get one explicit rejected disposition with no
false application or continuation handoff. Responses are never mocked. Each
case retains its original 30-second observer, 120-second execution and 60-second
run budgets.

After Workers drain, verify disposition, original deadlines, contiguous history
and first-attempt tasks through a stopped read-only database audit. Check the
rejected reason and history event through normally installed Waterline and `dw`.
Then retire the disposable runtime, database, installed SDK and raw responses.

This is preparation until run against a named published tuple. The Core source
component and any failed baseline remain separate. It does not qualify native
parity, a frozen tuple or a managed deployment.
