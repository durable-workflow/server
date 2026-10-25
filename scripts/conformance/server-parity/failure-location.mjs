// The contract line that refused a fixture, as "scripts/conformance/...:line".
// A bare "false !== true" is not locatable from a retained log; the stack
// frame is. Frames carry source paths and line numbers, never payload values.
export function contractFailureLocation(error) {
  for (const frame of String(error?.stack ?? '').split('\n').slice(1)) {
    const match = frame.match(/(scripts\/conformance\/[^\s:()]+\.m?js):(\d+):\d+/);
    if (match) return `${match[1]}:${match[2]}`;
  }
  return null;
}
