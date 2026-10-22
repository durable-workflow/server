const OFFICIAL_CRATES_IO_REGISTRY_SOURCES = new Set([
  'registry+https://github.com/rust-lang/crates.io-index',
  'registry+https://index.crates.io',
  'registry+https://index.crates.io/',
  'registry+sparse+https://index.crates.io',
  'registry+sparse+https://index.crates.io/',
  'sparse+https://index.crates.io',
  'sparse+https://index.crates.io/',
]);

export function isOfficialCratesIoRegistrySource(source) {
  return typeof source === 'string' && OFFICIAL_CRATES_IO_REGISTRY_SOURCES.has(source);
}
