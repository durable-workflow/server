//! The reviewed writers of `workflow_history_events` (Server #1181).
//!
//! A version-2 activity continuation proves the worker's cached history
//! prefix in O(1): the run's `last_history_sequence` equals the cached
//! boundary, and the event at that boundary has the cached ID
//! (`docs/contracts/activity-workflow-continuation-v2.md`). That proof is
//! sound only while every writer keeps the history append-only and
//! advances `last_history_sequence` with each insert. A writer that
//! inserts, rewrites or deletes history behind that allocator would make
//! the proof accept a history the worker never saw.
//!
//! The scan reads every string literal in non-test code under `rust/src`
//! that names the table, after joining `\` line continuations, and
//! classifies it:
//! - a write: any `INSERT`, `REPLACE`, `UPDATE`, `DELETE` or `TRUNCATE`
//!   statement naming the table, in any form (`INSERT OR IGNORE`,
//!   `DELETE h FROM`, `UPDATE ... JOIN`, quoted or schema-qualified names).
//!   Each must appear verbatim, with its file, in `WRITES`;
//! - a read: a `SELECT` (or `WITH`) statement with no write keyword. These
//!   cannot change history and are not listed;
//! - anything else, such as the bare table name handed to a macro, a table
//!   list or a constant, or a fragment that `concat!` or `format!` joins
//!   into a statement. Each must appear in `NAMES` with its file and use.
//!
//! So a new writer, a changed write statement and any generated SQL that
//! names the table all fail the test until they are reviewed and listed.
use regex_lite::Regex;
use std::path::{Path, PathBuf};

/// A reviewed statement that writes history, and why it keeps the
/// invariant. `statement` is the literal with whitespace collapsed.
struct Write {
    file: &'static str,
    statement: &'static str,
    keeps_invariant: &'static str,
}

/// A reviewed non-statement literal naming the table: the literal, how many
/// times the file holds it, and what it is used for.
struct Name {
    file: &'static str,
    literal: &'static str,
    count: usize,
    use_: &'static str,
}

const WRITES: &[Write] = &[
    Write {
        file: "runtime/store.rs",
        statement: "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)",
        keeps_invariant: "`Store::record` inserts at a sequence from `allocate_history_sequence(s)`, which advances \
            `last_history_sequence` in the same transaction.",
    },
    Write {
        file: "runtime/store.rs",
        statement: "UPDATE workflow_history_events SET payload=$1 WHERE id=$2",
        keeps_invariant: "The worker-terminal attribution rewrites only the payload of the terminal event this \
            completion recorded, in the same transaction, before it commits or answers.",
    },
    Write {
        file: "runtime/local_activities.rs",
        statement: "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at)",
        keeps_invariant: "`HISTORY_INSERT` inserts at sequences from the same allocator.",
    },
    Write {
        file: "runtime/workflow_import.rs",
        statement: "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at,recorded_at_utc,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)",
        keeps_invariant: "Inserts the history of a run the import creates, and sets that run's \
            `last_history_sequence` to at least the largest imported sequence. No worker has cached the run.",
    },
    Write {
        file: "runtime/waterline_import.rs",
        statement: "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at,recorded_at_utc,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)",
        keeps_invariant: "Inserts sequences 1..n of a run the import creates, with `last_history_sequence` n. \
            No worker has cached the run.",
    },
    Write {
        file: "runtime/retention.rs",
        statement: "DELETE FROM workflow_history_events WHERE workflow_run_id=$1",
        keeps_invariant: "Deletes a run's whole history only after checking, in the same transaction, that the \
            run is closed. A closed run takes no continuation.",
    },
];

const NAMES: &[Name] = &[
    Name {
        file: "runtime/namespace_cleanup.rs",
        literal: "workflow_history_events",
        count: 1,
        use_: "`by_run!` builds the DELETE of the history of a deleted namespace's runs, in the transaction that \
            deletes those runs and their tasks. A deleted run takes no continuation.",
    },
    Name {
        file: "runtime/mysql_checkpoint_catalog.rs",
        literal: "workflow_history_events",
        count: 1,
        use_: "Names the table whose MySQL triggers maintain `dw_child_summary_checkpoints`. The triggers write \
            only the checkpoint table.",
    },
    Name {
        file: "runtime/operator_metrics.rs",
        literal: "workflow_history_events",
        count: 4,
        use_: "One `scoped!` COUNT(*) read and three `not_maintained` report labels.",
    },
    Name {
        file: "runtime/run_observation_store.rs",
        literal: "workflow_history_events",
        count: 1,
        use_: "The `source` label of a run observation.",
    },
    Name {
        file: "runtime/system_metrics.rs",
        literal: "workflow_history_events",
        count: 1,
        use_: "The label of a namespace's history row count, read by the SELECT beside it.",
    },
    Name {
        file: "runtime/workflow_import.rs",
        literal: "workflow_history_events",
        count: 3,
        use_: "The import report's row-count keys (`ROWS` and two `row(...)` lookups).",
    },
];

