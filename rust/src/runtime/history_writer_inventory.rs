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
//! This test scans every non-test source under `rust/src` for SQL that
//! inserts into, updates, replaces into or deletes from
//! `workflow_history_events`, including table-name macros, and compares
//! the result with `WRITERS`. A new writer fails the test until it is
//! reviewed and listed with the reason it keeps the invariant.
use regex_lite::Regex;
use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

/// One reviewed writer: the source file, how many matching statements it
/// holds, and why each keeps the invariant.
struct Writer {
    file: &'static str,
    statements: usize,
    keeps_invariant: &'static str,
}

const WRITERS: &[Writer] = &[
    Writer {
        file: "runtime/store.rs",
        statements: 2,
        keeps_invariant: "`Store::record` inserts at a sequence from `allocate_history_sequence(s)`, which advances \
            `last_history_sequence` in the same transaction. The worker-terminal attribution `UPDATE` rewrites the \
            terminal event's payload in the transaction that records it, before any answer or tail is read.",
    },
    Writer {
        file: "runtime/local_activities.rs",
        statements: 1,
        keeps_invariant: "`HISTORY_INSERT` inserts at sequences from the same allocator.",
    },
    Writer {
        file: "runtime/workflow_import.rs",
        statements: 1,
        keeps_invariant: "Inserts the history of a run it creates, and sets that run's `last_history_sequence` to at \
            least the largest imported sequence. No worker has cached the run.",
    },
    Writer {
        file: "runtime/waterline_import.rs",
        statements: 1,
        keeps_invariant: "Inserts the history of a run it creates, with sequences 1..n and `last_history_sequence` n. \
            No worker has cached the run.",
    },
    Writer {
        file: "runtime/retention.rs",
        statements: 1,
        keeps_invariant: "Deletes a closed run's whole history. A closed run takes no continuation.",
    },
    Writer {
        file: "runtime/namespace_cleanup.rs",
        statements: 1,
        keeps_invariant: "`by_run!` deletes the history of the runs of a namespace being deleted, with the runs.",
    },
];

/// SQL that writes the history table, and a table-name macro argument
/// (`by_run!("workflow_history_events")`) that generates such SQL.
fn writes() -> [Regex; 2] {
    [
        Regex::new(r#"(?i)\b(INSERT\s+(IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+workflow_history_events\b"#).unwrap(),
        Regex::new(r#"[a-z_]+!\(\s*"workflow_history_events"\s*\)"#).unwrap(),
    ]
}

/// The source with every `#[cfg(test)]` item removed: a module, function,
/// constant, statement or field. The attribute counts only in code, and
/// brace matching skips string, raw string, character and comment contents.
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

/// The index after a string, raw string, character literal or comment that
/// starts at `index`, if one does.
fn literal_end(bytes: &[u8], index: usize) -> Option<usize> {
    let at = |offset: usize| bytes.get(index + offset).copied();
    match bytes[index] {
        b'/' if at(1) == Some(b'/') => Some(bytes[index..].iter().position(|&byte| byte == b'\n').map_or(bytes.len(), |end| index + end)),
        b'/' if at(1) == Some(b'*') => Some(
            bytes[index + 2..].windows(2).position(|pair| pair == b"*/").map_or(bytes.len(), |end| index + 2 + end + 2),
        ),
        b'r' if matches!(at(1), Some(b'"' | b'#')) && !ident(bytes, index) => {
            let hashes = bytes[index + 1..].iter().take_while(|&&byte| byte == b'#').count();
            let open = index + 1 + hashes;
            if bytes.get(open) != Some(&b'"') {
                return None;
            }
            let closing: Vec<u8> = std::iter::once(b'"').chain(std::iter::repeat_n(b'#', hashes)).collect();
            Some(bytes[open + 1..].windows(closing.len()).position(|window| window == closing.as_slice())
                .map_or(bytes.len(), |end| open + 1 + end + closing.len()))
        }
        b'b' if at(1) == Some(b'"') && !ident(bytes, index) => literal_end(bytes, index + 1),
        b'"' => {
            let mut cursor = index + 1;
            while cursor < bytes.len() && bytes[cursor] != b'"' {
                cursor += if bytes[cursor] == b'\\' { 2 } else { 1 };
            }
            Some((cursor + 1).min(bytes.len()))
        }
        // A character literal such as '{' or '\'' (not a lifetime).
        b'\'' if at(1) == Some(b'\\') => {
            let close = bytes[index + 2..].iter().position(|&byte| byte == b'\'').map_or(bytes.len(), |end| index + 2 + end);
            Some((close + 1).min(bytes.len()))
        }
        b'\'' if at(2) == Some(b'\'') => Some(index + 3),
        _ => None,
    }
}

/// The index after the item that starts at `from`: its matching closing
/// brace, or its `;` or `,` when it ends before any brace. An enclosing
/// closing brace also ends it (the last field of a struct literal).
fn item_end(bytes: &[u8], from: usize) -> usize {
    let mut depth = 0usize;
    let mut index = from;
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
                if depth == 0 {
                    return index + 1;
                }
            }
            b')' | b']' => depth -= 1,
            b';' | b',' if depth == 0 => return index + 1,
            _ => {}
        }
        index += 1;
    }
    bytes.len()
}

