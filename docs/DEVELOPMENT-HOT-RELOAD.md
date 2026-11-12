# Full-stack hot reload preview

Use the `hot-spa` Compose profile while working on customer and portal flows.
The browser-facing Vite server is bound to `127.0.0.1:5175`; its API proxy and
Laravel runtime use the isolated E2E services and `ogami_e2e` database. The
preview and E2E Nginx use a dedicated bridge only to make those loopback port
forwards reachable; application traffic still goes through the internal E2E
network, and no production data is reachable from this preview.

Start the guarded E2E stack if it is not already running:

```sh
node scripts/e2e/runner.mjs config
node scripts/e2e/runner.mjs up
node scripts/e2e/runner.mjs wait
```

Start the Vite preview in the same E2E Compose project:

```sh
docker compose \
  --project-name ogami-erp-e2e-session \
  --env-file .env.e2e \
  --file docker-compose.e2e.yml \
  --file docker-compose.hot-dev.yml \
  --profile hot-dev \
  up -d --build hot-spa
```

Open `http://127.0.0.1:5175`. If the server is remote, forward the loopback
ports over SSH (`5175` for the SPA and `18443` for Reverb), then open the local
URL. Demo staff accounts and the shared E2E password are stored in the private
`.env.e2e` and account manifest; do not copy either file into source control.

React/TypeScript edits are served by Vite HMR. PHP edits are read from the
source-mounted E2E API and PHP OPcache checks timestamps every two seconds.
Dependencies are installed during the hot-spa image build and seeded into a
private node_modules volume. Ordinary source edits need no rebuild. When
`package.json` or `package-lock.json` changes, stop the preview, remove its
private dependency volume, then rebuild it so Docker seeds the updated packages:

```sh
docker compose \
  --project-name ogami-erp-e2e-session \
  --env-file .env.e2e \
  --file docker-compose.e2e.yml \
  --file docker-compose.hot-dev.yml \
  --profile hot-dev \
  stop hot-spa
docker volume rm ogami-erp-e2e-hot-dev-spa-node-modules
docker compose \
  --project-name ogami-erp-e2e-session \
  --env-file .env.e2e \
  --file docker-compose.e2e.yml \
  --file docker-compose.hot-dev.yml \
  --profile hot-dev \
  up -d --build hot-spa
```

Stop the preview without removing the E2E database:

```sh
docker compose \
  --project-name ogami-erp-e2e-session \
  --env-file .env.e2e \
  --file docker-compose.e2e.yml \
  --file docker-compose.hot-dev.yml \
  --profile hot-dev \
  stop hot-spa
```

The E2E runner's `test` command resets its isolated database to a seeded
baseline. Stop interactive work before running it; it does not touch production
data. The production app continues to serve its built SPA independently.
