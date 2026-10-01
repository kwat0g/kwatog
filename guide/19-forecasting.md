# Forecasting — Demand Guesses, Stock-Out Warnings, Accuracy Scores

**What this module does:** guesses how much customers will order next,
warns which raw materials will run out, and scores how good last
year's guesses were.

**Password for ALL demo accounts:** `password`

| Who | Email | What they can do here |
|---|---|---|
| PPC Head (owner) | `ppc@ogami.test` | Views everything; clicks Recompute and Override |
| Purchasing Officer | `purchasing@ogami.test` | Views; acts on stock-out warnings |
| Finance (viewer) | `finance@ogami.test` | Views only |
| Warehouse (viewer) | `warehouse@ogami.test` | Views only |

**How to open these pages:** they have no sidebar entry.
Type the addresses in sections 2–4 directly into the browser.

## 1. Log in

1. Go to `/sign-in`.
2. Log in as `ppc@ogami.test` (password `password`).
3. Only `ppc@ogami.test` (and the admin) can change forecasts.
   Everyone else in the table above can look but the
   **"Recompute forecast"** button stays disabled for them.

## 2. Demand forecast — the main page (`/forecasting/demand`)

1. Go to `/forecasting/demand`.
2. The title is **Demand & Sales Forecasting**.
3. At the top you see three score cards for the current year:
   **MAPE** (average % error — lower is better),
   **Forecast Bias** (plus = we guessed too low; minus = too high),
   **Periods Evaluated** (how many months had both guess and actual).
4. Under **Forecast scope**, in **Product**, pick a product
   (example: a wiper bushing part number).
5. **Customer (optional)**: leave on All customers for the total,
   or pick one customer (example: Toyota) for their share only.
6. In **Method**, keep the selected method.
7. **Horizon (mo)** = how many months ahead to guess.
   **Lookback (mo)** = how many past months to learn from.
8. Click **"Recompute forecast"** and wait.
9. Read the **Demand history vs. forecast** chart: solid bars are
   real past sales, the other bars are the guess for next months.
10. Read the **Forecast rows** table: Period, Method,
    Forecast qty, Confidence, Actual, Variance.
11. If the row is empty, it says `No forecasts yet — click Recompute`.
12. To fix one month by hand (PPC only): click **"Override"** on
    that row, type the **Forecast quantity**, optionally type
    **Confidence (0–100)**, click **"Save override"**
    (or **"Cancel"** to abandon). Recompute keeps your override.
13. Optional checkbox: **Include forecast in MRP**. Ticking it lets
    planning see the guess. It is advisory only — no purchase request
    is created automatically.

## 3. Stock-out projection — the warning page (`/forecasting/stock-out`)

1. Go to `/forecasting/stock-out`.
2. The title is **Stock-Out Projection**.
3. At the top, a **Horizon** number box sets how many days ahead
   to look (change it and the table reloads).
4. The strip of risk cards counts items by risk level
   (critical / high / medium / low).
5. Read the **Items at risk** table, left to right:
   Item code and name, **On hand**, **Safety** stock,
   **Daily demand**, **Days until stock-out**,
   **Order by** date, **Suggested qty**, **Risk** chip.
6. A red day-count means the material runs out before new stock
   can arrive (days left is shorter than lead time).
7. If the table is empty, it says **All items healthy** —
   nothing runs out inside the horizon. That is good news,
   not a broken page.
8. For the worst row, click **"Create PR"** in the last column.
9. You land on the purchase-request form
   (`/purchasing/purchase-requests/create`) to start buying.
10. Demo line to say: "Purchasing watches
    this page every morning and converts red rows into PRs."

## 4. Accuracy page — the scorecard (`/forecasting/accuracy`)

1. Go to `/forecasting/accuracy`.
2. The title is **Forecast Accuracy**.
3. At the top right, the **Year** dropdown picks which year to score.
4. Three cards summarize the year: **MAPE** (with a verdict —
   Excellent, Acceptable, or Needs improvement),
   **Forecast Bias** (Under-forecasting / Over-forecasting /
   Balanced), **Periods Evaluated**.
5. The **Monthly Forecast vs Actual** chart draws
   Forecast, Actual, and Variance per month.
6. If it says **No reconciled data**, that year has no months with
   both a forecast and actual sales yet — pick another year.
7. The **Accuracy by Product** table lists every product with its
   MAPE %, Bias %, and evaluated periods. Click a column header
   to re-sort (an arrow shows the direction).

## 5. Who does what (one paragraph for the panel)

1. PPC (`ppc@ogami.test`) owns the guess: picks product, method,
   horizon, clicks **"Recompute forecast"**, and types
   **"Override"** values where they know better than the average.
2. Purchasing (`purchasing@ogami.test`) reads
   `/forecasting/stock-out` and turns red rows into purchase
   requests with **"Create PR"**.
3. Finance and Warehouse read all three pages but change nothing.

## 6. If a page fails to load

1. The page shows **Failed to load forecast data** (or
   **Failed to load projections** / **Failed to load accuracy data**).
2. Click **"Retry"**.
3. If it still fails, note it and move on — do not improvise numbers.

## 7. Quick demo script (5 minutes, in order)

1. `/sign-in` as `ppc@ogami.test` → `/forecasting/demand` →
   pick a product → **"Recompute forecast"** → point at the chart.
2. Click **"Override"** on one row → change the quantity →
   **"Save override"**.
3. Go to `/forecasting/stock-out` → point at the reddest row →
   click **"Create PR"** (then press the browser Back button).
4. Go to `/forecasting/accuracy` → read the MAPE card and the
   best/worst product rows.
5. Log in as `purchasing@ogami.test` and show the same three pages
   with **"Recompute forecast"** disabled (view-only).
