# Schedule visibility parser vectors

`system-fields.json` is the exact 7,903-byte fixture from Workflow commit
`d28ec380b98f3a9f09447df464b9cfc85c4f101d`, path
`tests/Fixtures/ScheduleVisibilityQuery/system-fields.json`. Its SHA-256 is
`23786cdc9d4b784ee85fcc8c787e40949f0876383de72f316449c54f8a7f0f24`.

The 37 cases cover system fields and typed refusals, including adjacent
single-quoted tokens and escaped quote/backslash controls. The unchanged
`native_reference` identifies historical source, not acceptance of these cases.
The Rust consumer applies the existing request-string trim before the pure
parser. HTTP request validation remains a separate layer.

The system-field numeric refusal cases use Note, a text field. Separate native
Count/Weight parser and HTTP/database tests isolate signed-integer overflow and
non-finite decimal refusal for Server #699. The adjacent-token correction is
tracked in Server #1040. A source fixture is not a published-artifact result.