/// A literal naming the table as a word, quoted or schema-qualified.
fn names_table(literal: &str) -> bool {
    Regex::new(r"(?i)(^|[^a-z0-9_])workflow_history_events($|[^a-z0-9_])").unwrap().is_match(literal)
}

/// A statement keyword that can change rows. A row lock (`FOR UPDATE`,
/// `FOR NO KEY UPDATE`) on a read changes nothing and does not count.
fn writes(literal: &str) -> bool {
    let unlocked = Regex::new(r"(?i)\bFOR\s+(NO\s+KEY\s+)?UPDATE\b").unwrap().replace_all(literal, "");
    Regex::new(r"(?i)\b(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|MERGE|UPSERT)\b").unwrap().is_match(&unlocked)
}

fn reads(literal: &str) -> bool {
    Regex::new(r"(?i)^\s*\(?\s*(SELECT|WITH)\b").unwrap().is_match(literal)
}

/// Collapses whitespace and joins `\` line continuations.
fn normalized(literal: &str) -> String {
    let joined = Regex::new(r"\\\n\s*").unwrap().replace_all(literal, "");
    joined.split_whitespace().collect::<Vec<_>>().join(" ")
}

/// The source with every `#[cfg(test)]` item removed. The attribute counts
/// only in code, and matching skips string, raw string, byte string,
/// character and comment contents.
fn non_test(source: &str) -> String {
    const ATTRIBUTE: &[u8] = b"#[cfg(test)]";
    let bytes = source.as_bytes();
    let mut kept = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if let Some(after) = literal_end(bytes, index) {
            kept.extend_from_slice(&bytes[index..after]);
            index = after;
        } else if bytes[index..].starts_with(ATTRIBUTE) {
            index = item_end(bytes, index + ATTRIBUTE.len());
        } else {
            kept.push(bytes[index]);
            index += 1;
        }
    }
    String::from_utf8(kept).unwrap()
}

/// The index after a string, raw string, byte string, character literal or
/// comment that starts at `index`, if one does.
fn literal_end(bytes: &[u8], index: usize) -> Option<usize> {
    literal_span(bytes, index).map(|(_, end)| end)
}

/// A literal or comment at `index`: whether it is a string literal (with
/// its content range), and the index after it.
fn literal_span(bytes: &[u8], index: usize) -> Option<(Option<(usize, usize)>, usize)> {
    let at = |offset: usize| bytes.get(index + offset).copied();
    match bytes[index] {
        b'/' if at(1) == Some(b'/') => {
            Some((None, bytes[index..].iter().position(|&byte| byte == b'\n').map_or(bytes.len(), |end| index + end)))
        }
        b'/' if at(1) == Some(b'*') => Some((
            None,
            bytes[index + 2..].windows(2).position(|pair| pair == b"*/").map_or(bytes.len(), |end| index + 2 + end + 2),
        )),
        b'r' if matches!(at(1), Some(b'"' | b'#')) && !ident(bytes, index) => raw(bytes, index + 1),
        b'b' if at(1) == Some(b'r') && matches!(at(2), Some(b'"' | b'#')) && !ident(bytes, index) => raw(bytes, index + 2),
        b'b' if at(1) == Some(b'"') && !ident(bytes, index) => literal_span(bytes, index + 1),
        b'"' => {
            let mut cursor = index + 1;
            while cursor < bytes.len() && bytes[cursor] != b'"' {
                cursor += if bytes[cursor] == b'\\' { 2 } else { 1 };
            }
            let end = cursor.min(bytes.len());
            Some((Some((index + 1, end)), (end + 1).min(bytes.len())))
        }
        // An escaped character literal such as '\'' or '\n'; the closing
        // quote comes after the escaped character.
        b'\'' if at(1) == Some(b'\\') => {
            let close = bytes[(index + 3).min(bytes.len())..]
                .iter()
                .position(|&byte| byte == b'\'')
                .map_or(bytes.len(), |end| index + 3 + end);
            Some((None, (close + 1).min(bytes.len())))
        }
        // A plain character literal such as '{' (not a lifetime).
        b'\'' if at(2) == Some(b'\'') => Some((None, index + 3)),
        _ => None,
    }
}