fn ident(bytes: &[u8], index: usize) -> bool {
    index > 0 && (bytes[index - 1].is_ascii_alphanumeric() || bytes[index - 1] == b'_')
}

/// Module files declared only under `#[cfg(test)]` (`mod name;`).
fn test_modules(dir: &Path, source: &str, found: &mut Vec<PathBuf>) {
    let declaration = Regex::new(r"#\[cfg\(test\)\]\s*(#\[[^\]]*\]\s*)*(pub(\([^)]*\))?\s+)?mod\s+([a-z0-9_]+)\s*;").unwrap();
    for captures in declaration.captures_iter(source) {
        let name = &captures[4];
        found.push(dir.join(format!("{name}.rs")));
        found.push(dir.join(name).join("mod.rs"));
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

/// Matching statements per file, relative to `rust/src`.
fn inventory(root: &Path) -> BTreeMap<String, usize> {
    let mut files = Vec::new();
    sources(root, &mut files);
    let mut excluded = Vec::new();
    for file in &files {
        test_modules(file.parent().unwrap(), &std::fs::read_to_string(file).unwrap(), &mut excluded);
    }
    let patterns = writes();
    let mut found = BTreeMap::new();
    for file in files.iter().filter(|file| !excluded.contains(file)) {
        let source = non_test(&std::fs::read_to_string(file).unwrap());
        let count: usize = patterns.iter().map(|pattern| pattern.find_iter(&source).count()).sum();
        if count > 0 {
            let relative = file.strip_prefix(root).unwrap().to_string_lossy().replace('\\', "/");
            found.insert(relative, count);
        }
    }
    found
}

#[test]
fn every_history_writer_is_reviewed() {
    let found = inventory(&Path::new(env!("CARGO_MANIFEST_DIR")).join("src"));
    let listed: BTreeMap<String, usize> = WRITERS.iter().map(|writer| (writer.file.to_owned(), writer.statements)).collect();
    assert!(WRITERS.iter().all(|writer| !writer.keeps_invariant.is_empty()));
    assert_eq!(
        found, listed,
        "a writer of workflow_history_events changed. Review that it keeps history append-only with \
         last_history_sequence advanced in the same transaction (the v2 continuation prefix proof relies on it), \
         then update WRITERS"
    );
}

#[test]
fn the_scanner_skips_test_items_and_sees_writers() {
    let source = r##"
        const A: &str = "INSERT INTO workflow_history_events(id) VALUES ($1)";
        #[cfg(test)]
        mod tests {
            fn fixture() { let _ = "DELETE FROM workflow_history_events WHERE x='}'"; let _ = '{'; }
            fn raw() { let _ = r#"UPDATE workflow_history_events SET payload='{"a":1}'"#; }
        }
        #[cfg(test)]
        const B: &str = "DELETE FROM workflow_history_events";
        const C: &str = concat!("DELETE FROM ", "workflow_history_events");
        const D: Cleanup = by_run!("workflow_history_events");
        const E: &str = "update  workflow_history_events set payload=$1";
        const F: &str = "SELECT id FROM workflow_history_events";
    "##;
    let kept = non_test(source);
    assert!(kept.contains("const A") && kept.contains("const C") && kept.contains("const F"), "{kept}");
    assert!(!kept.contains("fixture") && !kept.contains("raw()") && !kept.contains("const B"), "{kept}");
    let patterns = writes();
    let count: usize = patterns.iter().map(|pattern| pattern.find_iter(&kept).count()).sum();
    // A, D and E. C splits the statement across literals, which the
    // inventory cannot see; the table-name macro form is how the runtime
    // builds such statements, and it is matched.
    assert_eq!(count, 3, "{kept}");
}
