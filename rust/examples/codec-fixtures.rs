//! Exercise reviewed logical values and cross-decode another official binding.
use std::{collections::BTreeMap, error::Error, fs};

use base64::{Engine, engine::general_purpose::STANDARD};
use durable_workflow_server::codec::{Value, ValueCodec};
use serde_json::{Value as Json, json};

fn tagged(value: &Json) -> Result<Value, Box<dyn Error>> {
    Ok(match value["type"].as_str().ok_or("missing type")? {
        "null" => Value::Null,
        "boolean" => Value::Boolean(value["value"].as_bool().ok_or("invalid boolean")?),
        "long" => Value::Long(value["value"].as_str().ok_or("invalid long")?.parse()?),
        "double" => Value::Double(value["value"].as_str().ok_or("invalid double")?.parse()?),
        "bytes" => Value::Bytes(STANDARD.decode(value["base64"].as_str().ok_or("invalid bytes")?)?),
        "string" => Value::String(value["value"].as_str().ok_or("invalid text")?.into()),
        "array" => Value::Array(
            value["items"]
                .as_array()
                .ok_or("invalid items")?
                .iter()
                .map(tagged)
                .collect::<Result<_, _>>()?,
        ),
        "map" => {
            let mut entries = BTreeMap::new();
            for entry in value["entries"].as_array().ok_or("invalid entries")? {
                let key = entry["key"].as_str().ok_or("invalid map key")?.to_string();
                if entries.insert(key, tagged(&entry["value"])?).is_some() {
                    return Err("duplicate fixture map key".into());
                }
            }
            Value::Map(entries)
        }
        _ => return Err("unknown tagged value".into()),
    })
}

fn main() -> Result<(), Box<dyn Error>> {
    let args: Vec<_> = std::env::args().collect();
    if !(2..=3).contains(&args.len()) {
        return Err("usage: codec-fixtures FIXTURE [OTHER_BINDING_OBSERVATIONS]".into());
    }
    let fixture: Json = serde_json::from_str(&fs::read_to_string(&args[1])?)?;
    assert_eq!(
        fixture["fixture_schema"],
        "durable-workflow.server-parity-codec/v1"
    );
    assert_eq!(fixture["protocol"]["codec"], "avro");
    assert_eq!(fixture["protocol"]["fingerprint"], "e2a33dff55802237");
    let cases = fixture["cases"].as_array().ok_or("missing cases")?;
    let other: Option<Json> = args
        .get(2)
        .map(|path| -> Result<_, Box<dyn Error>> {
            Ok(serde_json::from_str(&fs::read_to_string(path)?)?)
        })
        .transpose()?;
    let mut inputs = BTreeMap::new();
    if let Some(other) = other {
        assert_eq!(other["fixture_schema"], fixture["fixture_schema"]);
        for record in other["observations"]
            .as_array()
            .ok_or("missing observations")?
        {
            let id = record["id"].as_str().ok_or("missing id")?.to_string();
            let blob = record["blob"].as_str().ok_or("missing blob")?.to_string();
            if inputs.insert(id, blob).is_some() {
                return Err("duplicate observed id".into());
            }
        }
        assert_eq!(inputs.len(), cases.len(), "observation count");
    }
    let codec = ValueCodec::new()?;
    let mut observations = Vec::new();
    for case in cases {
        let id = case["id"].as_str().ok_or("missing fixture id")?;
        let expected = tagged(&case["value"])?;
        let blob = codec.encode(&expected)?;
        assert_eq!(codec.decode(&blob)?, expected, "{id}: Rust round trip");
        if let Some(wire) = case["wire_base64"].as_str() {
            assert_eq!(blob, wire, "{id}: reviewed golden wire");
        }
        if args.len() == 3 {
            let input = inputs.remove(id).ok_or("missing observed fixture")?;
            assert_eq!(
                codec.decode(&input)?,
                expected,
                "{id}: cross-binding decode"
            );
        }
        observations.push(json!({"id": id, "blob": blob}));
    }
    assert!(inputs.is_empty(), "unexpected observations");
    println!(
        "{}",
        json!({"fixture_schema": fixture["fixture_schema"], "observations": observations})
    );
    Ok(())
}
