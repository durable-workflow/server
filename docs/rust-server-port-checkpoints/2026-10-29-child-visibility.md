# October 29, memos and search attributes on child runs

- **Before.** Native copied a run's memos and search attributes only to a
  run that continued it as new. A child's first run, and a run retrying a
  failed child, started with none. So their descriptions and run lists
  showed an empty memo, and a visibility query for the parent's attribute
  did not find the child.
- **Contract** (published Server 2.11.7, Workflow 2.15.7
  `inheritTypedVisibilityMetadata`, `MemoUpsertService::inheritFromParent`
  and `SearchAttributeUpsertService::inheritFromParent`):
  - a child's first run takes its parent run's memos and search
    attributes;
  - a retry takes those of the run it retries;
  - each copy is recorded as inherited at sequence 1.

  A visibility query lists every run of the child that matches, the failed
  first run and the pending retry alike.
- **Fixture.** `child-visibility`, raw worker HTTP on protocol 1.20 in a
  fresh namespace with one registered keyword attribute. A parent started
  with a memo and the attribute starts one child under a retry policy. The
  probe records:
  - the child's description;
  - after a failure, the retry's description;
  - the child's run list;
  - a query for the attribute.
- **Native.** The continue-as-new copy is now two shared helpers,
  `inheritable_memos` and `inherit_visibility` (`continuations.rs`).
  `start_child` and the child retry call them right after creating the run.
- **Tests.** `a_child_and_its_retry_inherit_the_parents_memos_and_search_attributes`
  (`child_retries.rs`), and the existing continue-as-new tests for the
  shared copy.
- **Also here** (Security's re-review of #869). A child backoff sent as a
  JSON object, which PHP reads as its values, is now checked at admission
  like a list. Before, such an extreme value was refused only when the
  retry started (`invalid_child_retry_policy`), and it never overflowed.
