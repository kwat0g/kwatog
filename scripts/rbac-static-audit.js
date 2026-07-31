const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SEEDER = path.join(ROOT, 'api/database/seeders/RolePermissionSeeder.php');
const seederSource = fs.readFileSync(SEEDER, 'utf8');
const catalogSource = seederSource.slice(0, seederSource.indexOf('private function roleCatalog'));
const catalog = new Set(
  [...catalogSource.matchAll(/'slug'\s*=>\s*'([^']+)'/g)].map((match) => match[1]),
);

function filesUnder(directory, extensions) {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...filesUnder(target, extensions));
    else if (extensions.some((extension) => entry.name.endsWith(extension))) files.push(target);
  }
  return files;
}

const references = new Map();
const unknown = new Map();

function record(permission, file) {
  permission = permission.replace(/[.,;:]+$/, '');
  if (!permission || !permission.includes('.')) return;
  const target = catalog.has(permission) ? references : unknown;
  if (!target.has(permission)) target.set(permission, new Set());
  target.get(permission).add(path.relative(ROOT, file));
}

const spaPatterns = [
  /\bcan\('([^']+)'\)/g,
  /permission="([^"]+)"/g,
  /permission:\s*'([^']+)'/g,
  /anyPermissions:\s*\[([^\]]+)\]/g,
  /anyOf=\{\[([^\]]+)\]\}/g,
];
const spaFiles = filesUnder(path.join(ROOT, 'spa/src'), ['.ts', '.tsx']);
for (const file of spaFiles) {
  if (/\.(?:test|spec)\.[^.]+$/.test(file)) continue;
  const source = fs.readFileSync(file, 'utf8');
  for (const pattern of spaPatterns) {
    for (const match of source.matchAll(pattern)) {
      const values = match[1].match(/[a-z][a-z0-9_.-]+/g) ?? [];
      for (const value of values) record(value, file);
    }
  }
}

const phpPatterns = [
  /permission(?:_any)?:([a-z0-9_.\-,]+)/gi,
  /(?:hasPermission|can|cannot)\(\s*'([^']+)'/g,
];
const phpFiles = filesUnder(path.join(ROOT, 'api/app'), ['.php']);
for (const file of phpFiles) {
  const source = fs.readFileSync(file, 'utf8');
  for (const pattern of phpPatterns) {
    for (const match of source.matchAll(pattern)) {
      for (const value of match[1].split(',')) record(value.trim(), file);
    }
  }
  // Permission constants are commonly passed to hasPermission later.
  for (const match of source.matchAll(/PERMISSION\s*=\s*'([^']+)'/g)) record(match[1], file);
}

// Catch catalog permissions used through constants or lookup maps instead of
// direct can()/middleware calls.
for (const file of [...spaFiles, ...phpFiles]) {
  if (/\.(?:test|spec)\.[^.]+$/.test(file)) continue;
  const source = fs.readFileSync(file, 'utf8');
  for (const permission of catalog) {
    if (source.includes(`'${permission}'`) || source.includes(`"${permission}"`)) {
      record(permission, file);
    }
  }
}

const unused = [...catalog].filter((permission) => !references.has(permission)).sort();
console.log(`Permission catalog: ${catalog.size}`);
console.log(`Statically referenced permissions: ${references.size}`);
console.log(`Referenced but not seeded: ${unknown.size}`);
for (const [permission, files] of [...unknown].sort()) {
  console.log(`  ${permission}: ${[...files].join(', ')}`);
}
console.log(`Seeded without a static enforcement/UI reference: ${unused.length}`);
for (const permission of unused) console.log(`  ${permission}`);

if (unknown.size) process.exitCode = 1;
