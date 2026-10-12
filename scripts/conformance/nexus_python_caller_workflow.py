"""One application definition for ordinary and replacement Nexus callers."""

from durable_workflow import NexusOperationFailed, workflow


@workflow.defn(name="nexus.fixture.python.caller")
class PythonNexusCaller:
    def run(self, context, endpoint, service, operation, name, target_namespace):
        try:
            result = yield context.call_nexus_service(
                endpoint, service, operation, [name], wait_timeout_seconds=5,
                target_namespace=target_namespace,
            )
        except NexusOperationFailed as failure:
            return {
                "service_call_id": failure.service_call_id, "status": failure.status,
                "failure_type": failure.service_error_type,
                "failure_message": failure.typed_error_message or str(failure),
                "failure_class": type(failure).__name__,
                "response_or_failure_surface": failure.body,
            }
        return result.to_recorded_payload()
