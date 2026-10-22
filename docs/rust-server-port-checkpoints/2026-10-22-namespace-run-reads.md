# October 22, named-namespace run reads

- **Found by.** The fourth disposable VM run of the Sample App against
  native (`dw-rs325-20261022T050116`). The namespaces scenario's operator
  step opens Waterline's run detail for a run in a named namespace, and
  Waterline answered 422. Waterline's service-mode detail
  (`RemoteWorkflowsController::detail`) first lists the instance's runs
  (`GET /api/workflows/{id}/runs`). Native refused that in a named namespace
  with `development_namespace_operation_unqualified`.
- **Source.** Named namespaces now serve the four reads Waterline's run
  detail and export use: the run list, the instance and run debug views, and
  the run history export. Each reads the instance or run within the
  request's namespace before anything else, so another namespace gets 404
  for the globally unique workflow id, as with describe and history.
- **Remaining gap** (the port's gap list): updates, repair and cooperative
  cancellation requests are still refused in named namespaces.
- **Verification.** A new test starts a run in one named namespace, reads
  all four routes there, and checks that a second named namespace gets 404
  for each.
