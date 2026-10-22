use super::*;

const MAGIC: &str = "The input envelope blob could not be decoded with codec \"avro\": invalid_payload_framing: expected Avro single-object magic c301. Remediation: Encode the value with the Durable Workflow 2.0 fixed Avro Value schema and single-object framing; JSON is only the HTTP document transport.";

async fn started(app: &Router, workflow: &str, input: Value) -> (StatusCode, Value) {
    request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":"test","input":input})).await
}

async fn signalled(app: &Router, input: Value) -> (StatusCode, Value) {
    request(app, "POST", "/api/workflows/envelopes/signal/note", json!({"input":input})).await
}

// Published PHP refuses a malformed input envelope as Laravel validation of
// `input.codec` or `input.blob`, on start and signal alike.
#[tokio::test]
async fn malformed_input_envelopes_are_refused_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    start(&app, "envelopes").await;
    let codec = |name: &str| format!("unsupported_payload_codec: workflow payload codec \"{name}\" is not supported by Durable Workflow 2.0; use codec=\"avro\" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec.");
    let cases = [
        (json!({"codec":"json","blob":"[]"}), "input.codec", codec("json")),
        (json!({"codec":"json"}), "input.codec", codec("json")),
        (json!({"codec":1,"blob":"x"}), "input.codec", codec("int")),
        (json!({"codec":null,"blob":"x"}), "input.codec", "The payload envelope codec must be a non-empty string.".to_owned()),
        (json!({"codec":"avro","blob":""}), "input.blob", "The payload envelope blob must be a string.".to_owned()),
        (json!({"codec":"avro","blob":"QUJD"}), "input.blob", MAGIC.to_owned()),
    ];
    for (index, (input, field, message)) in cases.into_iter().enumerate() {
        for (status, body) in [started(&app, &format!("refused-{index}"), input.clone()).await, signalled(&app, input.clone()).await] {
            assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{input}: {body}");
            assert_eq!(body["reason"], "validation_failed", "{input}: {body}");
            assert_eq!(body["errors"][field], json!([message]), "{input}: {body}");
        }
    }
    // The transport refuses provider-specific references first.
    for (status, body) in [started(&app, "provider", json!({"codec":"avro","external_storage":{}})).await,
        signalled(&app, json!({"codec":"avro","external_storage":{}})).await] {
        assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("external_payload_unsupported")), "{body}");
    }

    // A signal's blob must decode to a list or a map; a start's may be any value.
    let scalar = envelope(Payload::Long(7));
    let (status, body) = signalled(&app, scalar.clone()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"]["input.blob"], json!(["The input envelope blob must decode to an array."]));
    assert_eq!(started(&app, "scalar", scalar).await.0, StatusCode::CREATED);
    let map = envelope(Payload::Map([("a".to_owned(), Payload::Long(1))].into_iter().collect()));
    assert_eq!(signalled(&app, map).await.0, StatusCode::ACCEPTED);
    // PHP's strict base64 takes a blob without its padding.
    let list = envelope(Payload::Array(vec![Payload::Long(1)]));
    let unpadded = json!({"codec":"avro","blob":list["blob"].as_str().unwrap().trim_end_matches('=')});
    assert_eq!(signalled(&app, unpadded).await.0, StatusCode::ACCEPTED);
}