/// A raw string whose `#`s or opening quote start at `from`.
fn raw(bytes: &[u8], from: usize) -> Option<(Option<(usize, usize)>, usize)> {
    let hashes = bytes[from..].iter().take_while(|&&byte| byte == b'#').count();
    let open = from + hashes;
    if bytes.get(open) != Some(&b'"') {
        return None;
    }
    let closing: Vec<u8> = std::iter::once(b'"').chain(std::iter::repeat_n(b'#', hashes)).collect();
    let end = bytes[open + 1..]
        .windows(closing.len())
        .position(|window| window == closing.as_slice())
        .map_or(bytes.len(), |end| open + 1 + end);
    Some((Some((open + 1, end)), (end + closing.len()).min(bytes.len())))
}

/// How a `#[cfg(test)]` item ends, judged by its first words.
#[derive(Clone, Copy, PartialEq)]
enum Kind {
    /// `mod`, `fn`, `impl`, `struct`, ... (also `const unsafe fn`,
    /// `extern "C" fn` and extern blocks): ends at its body's closing brace
    /// or its `;`.
    Body,
    /// `const X`, `static`, `use`, `type`, `let`, `extern crate`: ends only
    /// at its `;`, so generic, where-clause and `if`/`else` braces never end
    /// it early.
    Statement,
    /// Anything else (a block, `unsafe { .. }`, `async move { .. }`,
    /// `const { .. }`, an `if`, a field, a match arm): ends at its first
    /// closed block, a top-level `,` or `;`, or an enclosing closing bracket.
    Expression,
}

fn ident_byte(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || byte == b'_'
}

/// The identifier or number that starts at `at`, or an empty slice.
fn word_at(bytes: &[u8], at: usize) -> &[u8] {
    let rest = bytes.get(at..).unwrap_or_default();
    &rest[..rest.iter().take_while(|&&byte| ident_byte(byte)).count()]
}

fn skip_space(bytes: &[u8], mut at: usize) -> usize {
    while at < bytes.len() && bytes[at].is_ascii_whitespace() {
        at += 1;
    }
    at
}

/// The kind of the item whose first word starts at `from`, after
/// visibility (`pub`, `pub(crate)`) and qualifiers (`async`, `unsafe`,
/// `default`). A qualifier that opens a block (`unsafe {`, `async {`,
/// `async move {`, `const {`) makes a block expression, not an item.
fn item_kind(bytes: &[u8], from: usize) -> Kind {
    let mut cursor = from;
    loop {
        let word = word_at(bytes, cursor);
        let next = skip_space(bytes, cursor + word.len());
        let opens_block = bytes.get(next) == Some(&b'{') || word_at(bytes, next) == b"move";
        match word {
            b"pub" => {
                cursor = next;
                if bytes.get(cursor) == Some(&b'(') {
                    let close = bytes[cursor..].iter().position(|&byte| byte == b')').map_or(bytes.len(), |end| cursor + end);
                    cursor = skip_space(bytes, close + 1);
                }
            }
            b"async" | b"unsafe" | b"const" if opens_block => return Kind::Expression,
            b"async" | b"unsafe" | b"default" => cursor = next,
            // `const unsafe fn`, `const extern "C" fn`, `extern "C" fn`: a
            // function when `fn` comes before the first `(`, `=`, `:` or
            // `{`. Any other `extern` but `extern crate` is an extern block.
            b"const" | b"extern" => {
                let header = bytes[cursor..]
                    .iter()
                    .position(|byte| b"(=:{;".contains(byte))
                    .map_or(bytes.len(), |end| cursor + end);
                let function = bytes[cursor..header].split(|&byte| !ident_byte(byte)).any(|word| word == b"fn");
                return if function || word == b"extern" && word_at(bytes, next) != b"crate" {
                    Kind::Body
                } else {
                    Kind::Statement
                };
            }
            b"mod" | b"fn" | b"impl" | b"struct" | b"enum" | b"trait" | b"union" | b"macro_rules" => return Kind::Body,
            b"static" | b"use" | b"type" | b"let" => return Kind::Statement,
            _ => return Kind::Expression,
        }
    }
}

