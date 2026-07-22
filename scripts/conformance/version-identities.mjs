const CORE_IDENTIFIER = '(?:0|[1-9]\\d*)';
const PRERELEASE_IDENTIFIER = '(?:0|[1-9]\\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)';
const ROLLING_RELEASE_IDENTIFIERS = new Set([
  'latest', 'current', 'head', 'main', 'master', 'dev', 'snapshot', 'unresolved', 'placeholder',
]);

export const EXACT_SEMVER_RELEASE_PATTERN = new RegExp(
  `^${CORE_IDENTIFIER}\\.${CORE_IDENTIFIER}\\.${CORE_IDENTIFIER}`
  + `(?:-${PRERELEASE_IDENTIFIER}(?:\\.${PRERELEASE_IDENTIFIER})*)?$`,
);

const PYTHON_SEMVER_PRERELEASE_PATTERN = new RegExp(
  `^(${CORE_IDENTIFIER})\\.(${CORE_IDENTIFIER})\\.(${CORE_IDENTIFIER})-(alpha|beta|rc)\\.(${CORE_IDENTIFIER})$`,
  'i',
);
const PYTHON_PEP440_PRERELEASE_PATTERN = new RegExp(
  `^(${CORE_IDENTIFIER})\\.(${CORE_IDENTIFIER})\\.(${CORE_IDENTIFIER})(a|b|rc)(${CORE_IDENTIFIER})$`,
  'i',
);

export function isExactSemverRelease(value) {
  if (typeof value !== 'string' || !EXACT_SEMVER_RELEASE_PATTERN.test(value)) return false;
  const prerelease = value.includes('-') ? value.slice(value.indexOf('-') + 1).split('.') : [];
  return !prerelease.some((identifier) => ROLLING_RELEASE_IDENTIFIERS.has(identifier.toLowerCase()));
}

export function pythonReleaseIdentity(value) {
  if (typeof value !== 'string') return null;
  if (new RegExp(`^${CORE_IDENTIFIER}\\.${CORE_IDENTIFIER}\\.${CORE_IDENTIFIER}$`).test(value)) {
    return value;
  }

  const semver = PYTHON_SEMVER_PRERELEASE_PATTERN.exec(value);
  if (semver) {
    const [, major, minor, patch, prerelease, ordinal] = semver;
    const pep440Prerelease = prerelease.toLowerCase() === 'alpha'
      ? 'a'
      : (prerelease.toLowerCase() === 'beta' ? 'b' : 'rc');
    return `${major}.${minor}.${patch}${pep440Prerelease}${ordinal}`;
  }

  const pep440 = PYTHON_PEP440_PRERELEASE_PATTERN.exec(value);
  if (!pep440) return null;
  const [, major, minor, patch, prerelease, ordinal] = pep440;
  return `${major}.${minor}.${patch}${prerelease.toLowerCase()}${ordinal}`;
}

export function isExactPythonRelease(value) {
  return pythonReleaseIdentity(value) !== null;
}

export function samePythonRelease(expected, observed) {
  const expectedIdentity = pythonReleaseIdentity(expected);
  return expectedIdentity !== null && expectedIdentity === pythonReleaseIdentity(observed);
}
