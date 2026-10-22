# October 22, children in named namespaces

- **Why.** Published PHP lets a workflow in any namespace start a child.
  Native refused `start_child_workflow` in a named namespace, and its child
  start wrote every child instance and run into `default`. A few worker
  checks and the cancellation deadline sweep also read only `default`.
- **Source.** Workflow 2.9.0 `ChildWorkflowNamespaceProjection` places a
  child in its parent's namespace.
- **Behavior.**
  - A child instance and run take the parent run's namespace. Its tasks
    follow, since tasks take their run's namespace.
  - A named-namespace workflow task may now start a child. Native used to
    refuse `start_child_workflow` there
    (`development_namespace_command_unqualified`).
  - The worker checks in `deliver-cancellation` and update application
    read the worker registration in the task's namespace, not in `default`.
  - The cooperative cancellation deadline sweep covers every namespace.
    After a takeover, a PHP-written request in a named namespace now
    expires on time.
- **Unchanged.** Update, repair and cooperative cancellation requests in a
  named namespace are still refused
  (`development_namespace_operation_unqualified`). That gap is listed
  under "Named-namespace workflow commands".
- **Test.** In namespace `alpha`, a parent starts a child. The child is
  claimed in `alpha`, is described there and is not found in `default`.
  Its result resumes the parent, which completes.
