# October 31, fairness within a priority tier

- **Before.** Native tried a poll's candidates in plain priority and
  availability order. PHP reorders them with `TaskFairnessScheduler`.
- **Contract** (published Server 2.11.7, Workflow 2.15.7):
  - `WorkflowTaskPoller::claimReadyTask` and
    `ActivityTaskPoller::claimReadyTask` read a batch of candidates (limit
    `max(10, server.polling.max_tasks_per_poll * 10)`, so 10 by default).
    The workflow bridge already filters the workflow types in SQL, and pages
    forward until a batch holds an eligible task.
  - The poll tries the batch in `TaskFairnessScheduler::reorder` order.
    Tiers keep their priority order. Within a tier, the next candidate comes
    from the class with the fewest picks per unit of weight. The scheduler
    adds the scores in the fairness state; ties go to the earliest-seen
    class, and tasks without a key share one default class.
  - The fairness state is `InMemoryTaskFairnessState`, bound by
    `WorkflowServiceProvider`. Under the published Apache image it starts
    empty on every request, so only the batch's own picks count. The first
    candidate never moves; the order matters when an earlier candidate can't
    be claimed.
- **Evidence.** Raw worker HTTP against published 2.11.7, with a worker that
  can't replay typed search attributes:
  - Ready tasks in time order were A1 (a resume task carrying typed
    upserts), C (no fairness key) and A2 (key `a`).
  - PHP claimed A2, then C. The order was A1, A2, C, with A1 skipped.
    Time order would have claimed C.
- **Native** (`priority_fairness::fairness_order`, `Store::fairness_batches`):
  - The claim loop drops candidates of an unsupported workflow type, as
    PHP's SQL does.
  - It splits the rest into batches of 10 and tries each batch in the same
    order.
  - Tests: `fairness_order` unit tests pin the order (alternation, weights,
    tiers), and `a_skipped_candidate_is_followed_by_another_fairness_class`
    pins the claim path.
- **No shared fixture.** In a shared recording the skipped candidate is
  always a resume task, because a first task carries no history to skip it
  on. Published PHP gives resume tasks the default priority and no fairness
  key, against its published inheritance rule
  ([workflow#971](https://github.com/durable-workflow/workflow/issues/971)).
  Native follows the rule, so the two servers place that task in different
  classes. With the same keys, native gave the same order as PHP.
- **Remaining.**
  - PHP's activity poll stops after its first batch of 10; native keeps
    looking.
  - An activity's type is read with its execution, so native's activity
    batches also hold candidates of other activity types, which PHP's SQL
    leaves out.
