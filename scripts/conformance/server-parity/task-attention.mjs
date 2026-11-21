// Historic PHP import recordings omit the optional diagnostic. The import
// scenarios have no repeated lease losses, so keep any populated diagnostic
// in the comparison to catch an unexpected report.
export function withoutEmptyTaskAttention(value) {
  if (Array.isArray(value)) return value.map(withoutEmptyTaskAttention);
  if (!value || typeof value !== 'object') return value;
  const projected = {};
  for (const [key, item] of Object.entries(value)) {
    if (key === 'task_attention' && item === null) continue;
    projected[key] = withoutEmptyTaskAttention(item);
  }
  return projected;
}
