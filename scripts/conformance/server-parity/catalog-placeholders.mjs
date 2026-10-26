import assert from 'node:assert/strict';

// Exact comparison of recorded catalog and service-call answers, except for
// generated values:
// - "{id}" is a lowercase ULID and "{ULID}" an uppercase one;
// - "{time}" is a whole-second UTC time with an offset, "{µs}" one with six
//   fractional digits and "Z".
// "{kind:NAME}" binds NAME on first sight and must recur exactly; ids bound to
// different names must differ. "@own" is the fixture's namespace, also inside
// other strings.
const FORMATS = {
  id: /^[0-9a-hjkmnp-tv-z]{26}$/,
  ULID: /^[0-9A-HJKMNP-TV-Z]{26}$/,
  time: /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/,
  'µs': /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/,
};
const PLACEHOLDER = /^\{(id|ULID|time|µs)(?::(.+))?\}$/u;
const INLINE = /\{(id|ULID|time|µs)(?::([^{}]+))?\}/gu;

export function catalogContext(own) {
  return {own, bindings: new Map()};
}

export function matchCatalog(expected, actual, context, path) {
  if (typeof expected === 'string') {
    const placeholder = PLACEHOLDER.exec(expected);
    if (placeholder) {
      const [, kind, name] = placeholder;
      assert.ok(typeof actual === 'string' && FORMATS[kind].test(actual), `${path}: ${kind} expected, got ${JSON.stringify(actual)}`);
      bind(context, kind, name, actual, path);
      return;
    }
    const literal = expected.split('@own').join(context.own);
    const inline = [...literal.matchAll(INLINE)];
    if (inline.length === 0) {
      assert.equal(actual, literal, path);
      return;
    }
    // Placeholders inside a message: the literal text must match exactly.
    const escape = text => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    let pattern = '^';
    let at = 0;
    for (const match of inline) {
      pattern += escape(literal.slice(at, match.index)) + `(${FORMATS[match[1]].source.slice(1, -1)})`;
      at = match.index + match[0].length;
    }
    pattern += escape(literal.slice(at)) + '$';
    const found = typeof actual === 'string' ? new RegExp(pattern, 'u').exec(actual) : null;
    assert.ok(found, `${path}: ${JSON.stringify(actual)} does not match ${JSON.stringify(expected)}`);
    inline.forEach((match, index) => bind(context, match[1], match[2], found[index + 1], path));
    return;
  }
  if (Array.isArray(expected)) {
    assert.ok(Array.isArray(actual), `${path}: a list expected, got ${JSON.stringify(actual)}`);
    assert.equal(actual.length, expected.length, `${path}: length`);
    expected.forEach((item, index) => matchCatalog(item, actual[index], context, `${path}[${index}]`));
    return;
  }
  if (expected !== null && typeof expected === 'object') {
    assert.ok(actual !== null && typeof actual === 'object' && !Array.isArray(actual),
      `${path}: an object expected, got ${JSON.stringify(actual)}`);
    assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(expected).sort(), `${path}: fields`);
    for (const key of Object.keys(expected)) {
      matchCatalog(expected[key], actual[key], context, `${path}.${key}`);
    }
    return;
  }
  assert.deepStrictEqual(actual, expected, path);
}

function bind(context, kind, name, actual, path) {
  if (name === undefined) {
    return;
  }
  const key = `${kind}:${name}`;
  if (context.bindings.has(key)) {
    assert.equal(actual, context.bindings.get(key), `${path}: ${key}`);
  } else {
    context.bindings.set(key, actual);
  }
}

/** Ids bound to different names must be different objects. */
export function assertDistinctIds(context, message) {
  for (const kind of ['id', 'ULID']) {
    const ids = [...context.bindings].filter(([name]) => name.startsWith(`${kind}:`)).map(([, value]) => value);
    assert.equal(new Set(ids).size, ids.length, message);
  }
}
