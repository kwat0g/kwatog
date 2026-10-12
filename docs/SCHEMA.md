# Database Schema Reference

This page points to current schema sources; it no longer duplicates table definitions that drift from migrations.

- **Current module inventory and verified relationships:** [dashboard Phase 0, section B](dashboards/phase0/B-modules-data.md). Checked against the migrated `ogami` database on 2026-10-12; it records 261 base tables.
- **Exact columns, types, defaults, constraints, and indexes:** `api/database/migrations/`. Read the latest migrations affecting the table before changing a model or query.
- **Seeded reference data and workflow definitions:** [SEEDS.md](SEEDS.md).

The running database is the deployment result, while migrations define how new installations reach it. If they differ, verify migration order and migration status before updating this reference. Timestamped migrations sort after four-digit migrations, so a four-digit migration cannot depend on a timestamped file.
