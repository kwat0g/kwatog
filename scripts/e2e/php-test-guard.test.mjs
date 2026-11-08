import assert from 'node:assert/strict';
import test from 'node:test';
import { assertNoActivePhpTest } from './php-test-guard.mjs';

test('allows PHP processes used for migrations and E2E seeding', () => {
  assert.doesNotThrow(() => assertNoActivePhpTest([
    'php artisan migrate:fresh --force --seed',
    'php artisan db:seed --class=Database\\Seeders\\E2EAccountSeeder --force',
  ].join('\n')));
});

test('refuses a Laravel or PHPUnit test process without echoing command arguments', () => {
  for (const processList of [
    'docker compose exec -T api php artisan test --filter=test_example',
    '/usr/bin/php8.3 artisan test --filter=test_example',
    '/usr/local/bin/php vendor/phpunit/phpunit/phpunit --configuration=/var/www/phpunit.xml',
  ]) {
    assert.throws(
      () => assertNoActivePhpTest(processList),
      (error) => error.message === 'A PHP test process is active; refusing to start E2E work.',
    );
  }
});
