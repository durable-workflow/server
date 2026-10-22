# October 22, children stay in their parent's namespace

- **Why.** Native wrote every child instance and run into the `default`
  namespace. A workflow in a named namespace that started a child lost
  that child: its worker could not claim it, and the namespace's reads did
  not show it.
- **Source.** Workflow 2.9.0 `ChildWorkflowNamespaceProjection` places a
  child in its parent's namespace.
- **Behavior.**
  - A child instance and run take the parent run's namespace. Its tasks
    follow, since tasks take their run's namespace.
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
