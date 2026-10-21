//! Development foundation for the Rust Server. No HTTP runtime is published yet.

pub mod codec;
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
}
