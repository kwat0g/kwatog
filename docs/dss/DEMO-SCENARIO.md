# DSS demo scenario

`DssDemoScenarioSeeder` creates one confirmed sales order and one overdue in-progress work order linked to its line, then runs the real D07 detector. It does not insert a recommendation directly or change detector mode.

Use a disposable local PostgreSQL database named `ogami_demo_*` or `ogami_test_*`. The seeder rejects `ogami` and non-local hosts. Create and migrate that database first, then run:

```sh
docker exec -e APP_ENV=local -e DB_DATABASE=ogami_demo_dss -w /var/www ogami-api php artisan db:seed --class='Database\Seeders\DssDemoScenarioSeeder'
```

The output includes the work-order number and detector status. DSS detectors default to shadow, so the Decision Center hides the card until an administrator switches only `dss.work_order_intervention` to advisory in that disposable database. Sign in as a user with `dss.view`, `production.work_orders.view`, and `crm.sales_orders.view` to see both source links.

The scenario shows 60 pcs remaining and a past planned completion date. The Sales Order link appears only when the work order and order-line links agree. The seeder is repeatable, but it does not reset a work order after you complete or otherwise change it; use a fresh disposable database to replay the initial scenario.

The seeder and its isolation guard have a focused feature test. Tests were not run during this implementation.
