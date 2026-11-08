import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

export async function readOnlyDatabaseJson<T>(query: string): Promise<T> {
  const sql = query.trim();
  if (!/^(select|with)\b/i.test(sql) || /;|--|\/\*/.test(sql)) {
    throw new Error('The E2E database reader accepts one SELECT or WITH query only.');
  }
  const username = process.env.E2E_DB_READER_USERNAME;
  const password = process.env.E2E_DB_READER_PASSWORD;
  if (username !== 'ogami_e2e_reader' || !password) {
    throw new Error('The dedicated read-only E2E database account is unavailable.');
  }

  const statement = `BEGIN TRANSACTION READ ONLY; SELECT COALESCE(json_agg(row_to_json(e2e_rows)), '[]'::json) FROM (${sql}) AS e2e_rows; COMMIT;`;
  const { stdout } = await execFileAsync('psql', [
    '--no-psqlrc', '--quiet', '--tuples-only', '--no-align',
    '--set', 'ON_ERROR_STOP=1',
    '--command', statement,
  ], {
    env: {
      ...process.env,
      PGUSER: username,
      PGPASSWORD: password,
      PGHOST: 'db',
      PGPORT: '5432',
      PGDATABASE: 'ogami_e2e',
      PGSSLMODE: 'disable',
    },
    timeout: 10_000,
    maxBuffer: 4 * 1024 * 1024,
  });
  return JSON.parse(stdout.trim()) as T;
}
