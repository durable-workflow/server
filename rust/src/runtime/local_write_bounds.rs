//! Bounds for a compiled multi-row INSERT, including its binding overhead.
use std::ops::Range;

pub(super) const ROW_LIMIT: usize = 100;
pub(super) const BYTE_LIMIT: usize = 1024 * 1024;

#[derive(Debug, PartialEq, Eq)]
pub(super) struct Chunk {
    pub(super) rows: Range<usize>,
    // An oversized single row keeps the original single-statement path.
    pub(super) single: bool,
}

pub(super) fn chunks(sizes: &[usize], prefix_bytes: usize) -> Vec<Chunk> {
    assert!(prefix_bytes <= BYTE_LIMIT, "compiled INSERT prefix exceeds its byte bound");
    let mut chunks = Vec::new();
    let mut start = 0;
    while start < sizes.len() {
        let mut end = start;
        let mut bytes = prefix_bytes;
        while end < sizes.len() && end - start < ROW_LIMIT {
            let Some(next) = bytes.checked_add(sizes[end]).filter(|size| *size <= BYTE_LIMIT) else { break; };
            bytes = next;
            end += 1;
        }
        let single = end == start;
        if single { end += 1; }
        chunks.push(Chunk { rows: start..end, single });
        start = end;
    }
    chunks
}

#[cfg(test)]
mod tests {
    use super::*;

    fn preserves(sizes: &[usize], prefix: usize) -> Vec<Chunk> {
        let result = chunks(sizes,prefix);
        assert_eq!(result.iter().flat_map(|chunk| chunk.rows.clone()).collect::<Vec<_>>(),
            (0..sizes.len()).collect::<Vec<_>>());
        for chunk in &result {
            assert!(!chunk.rows.is_empty());
            assert!(chunk.rows.len() <= ROW_LIMIT);
            let bytes = sizes[chunk.rows.clone()].iter().try_fold(prefix,|total,size| total.checked_add(*size));
            if chunk.single {
                assert_eq!(chunk.rows.len(),1);
                assert!(bytes.is_none_or(|bytes| bytes > BYTE_LIMIT));
            } else {
                assert!(bytes.is_some_and(|bytes| bytes <= BYTE_LIMIT));
            }
        }
        result
    }

    #[test]
    fn empty_exact_row_and_exact_byte_boundaries() {
        assert!(preserves(&[],100).is_empty());
        let result = preserves(&vec![0;201],BYTE_LIMIT);
        assert_eq!(result.iter().map(|chunk| chunk.rows.len()).collect::<Vec<_>>(),vec![100,100,1]);
        let result = preserves(&[BYTE_LIMIT-100,1,1],100);
        assert_eq!(result[0].rows,0..1);
        assert!(!result[0].single);
        assert_eq!(result[1].rows,1..3);
    }

    #[test]
    fn large_results_split_by_bytes_before_the_row_limit() {
        let result = preserves(&vec![400_000;40],1024);
        assert_eq!(result.len(),20);
        assert!(result.iter().all(|chunk| chunk.rows.len()==2 && !chunk.single));
    }

    #[test]
    fn oversized_or_overflowing_single_rows_never_enter_a_multi_row_insert() {
        let result = preserves(&[1,BYTE_LIMIT+1,1,usize::MAX,1],128);
        assert_eq!(result.iter().map(|chunk| chunk.single).collect::<Vec<_>>(),vec![false,true,false,true,false]);
    }

    #[test]
    fn mixed_sizes_keep_complete_ordered_coverage() {
        for count in [1,99,100,101,399] {
            let sizes = (0..count).map(|index| match index%7 {0=>BYTE_LIMIT+1,1=>BYTE_LIMIT-256,2=>300_000,_=>17}).collect::<Vec<_>>();
            preserves(&sizes,256);
        }
    }
}
