# October 23, task queue priority and fairness

- **What is served.** `GET /api/task-queues/{queue}/priority-fairness`, as
  published Server 2.11.1's `TaskQueuePriorityFairnessSurface` answers it.
  For workflow and activity tasks it reports:
  - the ready count;
  - priority tiers, most urgent first, each with its fairness classes
    sorted by key;
  - each class's count and its first task's weight;
  - each class's recent dispatch score.
- **Why every score is 0.** PHP keeps dispatch scores in
  `InMemoryTaskFairnessState`, which its Apache server builds fresh for
  every request. So every class scores 0. PHP's fairness reorder then never
  moves a poll's first candidate: within the most urgent tier, the first
  class it picks is the oldest task's class. A poll takes the oldest ready
  task of the most urgent tier, which is native's existing order.
- **How it was checked.** A probe started workflows on the published image
  and on native with these settings:
  - two fairness keys, one mixed-case;
  - fairness weights;
  - a task with neither key nor weight;
  - an urgent priority;
  - a low priority;
  - an invalid key;
  - an invalid weight.

  It then read the snapshot and drained the queue with one worker. The
  snapshot, the validation refusals and the dispatch order matched.
  `priority_fairness_reports_tiers_and_classes_like_php` passes on SQLite,
  PostgreSQL and MySQL.
