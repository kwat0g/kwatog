#!/bin/sh
set -eu

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=reader_password="$E2E_DB_READER_PASSWORD" <<'SQL'
CREATE ROLE ogami_e2e_reader
    LOGIN PASSWORD :'reader_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT CONNECT ON DATABASE ogami_e2e TO ogami_e2e_reader;
GRANT USAGE ON SCHEMA public TO ogami_e2e_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO ogami_e2e_reader;
ALTER DEFAULT PRIVILEGES FOR ROLE ogami_e2e IN SCHEMA public
    GRANT SELECT ON TABLES TO ogami_e2e_reader;
ALTER ROLE ogami_e2e_reader SET default_transaction_read_only = on;
SQL
