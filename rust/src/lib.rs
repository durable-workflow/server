//! Development foundation for the Rust Server. No HTTP runtime is published yet.

pub mod codec;
pub mod config_audit;
pub mod database_tls;
pub mod external_payload;
pub mod runtime;

#[cfg(test)]
mod logging_tests {
    /// Dependency `log` messages can carry whole payloads; they are compiled
    /// out of every native build.
    #[test]
    fn dependency_logging_is_compiled_out() {
        assert_eq!(log::STATIC_MAX_LEVEL, log::LevelFilter::Off);
    }

    /// Even with a logger installed at the most verbose level, a value that
    /// fails Avro validation is never logged.
    #[test]
    fn rejected_avro_values_never_reach_a_logger() {
        static CAPTURED: std::sync::Mutex<Vec<String>> = std::sync::Mutex::new(Vec::new());
        struct Capture;
        impl log::Log for Capture {
            fn enabled(&self, _: &log::Metadata) -> bool {
                true
            }
            fn log(&self, record: &log::Record) {
                CAPTURED.lock().unwrap().push(record.args().to_string());
            }
            fn flush(&self) {}
        }
        let _ = log::set_logger(&Capture);
        log::set_max_level(log::LevelFilter::Trace);
        let marker = apache_avro::types::Value::String("customer-payload-marker".into());
        assert!(!marker.validate(&apache_avro::Schema::Long));
        assert!(!marker.validate_schemata(&[&apache_avro::Schema::Long, &apache_avro::Schema::Int]));
        assert!(CAPTURED.lock().unwrap().is_empty(), "{:?}", CAPTURED.lock().unwrap());
    }
}
