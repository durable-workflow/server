"""One application definition for ordinary and replacement Nexus callers."""

from durable_workflow import NexusOperationFailed, workflow
import hashlib


@workflow.defn(name="nexus.fixture.python.caller")
class PythonNexusCaller:
    def run(self, context, endpoint, service, operation, name, target_namespace, handle_failure=True):
        external = name == "external"
        if external:
            name = "nexus-external:" + "x" * (4 * 1024 * 1024) + "☃"
        try:
            result = yield context.call_nexus_service(
                endpoint, service, operation, [name], wait_timeout_seconds=5,
                target_namespace=target_namespace,
            )
        except NexusOperationFailed as failure:
            if not handle_failure:
                raise
            return {
                "service_call_id": failure.service_call_id, "status": failure.status,
                "failure_type": failure.service_error_type,
                "failure_message": failure.typed_error_message or str(failure),
                "failure_class": type(failure).__name__,
                "response_or_failure_surface": failure.body,
            }
        if external:
            value = result.result
            return {"service_call_id": result.service_call_id, "status": result.status,
                    "external_result": {
                        "language": value["language"],
                        "name_bytes": len(value["name"].encode()), "name_sha256": hashlib.sha256(value["name"].encode()).hexdigest(),
                        "greeting_bytes": len(value["greeting"].encode()), "greeting_sha256": hashlib.sha256(value["greeting"].encode()).hexdigest(),
                        "bigint": str(value["bigint"]), "bigint_type": type(value["bigint"]).__name__,
                        "nullable": value["nullable"], "nullable_present": "nullable" in value, "unicode": value["unicode"],
                    }}
        return result.to_recorded_payload()