/// The index after the `#[cfg(test)]` item that starts at `from` (see
/// [`Kind`]).
fn item_end(bytes: &[u8], from: usize) -> usize {
    let mut cursor = from;
    // Skip further attributes and whitespace to find the item's first word.
    // Brackets inside an attribute's string or character literals do not
    // count.
    loop {
        cursor = skip_space(bytes, cursor);
        if !bytes[cursor..].starts_with(b"#[") {
            break;
        }
        let mut depth = 0usize;
        while cursor < bytes.len() {
            if let Some(after) = literal_end(bytes, cursor) {
                cursor = after;
                continue;
            }
            match bytes[cursor] {
                b'[' => depth += 1,
                b']' => {
                    depth -= 1;
                    if depth == 0 {
                        cursor += 1;
                        break;
                    }
                }
                _ => {}
            }
            cursor += 1;
        }
    }
    let kind = item_kind(bytes, cursor);
    let mut depth = 0usize;
    let mut index = cursor;
    while index < bytes.len() {
        if let Some(after) = literal_end(bytes, index) {
            index = after;
            continue;
        }
        match bytes[index] {
            b'{' | b'(' | b'[' => depth += 1,
            b'}' | b')' | b']' if depth == 0 => return index,
            b'}' => {
                depth -= 1;
                // A body item ends with its body; an expression (a block, an
                // `if`, a match arm's block) with its first closed block.
                if depth == 0 && kind != Kind::Statement {
                    return index + 1;
                }
            }
            b')' | b']' => depth -= 1,
            b';' if depth == 0 => return index + 1,
            b',' if depth == 0 && kind == Kind::Expression => return index + 1,
            _ => {}
        }
        index += 1;
    }
    bytes.len()
}

fn ident(bytes: &[u8], index: usize) -> bool {
    index > 0 && ident_byte(bytes[index - 1])
}

/// Every string literal's content in `source`.
fn literals(source: &str) -> Vec<String> {
    let bytes = source.as_bytes();
    let mut found = Vec::new();
    let mut index = 0;
    while index < bytes.len() {
        match literal_span(bytes, index) {
            Some((content, end)) => {
                if let Some((from, to)) = content {
                    found.push(String::from_utf8_lossy(&bytes[from..to]).into_owned());
                }
                index = end;
            }
            None => index += 1,
        }
    }
    found
}

