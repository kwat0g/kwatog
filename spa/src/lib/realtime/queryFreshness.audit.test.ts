import { readdirSync, readFileSync } from 'node:fs';
import { relative, resolve } from 'node:path';
import type { QueryKey } from '@tanstack/react-query';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';
import { dashboardLayoutQueryKeys } from '@/api/dashboard-layout';
import { maintenanceScheduleQueryKeys } from '@/lib/queryKeys/maintenanceSchedules';
import { purchaseOrderQueryKeys } from '@/lib/queryKeys/purchasing';
import { inspectionQueryKeys } from '@/lib/queryKeys/inspections';
import { returnCaseQueryKeys } from '@/lib/queryKeys/returnCases';
import { isRealtimeManagedQueryKey, isStaticReferenceQueryKey } from './queryFreshness';

const sourceRoot = resolve(process.cwd(), 'src');
const auditIdentity = 'freshness-audit:role';
const knownFactoryKeys = new Map<string, QueryKey>([
  ['dashboardLayoutQueryKeys.widgets(dashboardIdentity)', dashboardLayoutQueryKeys.widgets(auditIdentity)],
  ['maintenanceScheduleQueryKeys.options', maintenanceScheduleQueryKeys.options],
  ['purchaseOrderQueryKeys.options', purchaseOrderQueryKeys.options],
  ['inspectionQueryKeys.options', inspectionQueryKeys.options],
  ['inspectionQueryKeys.chain(id)', inspectionQueryKeys.chain('freshness-audit')],
  [
    'returnCaseQueryKeys.sources(realm, cacheScope, type, debouncedSearch)',
    returnCaseQueryKeys.sources('customer', 'customer:freshness-audit', 'delivery', 'freshness-audit'),
  ],
]);

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) return sourceFiles(path);
    return /\.[jt]sx?$/.test(entry.name) && !/\.(test|spec)\.[jt]sx?$/.test(entry.name) ? [path] : [];
  });
}

function property(object: ts.ObjectLiteralExpression, name: string): ts.Expression | undefined {
  const match = object.properties.find((candidate): candidate is ts.PropertyAssignment =>
    ts.isPropertyAssignment(candidate)
    && ts.isIdentifier(candidate.name) && candidate.name.text === name,
  );
  return match?.initializer;
}

function literalValue(expression: ts.Expression, declarations: Map<string, ts.Expression>): unknown {
  if (ts.isStringLiteral(expression) || ts.isNumericLiteral(expression)) {
    return ts.isNumericLiteral(expression) ? Number(expression.text) : expression.text;
  }
  if (expression.kind === ts.SyntaxKind.TrueKeyword) return true;
  if (expression.kind === ts.SyntaxKind.FalseKeyword) return false;
  if (ts.isArrayLiteralExpression(expression)) {
    return expression.elements.map((element) => ts.isSpreadElement(element) ? undefined : literalValue(element, declarations));
  }
  if (ts.isIdentifier(expression)) {
    const declaration = declarations.get(expression.text);
    return declaration ? literalValue(declaration, declarations) : undefined;
  }
  return undefined;
}

function numericValue(expression: ts.Expression, declarations: Map<string, ts.Expression>): number | undefined {
  if (ts.isNumericLiteral(expression)) return Number(expression.text);
  if (ts.isIdentifier(expression)) {
    const declaration = declarations.get(expression.text);
    return declaration ? numericValue(declaration, declarations) : undefined;
  }
  if (ts.isBinaryExpression(expression)) {
    const left = numericValue(expression.left, declarations);
    const right = numericValue(expression.right, declarations);
    if (left === undefined || right === undefined) return undefined;
    if (expression.operatorToken.kind === ts.SyntaxKind.AsteriskToken) return left * right;
    if (expression.operatorToken.kind === ts.SyntaxKind.PlusToken) return left + right;
    if (expression.operatorToken.kind === ts.SyntaxKind.MinusToken) return left - right;
    if (expression.operatorToken.kind === ts.SyntaxKind.SlashToken) return left / right;
  }
  return undefined;
}

describe('query freshness source audit', () => {
  it('classifies every statically discoverable positive staleTime override', () => {
    const overrides: Array<{ file: string; key: unknown[]; classification: string }> = [];
    const unresolved: string[] = [];

    for (const file of sourceFiles(sourceRoot)) {
      const text = readFileSync(file, 'utf8');
      const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
      const declarations = new Map<string, ts.Expression>();
      const collectDeclarations = (node: ts.Node) => {
        if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer) {
          declarations.set(node.name.text, node.initializer);
        }
        ts.forEachChild(node, collectDeclarations);
      };
      collectDeclarations(source);

      const inspect = (node: ts.Node) => {
        if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text === 'useQuery') {
          const options = node.arguments[0];
          if (options && ts.isObjectLiteralExpression(options)) {
            const keyExpression = property(options, 'queryKey');
            const staleExpression = property(options, 'staleTime');
            const staleTime = staleExpression ? numericValue(staleExpression, declarations) : undefined;
            const keyText = keyExpression?.getText(source);
            const queryKey = keyExpression
              ? literalValue(keyExpression, declarations) ?? knownFactoryKeys.get(keyText ?? '')
              : undefined;
            if (staleExpression && staleTime === undefined) {
              unresolved.push(`${relative(sourceRoot, file)}:${source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1} staleTime=${staleExpression.getText(source)}`);
            } else if (staleTime !== undefined && staleTime > 0 && !Array.isArray(queryKey)) {
              unresolved.push(`${relative(sourceRoot, file)}:${source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1} queryKey=${keyExpression?.getText(source) ?? 'missing'}`);
            } else if (staleTime !== undefined && staleTime > 0 && Array.isArray(queryKey)) {
              overrides.push({
                file: relative(sourceRoot, file),
                key: queryKey,
                classification: isRealtimeManagedQueryKey(queryKey as QueryKey)
                  ? 'realtime-managed'
                  : isStaticReferenceQueryKey(queryKey as QueryKey) ? 'reference/options' : 'unmapped-live-or-periodic',
              });
            }
          }
        }
        ts.forEachChild(node, inspect);
      };
      inspect(source);
    }

    const unmapped = overrides.filter((override) => override.classification === 'unmapped-live-or-periodic');
    expect(overrides.length).toBeGreaterThan(0);
    expect(unresolved).toEqual([]);
    expect(unmapped).toEqual([]);
  }, 15_000);
});
