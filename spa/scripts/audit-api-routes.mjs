import { spawnSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const spaDirectory = resolve(scriptDirectory, '..');
const repositoryDirectory = resolve(spaDirectory, '..');
const sourceDirectory = join(spaDirectory, 'src');
const scopeManifestPath = join(scriptDirectory, 'api-route-scope-manifest.json');
const requestMethods = new Set(['get', 'post', 'put', 'patch', 'delete']);
const axiosClients = new Set(['client', 'portalClient', 'unwrappingClient', 'publicClient']);

function sourceFiles(directory) {
  return readdirSync(directory).flatMap((name) => {
    const path = join(directory, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
  });
}

function staticPath(expression, constants) {
  if (ts.isStringLiteralLike(expression)) return expression.text;
  if (ts.isIdentifier(expression) && constants.has(expression.text)) {
    return constants.get(expression.text);
  }
  if (!ts.isTemplateExpression(expression)) return null;

  return expression.templateSpans.reduce(
    (path, span) => {
      const value = staticPath(span.expression, constants) ?? '{value}';
      return `${path}${value}${span.literal.text}`;
    },
    expression.head.text,
  );
}

function lineOf(sourceFile, node) {
  return sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1;
}

function collectRequests() {
  const requests = [];

  for (const file of sourceFiles(sourceDirectory)) {
    const content = readFileSync(file, 'utf8');
    const sourceFile = ts.createSourceFile(file, content, ts.ScriptTarget.Latest, true);
    const constants = new Map();

    for (const statement of sourceFile.statements) {
      if (!ts.isVariableStatement(statement)) continue;
      for (const declaration of statement.declarationList.declarations) {
        if (!ts.isIdentifier(declaration.name) || !declaration.initializer) continue;
        const value = staticPath(declaration.initializer, constants);
        if (value !== null) constants.set(declaration.name.text, value);
      }
    }

    function visit(node) {
      if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression)) {
        const { expression, name } = node.expression;
        const clientName = ts.isIdentifier(expression) ? expression.text : null;
        const method = name.text.toLowerCase();

        if (clientName && axiosClients.has(clientName) && requestMethods.has(method) && node.arguments[0]) {
          const path = staticPath(node.arguments[0], constants);
          if (path) {
            const prefix = clientName === 'publicClient' ? '/public/recruitment' : '';
            requests.push({
              method: method.toUpperCase(),
              path: `${prefix}${path}`.replace(/\/+/g, '/').split('?')[0],
              file: relative(repositoryDirectory, file),
              line: lineOf(sourceFile, node),
            });
          }
        }
      }
      ts.forEachChild(node, visit);
    }

    visit(sourceFile);
  }

  return requests;
}

function laravelRoutes() {
  const result = spawnSync(
    'docker',
    ['compose', 'exec', '-T', 'api', 'php', 'artisan', 'route:list', '--json'],
    { cwd: repositoryDirectory, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 },
  );

  if (result.status !== 0) {
    process.stderr.write(result.stderr || result.stdout);
    throw new Error('Unable to read Laravel routes from the running API container.');
  }

  return JSON.parse(result.stdout)
    .filter((route) => route.uri.startsWith('api/v1/'))
    .flatMap((route) => route.method.split('|').map((method) => ({
      method,
      path: `/${route.uri.slice('api/v1/'.length)}`,
    })));
}

function routePattern(path) {
  const escaped = path
    .split('/')
    .map((segment) => {
      if (/^\{[^}]+\??\}$/.test(segment)) {
        return segment.endsWith('?}') ? '[^/]*' : '[^/]+';
      }
      return segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    })
    .join('/');
  return new RegExp(`^${escaped}$`);
}

const requests = collectRequests();
const routes = laravelRoutes();
const missing = requests.filter((request) => !routes.some(
  (route) => route.method === request.method && routePattern(route.path).test(request.path),
));

const scopeManifest = JSON.parse(readFileSync(scopeManifestPath, 'utf8'));
const manifestKey = (entry) => `${entry.method.toUpperCase()} ${entry.path}`;
const classified = new Map(scopeManifest.map((entry) => [manifestKey(entry), entry]));
if (classified.size !== scopeManifest.length) {
  throw new Error('API route scope manifest contains duplicate method/path entries.');
}

const missingKeys = new Set(missing.map(manifestKey));
const staleClassifications = scopeManifest.filter((entry) => !missingKeys.has(manifestKey(entry)));
if (staleClassifications.length > 0) {
  for (const entry of staleClassifications) {
    console.error(`STALE  ${entry.method.padEnd(6)} ${entry.path}`);
  }
  console.error(`\n${staleClassifications.length} scope-manifest entr${staleClassifications.length === 1 ? 'y is' : 'ies are'} no longer an unmatched SPA request.`);
  process.exitCode = 1;
}

const unclassified = missing.filter((request) => !classified.has(manifestKey(request)));

if (unclassified.length > 0) {
  for (const request of unclassified) {
    console.error(`${request.method.padEnd(6)} ${request.path.padEnd(64)} ${request.file}:${request.line}`);
  }
  console.error(`\n${unclassified.length} unmatched SPA request(s) lack a scope-manifest decision.`);
  process.exitCode = 1;
}

if (!process.exitCode) {
  console.log(`${requests.length - missing.length} SPA requests match ${routes.length} Laravel API method/routes.`);
  console.log(`${missing.length} unmatched requests are explicitly classified in api-route-scope-manifest.json.`);
}