/// Module files declared only under `#[cfg(test)]`. `mod name;` in
/// `dir/file.rs` is `dir/file/name.rs` (or `.../name/mod.rs`); in a
/// `mod.rs`, `lib.rs` or `main.rs` it is `dir/name.rs`. A `#[path]`
/// attribute names the file relative to the declaring file's directory.
fn test_modules(file: &Path, source: &str, found: &mut Vec<PathBuf>) {
    let declaration = Regex::new(
        r#"#\[cfg\(test\)\]\s*((?:#\[[^\]]*\]\s*)*)(?:pub(?:\([^)]*\))?\s+)?mod\s+([a-z0-9_]+)\s*;"#,
    )
    .unwrap();
    let path_attribute = Regex::new(r#"#\[path\s*=\s*"([^"]+)"\s*\]"#).unwrap();
    let dir = file.parent().unwrap();
    let owner = file.file_stem().unwrap().to_string_lossy();
    let base = if matches!(owner.as_ref(), "mod" | "lib" | "main") { dir.to_path_buf() } else { dir.join(owner.as_ref()) };
    for captures in declaration.captures_iter(source) {
        if let Some(path) = path_attribute.captures(&captures[1]) {
            found.push(dir.join(&path[1]));
            continue;
        }
        let name = &captures[2];
        found.push(base.join(format!("{name}.rs")));
        found.push(base.join(name).join("mod.rs"));
    }
}

fn sources(dir: &Path, files: &mut Vec<PathBuf>) {
    for entry in std::fs::read_dir(dir).unwrap() {
        let path = entry.unwrap().path();
        if path.is_dir() {
            sources(&path, files);
        } else if path.extension().is_some_and(|extension| extension == "rs") {
            files.push(path);
        }
    }
}

/// Every non-test literal naming the table that is not a plain read, as
/// `(file, normalized literal, is a write)`, sorted.
fn inventory(root: &Path) -> Vec<(String, String, bool)> {
    let mut files = Vec::new();
    sources(root, &mut files);
    let mut excluded = Vec::new();
    for file in &files {
        test_modules(file, &std::fs::read_to_string(file).unwrap(), &mut excluded);
    }
    let mut found = Vec::new();
    for file in files.iter().filter(|file| !excluded.contains(file)) {
        let relative = file.strip_prefix(root).unwrap().to_string_lossy().replace('\\', "/");
        for literal in literals(&non_test(&std::fs::read_to_string(file).unwrap())) {
            let literal = normalized(&literal);
            if !names_table(&literal) || reads(&literal) && !writes(&literal) {
                continue;
            }
            let write = writes(&literal);
            found.push((relative.clone(), literal, write));
        }
    }
    found.sort();
    found
}

fn listed() -> Vec<(String, String, bool)> {
    let mut listed: Vec<(String, String, bool)> =
        WRITES.iter().map(|write| (write.file.to_owned(), normalized(write.statement), true)).collect();
    for name in NAMES {
        for _ in 0..name.count {
            listed.push((name.file.to_owned(), normalized(name.literal), false));
        }
    }
    listed.sort();
    listed
}

#[test]
fn every_history_writer_is_reviewed() {
    assert!(WRITES.iter().all(|write| !write.keeps_invariant.is_empty() && writes(write.statement)));
    assert!(NAMES.iter().all(|name| !name.use_.is_empty() && name.count > 0 && !writes(name.literal)));
    let found = inventory(&Path::new(env!("CARGO_MANIFEST_DIR")).join("src"));
    let listed = listed();
    let unlisted: Vec<_> = found.iter().filter(|entry| !listed.contains(entry)).collect();
    let stale: Vec<_> = listed.iter().filter(|entry| !found.contains(entry)).collect();
    assert_eq!(
        found, listed,
        "a literal naming workflow_history_events changed.\nunlisted: {unlisted:#?}\nno longer present: {stale:#?}\n\
         Review that every write keeps history append-only with last_history_sequence advanced in the same \
         transaction (the v2 continuation prefix proof relies on it), then update WRITES or NAMES"
    );
}

#[test]
fn the_scanner_skips_test_items_and_classifies_literals() {
    let source = r##"
        const A: &str = "INSERT INTO workflow_history_events(id) VALUES ($1)";
        #[cfg(test)]
        mod tests {
            fn fixture() { let _ = "DELETE FROM workflow_history_events WHERE x='}'"; let _ = '{'; let _ = '\''; }
            fn raw() { let _ = r#"UPDATE workflow_history_events SET payload='{"a":1}'"#; }
        }
        #[cfg(test)]
        fn generic<A, B>(a: A, b: B) where A: Clone, B: Clone { let _ = "DELETE FROM workflow_history_events"; }
        #[cfg(test)]
        const B: &str = "DELETE FROM workflow_history_events";
        #[cfg(test)]
        let c = if true { 1 } else { "TRUNCATE workflow_history_events" };
        const C: &str = concat!("DELETE FROM ", "workflow_history_events WHERE sequence>1");
        const D: Cleanup = by_instance_or_run!("workflow_history_events", "workflow_instance_id");
        const E: &str = "update  workflow_history_events set payload=$1";
        const F: &str = "SELECT id FROM workflow_history_events";
        const G: &str = "INSERT OR IGNORE INTO \"workflow_history_events\" (id) VALUES (1)";
        const H: &str = "DELETE h FROM public.workflow_history_events h JOIN workflow_runs r ON r.id=h.workflow_run_id";
        const I: &str = "UPDATE workflow_runs r JOIN workflow_history_events h ON h.workflow_run_id=r.id SET r.x=1";
        const J: &str = "DELETE FROM \
            workflow_history_events";
        const K: char = '\'';
        const L: &str = "TRUNCATE workflow_history_events";
        const M: &str = "SELECT id FROM workflow_runs WHERE id IN (SELECT workflow_run_id FROM workflow_history_events) FOR UPDATE";
        fn production(gate: bool, kind: u8) -> &'static str {
            #[cfg(test)]
            {
                let _ = "DELETE FROM workflow_history_events WHERE fixture";
            }
            let n = "DELETE FROM workflow_history_events WHERE after_block";
            #[cfg(test)]
            if let Some(x) = Some(1) { let _ = x; }
            let o = "UPDATE workflow_history_events SET after_if=1";
            match kind {
                #[cfg(test)]
                0 => { let _ = "TRUNCATE workflow_history_events"; }
                _ => { let p = "DELETE FROM workflow_history_events WHERE after_arm"; }
            }
            #[cfg(test)]
            unsafe { let _probe = 1; }
            let q = "DELETE FROM workflow_history_events WHERE after_unsafe";
            #[cfg(test)]
            async { let _x = 1; }
            let r = "DELETE FROM workflow_history_events WHERE after_async";
            #[cfg(test)]
            async move { let _y = 1; }
            let s = "DELETE FROM workflow_history_events WHERE after_async_move";
            #[cfg(test)]
            const { assert!(MAX > 0) }
            let t = "DELETE FROM workflow_history_events WHERE after_const_block";
            #[cfg(test)]
            const unsafe fn probe() -> u8 { 1 }
            let u = "DELETE FROM workflow_history_events WHERE after_const_unsafe_fn";
            #[cfg(test)]
            const extern "C" fn probe_c() -> u8 { 1 }
            let v = "DELETE FROM workflow_history_events WHERE after_const_extern_fn";
            #[cfg(test)]
            extern "C" fn probe_e() -> u8 { 1 }
            let w = "DELETE FROM workflow_history_events WHERE after_extern_fn";
            #[cfg(test)]
            #[doc = "] {"]
            fn bracket() { let _ = 1; }
            let x = "DELETE FROM workflow_history_events WHERE after_bracket";
            #[cfg(test)]
            pub(crate) async fn helper() { let _ = "DELETE FROM workflow_history_events WHERE helper"; }
            "INSERT INTO workflow_history_events(id) VALUES ('after_helper')"
        }
    "##;
    let kept = non_test(source);
    for present in [
        "const A", "const C", "const F", "const L", "after_block", "after_if", "after_arm", "after_unsafe", "after_async",
        "after_async_move", "after_const_block", "after_const_unsafe_fn", "after_const_extern_fn", "after_extern_fn",
        "after_bracket", "after_helper",
    ] {
        assert!(kept.contains(present), "{present}: {kept}");
    }
    for absent in [
        "fixture", "raw()", "generic", "const B", "let c", "WHERE helper", "TRUNCATE workflow_history_events\"; }", "_probe",
        "_x", "_y", "MAX > 0", "fn probe", "doc =", "fn bracket",
    ] {
        assert!(!kept.contains(absent), "{absent}: {kept}");
    }
    let classified: Vec<(String, bool)> = literals(&kept)
        .iter()
        .map(|literal| normalized(literal))
        .filter(|literal| names_table(literal) && !(reads(literal) && !writes(literal)))
        .map(|literal| {
            let write = writes(&literal);
            (literal, write)
        })
        .collect();
    let writes_found = classified.iter().filter(|(_, write)| *write).count();
    let names_found: Vec<&str> = classified.iter().filter(|(_, write)| !*write).map(|(literal, _)| literal.as_str()).collect();
    // A, E, G, H, I, J and L are writes, and so are the twelve production
    // literals after the stripped block, `if`, arm, qualified blocks and
    // functions, bracketed attribute and helper. C's
    // fragment and D's bare name are names that must be listed. F, and M
    // with its row lock, are reads.
    assert_eq!(writes_found, 19, "{classified:#?}");
    assert_eq!(names_found, ["workflow_history_events WHERE sequence>1", "workflow_history_events"], "{classified:#?}");
}

#[test]
fn test_only_module_files_resolve_beside_their_declaring_file() {
    let mut found = Vec::new();
    let source = "#[cfg(test)]\nmod checks;\n#[cfg(test)]\n#[path = \"fixtures/extra.rs\"]\nmod extra;\n";
    test_modules(Path::new("src/runtime/store.rs"), source, &mut found);
    assert_eq!(found, [
        PathBuf::from("src/runtime/store/checks.rs"),
        PathBuf::from("src/runtime/store/checks/mod.rs"),
        PathBuf::from("src/runtime/fixtures/extra.rs"),
    ]);
    let mut found = Vec::new();
    test_modules(Path::new("src/runtime/mod.rs"), "#[cfg(test)]\nmod checks;\n", &mut found);
    assert_eq!(found[0], PathBuf::from("src/runtime/checks.rs"));
}
