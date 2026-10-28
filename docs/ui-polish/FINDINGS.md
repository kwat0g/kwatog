# Functional and accessibility findings

Issues found during the visual audit. Functional fixes stay in focused work units.

| ID | Priority | Finding | Evidence / impact |
|---|---|---|---|
| F-EMP-01 | P1 | Employee status-card drilldowns replace the query string with only `status`, dropping active search, department, position, employment-type, and pay-type filters. The aggregate counts are fetched with those filters, so the destination list can no longer match the count the user clicked. | [employees/index.tsx](../../spa/src/pages/hr/employees/index.tsx#L172) and `#L359` |
| F-EMP-02 | P2 | Employee empty-state logic receives only the search term. A zero result caused by status, department, pay type, or another filter can therefore show first-run copy and an Add Employee action even though the dataset may contain employees. | [employees/index.tsx](../../spa/src/pages/hr/employees/index.tsx#L367), [ListEmptyState.tsx](../../spa/src/components/ui/ListEmptyState.tsx#L33) |
| F-EMP-03 | P2 | Every selectable table row checkbox uses the accessible name “Select row.” Screen-reader users cannot identify which record each checkbox selects. | [DataTable.tsx](../../spa/src/components/ui/DataTable.tsx#L616) |
| F-TOK-01 | P2 | Token audit rejects a palette-invariant white icon over a black photo scrim in ProductPhoto. | [ProductPhoto.tsx](../../spa/src/components/crm/ProductPhoto.tsx#L31); audit gate output |
| F-FC-01 | P1 | Demand forecast chart sorts unpadded year/month keys lexicographically, so months render out of calendar order (for example, January, October, November, December, February). This can misstate the direction of demand and forecast trends. | [demand.tsx](../../spa/src/pages/forecasting/demand.tsx#L228)–[L245](../../spa/src/pages/forecasting/demand.tsx#L245); [screenshot](screenshots/after/chart-routes/light/forecasting-demand-1280.png) |
| F-FC-02 | P1 | Demand outlook uses `Line` inside Recharts `AreaChart`; rendered chart omits the forecast series even though the table alternative contains forecast values. | [demand.tsx](../../spa/src/pages/forecasting/demand.tsx#L335)–[L404](../../spa/src/pages/forecasting/demand.tsx#L404); browser check: zero `.recharts-line-curve` paths and legend contains only “Actual demand” |

F-EMP-01 and F-EMP-02 are addressed by the Employees pilot: tabs preserve existing filters, and
the empty state now names active filters without suggesting a first-run Add Employee action.
F-EMP-03 remains open for a focused follow-up. F-TOK-01 remains open because changing a separate
CRM control is outside this pilot; the token audit therefore remains red.
F-FC-01 and F-FC-02 are logged for separate functional fixes and were not changed during chart styling work.
