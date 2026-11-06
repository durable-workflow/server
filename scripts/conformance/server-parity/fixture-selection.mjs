import {readdirSync} from 'node:fs';
import {basename, resolve} from 'node:path';

// The fixture files one record or comparison covers.
// fixture replaces the reviewed corpus with an explicit set. include adds
// candidates to the corpus. exclude removes members of the corpus that a
// separate, explicitly selected record covers (Server #723).
export function selectFixturePaths({root, directory, fixture = [], include = [], exclude = [], shard}) {
  if (shard !== undefined && shard !== '1/2' && shard !== '2/2') {
    throw new Error('Fixture shard must be exactly 1/2 or 2/2');
  }
  if (fixture.length && include.length) throw new Error('Choose either --fixture or --include-fixture');
  if (fixture.length && exclude.length) throw new Error('Choose either --fixture or --exclude-fixture');
  const selected = fixture.length ? fixture.map(path => resolve(root, path))
    : [...readdirSync(directory).filter(name => name !== 'php-baseline.json' && name.endsWith('.json')).map(name => resolve(directory, name)),
      ...include.map(path => resolve(root, path))];
  const excluded = exclude.map(path => resolve(root, path));
  for (const path of excluded) {
    if (!selected.includes(path)) throw new Error(`Excluded fixture is not in the selected set: ${path}`);
  }
  const paths = selected.filter(path => !excluded.includes(path));
  if (shard === undefined) return paths;
  const byName = new Map(paths.map(path => [basename(path), path]));
  if (byName.size !== paths.length) throw new Error('Selected fixtures require distinct JSON filenames');
  const offset = shard === '1/2' ? 0 : 1;
  const half = [...byName.keys()].sort().filter((_, index) => index % 2 === offset).map(name => byName.get(name));
  if (half.length === 0) throw new Error('Fixture shard must not be empty');
  return half;
}
