import {readdirSync} from 'node:fs';
import {resolve} from 'node:path';

// The fixture files one record or comparison covers.
// fixture replaces the reviewed corpus with an explicit set. include adds
// candidates to the corpus. exclude removes members of the corpus that a
// separate, explicitly selected record covers (Server #723).
export function selectFixturePaths({root, directory, fixture = [], include = [], exclude = []}) {
  if (fixture.length && include.length) throw new Error('Choose either --fixture or --include-fixture');
  if (fixture.length && exclude.length) throw new Error('Choose either --fixture or --exclude-fixture');
  const selected = fixture.length ? fixture.map(path => resolve(root, path))
    : [...readdirSync(directory).filter(name => name !== 'php-baseline.json' && name.endsWith('.json')).map(name => resolve(directory, name)),
      ...include.map(path => resolve(root, path))];
  const excluded = exclude.map(path => resolve(root, path));
  for (const path of excluded) {
    if (!selected.includes(path)) throw new Error(`Excluded fixture is not in the selected set: ${path}`);
  }
  return selected.filter(path => !excluded.includes(path));
}
