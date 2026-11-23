# Owner Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the three-page owner dashboard (Overview, Supplies, Profit & costs) with one calendar date filter, four read-only endpoints, and the POS write-path changes those numbers depend on.

**Architecture:** One value object (`Period`) validates `from/to/branch` and owns open days and chart buckets. One stats class per page computes everything on the server from raw rows; one thin controller exposes them as JSON and serves three Blade pages that only fetch and render. The existing `/dashboard` (Today / Supplies / Monthly costs) is left untouched and keeps serving staff; the new pages are admin-only and sit beside it.

**Tech Stack:** Laravel (PHP), Blade, Alpine.js, Chart.js 4, flatpickr 4, Tailwind 4, PHPUnit on in-memory SQLite. All already installed — add no dependencies.

**Spec:** `/home/mark/Downloads/Cane-Cotton-Owner-Dashboard-Developer-Spec-Final.pdf` (21 pages; section numbers below refer to it).

## Global Constraints

- Filter inputs are exactly `from`, `to` (inclusive, `YYYY-MM-DD`) and `branch` (`all` or a branch id). Nothing else.
- Time zone `Asia/Manila` (already `config('app.timezone')`; the DB stores local time, so a record's business date is its local date).
- Each table is filtered by one date field only (see "Name mapping"). Never mix date fields in one widget.
- Averages per day divide by **open days** (distinct `z_readings.business_date` in range for the branch scope), never calendar days. Zero open days → average is `0`, not an error.
- Buckets: range ≤ 31 days → `day`; 32–120 → `week` (Saturday to Friday, clipped to the range); > 120 → `month`.
- No wording tied to a fixed period ("today", "this month", "last 7 days", "vs last week") anywhere on the new pages.
- Endpoints return money as numbers in pesos rounded to 2 decimals; pages display whole pesos. Percentages are whole numbers, except profit % (1 decimal). Shares on one chart add to 100: round the largest part last.
- The page never adds or divides — every displayed figure comes from the endpoint.
- Admin only (`User::isAdmin()`): everyone else gets 403 on pages and endpoints.
- Cancelled (`status = cancelled`) and soft-deleted orders are excluded from every total.
- Expenses count only when `drawer_status` is `none` or `confirmed` (`pending` and `disputed` are not counted anywhere).
- Voided payments (`voided_at` not null) are never counted.
- Supplies in mL or pcs only.
- Tests only: run `php artisan test`. Do not launch the app locally (data lives in prod).

## Name mapping (spec name → this codebase)

| Spec | Here | Date field |
|---|---|---|
| `orders` | `job_orders` | `created_at` |
| `order_items` (services) | `job_order_items` whose service category has `is_addon = false` (or no service) | order's `created_at` |
| `order_addons` | `job_order_items` whose service's `laundry_service_categories.is_addon = true` | order's `created_at` |
| `amount`, `billed_qty` | `job_order_items.total`, `job_order_items.quantity` | — |
| `payments.method` | `payments.payment_type` in `cash`, `gcash`, `bank`; branch = `collected_branch_id` | `paid_at` |
| `expenses` | `branch_expenses` | `expense_date` |
| `bills` | `branch_expenses` rows with `bill_type` not null (Task 4) | `period_start`–`period_end` |
| other purchases | `branch_expenses` with `bill_type` null and `expense_type` not in `payroll`, `supplies`, `inventory_purchase` | `expense_date` |
| `shifts` | `employee_attendance_records` (present = `clock_in` not empty); scheduled = `schedule_shifts` with `template` not in `rest`, `leave` | `work_date` |
| `stock_items`, `service_recipes` | `inventories`, `service_inventory_usages` | — |
| `stock_movements` | `inventory_movements`: use = `out`; purchase = `in` with `reason` null; adjust = `adjustment`, or any row with `reason` not null | `created_at` |
| `closings`, `machine_counters` | `z_readings` and its `machine_counters` JSON | `business_date` |
| `cash_difference`, `gcash_difference` | `cash_diff`, `gcash_diff` | — |
| `envelope_amount`, `shop_cash_left`, `gcash_sent_to_bank` | `deposit_bag_amount`, `float_for_tomorrow`, `gcash_transfer_amount` | — |

## Decisions and deliberate deviations

- **Money stays `decimal(12,2)` pesos**, not centavos. Converting storage touches every money column for no visible change.
- **No `daily_stats` summary table.** The spec only recommends it; add it when a real range loads slowly.
- **No weekly payroll-run screen.** Staff cost comes from attendance by `work_date`; salary payments keep using the existing salary-expense entry. Spec test 3 is met by computing staff cost for the Saturday–Friday range.
- **Bills count once entered as an expense.** Unpaid payables in `accounts_payables` are not spread into profit.
- **Sales = sum of line totals**, so an order-level `discount` is not subtracted from Sales (it does reduce `total`, and so "still unpaid").
- **Old data:** movements before Task 2 get today's `unit_cost` and no reason; old `adjustment` rows get `change_qty = 0`; orders before Task 3 get an estimated `kg_weighed`.
- **Editable owner settings (spec section 10):** recipe amounts and item costs already have screens; Task 4 and 5 add bill period and employer share rate (default 14.5%, the ratio in the spec mockup).

## File structure

| File | Responsibility |
|---|---|
| `app/Support/OwnerDashboard/Period.php` | Validate filter; open days; bucket rule; bucket list |
| `app/Support/OwnerDashboard/Shares.php` | Whole-number percentages that add to 100 |
| `app/Support/OwnerDashboard/OverviewStats.php` | Page 1 payload |
| `app/Support/OwnerDashboard/ClosingView.php` | One Z-Reading as the dashboard shows it; status and "what to check" message |
| `app/Support/OwnerDashboard/SuppliesStats.php` | Page 2 payload |
| `app/Support/OwnerDashboard/ProfitStats.php` | Page 3 payload; staff, bills, supplies, other cost lines |
| `app/Http/Controllers/Admin/OwnerDashboardController.php` | 3 pages + 4 JSON endpoints |
| `resources/views/admin/owner-dashboard/{layout,overview,supplies,profit}.blade.php` | Pages |
| `resources/js/owner-dashboard.js` | Calendar, fetch, render |
| `tests/Feature/OwnerDashboard/*Test.php`, `tests/Feature/OwnerDashboard/SeedsOwnerDashboard.php` | Tests and shared fixture trait |

## Review Focus

1. **Range with no data at all** (new branch, zero orders, zero closings): every endpoint returns 200 with zeros; no division by zero. → Tasks 6, 8, 9.
2. **Admin asks for a branch id that does not exist or is inactive**: 400, not an empty 200. → Task 1.
3. **Stock movement recorded before the cost column existed** (`unit_cost` null): valued at the item's current `unit_cost`, never 0. → Task 8.
4. **Employee with no `daily_rate`**: counts as present with ₱0 wages and still appears in the table; no crash. → Task 9.
5. **Bill whose period starts or ends outside the range, or with `period_end` before `period_start`**: overlap days only; the invalid period is rejected at the expense form. → Tasks 4, 9.

---

### Task 1: Period and filter validation

**Files:**
- Create: `app/Support/OwnerDashboard/Period.php`
- Test: `tests/Feature/OwnerDashboard/PeriodTest.php`

**Interfaces:**
- Produces:
  - `Period::fromRequest(Request $request): Period` — aborts 400 on a bad filter.
  - `Period::make(string $from, string $to, ?int $branchId): Period`
  - public readonly: `string $from`, `string $to`, `?int $branchId` (null = all), `int $calendarDays`, `int $openDays`, `string $bucket` (`day|week|month`)
  - `buckets(): array` — list of `['label' => string, 'start' => 'Y-m-d', 'end' => 'Y-m-d']`
  - `openDates(): array` — list of `'Y-m-d'`
  - `perOpenDay(float $total): float` — `0.0` when `openDays` is 0
  - `toArray(): array` — `['from','to','calendar_days','open_days','bucket','branch']`, `branch` is `'all'` or the id

- [ ] **Step 1: Write the failing tests**

Travel to `2026-11-23 10:00`. Assert:

```php
// bucket rule
$this->assertSame('day',   Period::make('2026-09-01', '2026-10-01', null)->bucket);  // 31 days
$this->assertSame('week',  Period::make('2026-09-01', '2026-10-02', null)->bucket);  // 32 days
$this->assertSame('week',  Period::make('2026-06-01', '2026-09-28', null)->bucket);  // 120 days
$this->assertSame('month', Period::make('2026-06-01', '2026-09-29', null)->bucket);  // 121 days

// weeks run Saturday to Friday, clipped to the range (Sep 21 2026 is a Monday)
$b = Period::make('2026-09-21', '2026-10-30', null)->buckets();
$this->assertSame(['2026-09-21', '2026-09-25'], [$b[0]['start'], $b[0]['end']]);
$this->assertSame(['2026-09-26', '2026-10-02'], [$b[1]['start'], $b[1]['end']]);

// open days = distinct z_readings.business_date in range; a day with no closing is closed
// (create readings for Sep 1 and Sep 3 on branch A, Sep 3 on branch B)
$this->assertSame(2, Period::make('2026-09-01', '2026-09-03', null)->openDays);
$this->assertSame(1, Period::make('2026-09-01', '2026-09-03', $branchB->id)->openDays);
$this->assertSame(0.0, Period::make('2026-08-01', '2026-08-02', null)->perOpenDay(500));
```

`fromRequest` returns HTTP 400 for: `to` before `from`; `to` after today; malformed date; `branch=999999`; an inactive branch. Missing `from`/`to` defaults to the 1st of the current month through today; missing `branch` means `all`.

- [ ] **Step 2: Run** `php artisan test --filter=PeriodTest` — expect FAIL (class not found).
- [ ] **Step 3: Implement `Period`.** Monthly bucket labels `M Y`; weekly `M j–j`; daily `j`.
- [ ] **Step 4: Run** the same command — expect PASS.
- [ ] **Step 5: Commit** `feat: owner dashboard period and filter validation`

---

### Task 2: Stock movements carry cost, signed quantity and reason

**Files:**
- Create: `database/migrations/2026_11_23_000001_add_cost_and_reason_to_inventory_movements.php`
- Modify: `app/Models/InventoryMovement.php`, `app/Http/Controllers/Admin/InventoryController.php` (the three `movements()->create` sites near lines 112, 145, 208), `app/Http/Controllers/Admin/JobOrderController.php` (`cancel()` at 849, deduct near 1234, `restoreInventoryForOrder` at 1272), the inventory adjustment form view
- Test: `tests/Feature/OwnerDashboard/StockMovementRulesTest.php`

**Interfaces:**
- Produces columns on `inventory_movements`: `unit_cost decimal(12,4) null`, `change_qty decimal(12,4) null` (signed change to stock: `in` +, `out` −, `adjustment` = new − old), `reason string(30) null` (`count|spill|cancelled_order|other`).
- Produces `InventoryMovement::REASONS = ['count', 'spill', 'cancelled_order', 'other']`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_every_new_movement_stores_cost_and_signed_change(): void
// item unit_cost 0.06, stock 1000: stock-in 500 → change_qty 500, unit_cost 0.06
// stock-out 200 → change_qty -200; adjustment to 1200 from 1300 → change_qty -100

public function test_manual_adjustment_requires_a_reason(): void
// POST adjustment without reason → session error on 'reason'; with reason=spill → saved

public function test_cancelling_an_order_returns_its_supplies_as_a_positive_adjustment(): void
// order that deducted 60 mL detergent → cancel → one new movement:
// movement_type 'in', reason 'cancelled_order', change_qty 60; stock back to the original

public function test_cancelling_twice_or_cancelling_an_undeducted_order_writes_nothing(): void
```

- [ ] **Step 2: Run** `php artisan test --filter=StockMovementRulesTest` — expect FAIL.
- [ ] **Step 3: Migration.** Add the three columns. Backfill: `unit_cost` from `inventories.unit_cost`; `change_qty` = `quantity` for `in`, `-quantity` for `out`, `0` for `adjustment`; `reason = 'cancelled_order'` where `remarks LIKE 'Auto restored for %'`.
- [ ] **Step 4: Write paths.** Every `movements()->create` sets `unit_cost` and `change_qty`. `restoreInventoryForOrder` sets `reason => 'cancelled_order'`. `cancel()` calls `restoreInventoryForOrder` inside a transaction when `inventory_deducted_at` is set, then clears it. Manual `adjustment` validates `reason` with `Rule::in(InventoryMovement::REASONS)`; add the select to the form.
- [ ] **Step 5: Run** the filter, then `php artisan test --filter=Inventory` — expect PASS (existing inventory tests still green).
- [ ] **Step 6: Commit** `feat: cost, signed quantity and reason on stock movements`

---

### Task 3: Orders record kilos, intake type and return type

**Files:**
- Create: `database/migrations/2026_11_23_000002_add_dashboard_fields_to_job_orders.php`
- Modify: `app/Models/JobOrder.php`, `app/Http/Controllers/Admin/JobOrderController.php` (store, update near 712, status update near 764)
- Test: `tests/Feature/OwnerDashboard/OrderDashboardFieldsTest.php`

**Interfaces:**
- Produces columns on `job_orders`: `kg_weighed decimal(8,2) default 0`, `intake_type string(20) default 'walk_in'` (`walk_in|house_pickup`), `return_type string(20) default 'shop_pickup'` (`shop_pickup|delivery`).
- Produces `JobOrder::kgFromItems(iterable $items): float` — sum of `quantity` for `kilo` services plus `quantity × kilos_per_load` for `load` services; other pricing types add 0.

- [ ] **Step 1: Write the failing tests**

```php
public function test_saving_an_order_stores_kilos_from_its_lines(): void
// 6.5 kg Regular (kilo) + 2 loads Bed Sheets (load, kilos_per_load 8) + 3 pcs → kg_weighed 22.50

public function test_order_created_from_a_pickup_booking_is_house_pickup(): void
public function test_walk_in_order_is_walk_in(): void
public function test_moving_to_ready_for_delivery_sets_return_type_delivery(): void
public function test_moving_to_ready_for_pickup_sets_return_type_shop_pickup(): void
```

- [ ] **Step 2: Run** `php artisan test --filter=OrderDashboardFieldsTest` — expect FAIL.
- [ ] **Step 3: Migration + backfill.** `intake_type = 'house_pickup'` where a `pickup_requests.job_order_id` points at the order; `return_type = 'delivery'` where `transaction_type = 'delivery'`; `kg_weighed` via the same rule as `kgFromItems` in chunked PHP (SQLite-safe).
- [ ] **Step 4: Write paths.** Store and update set `kg_weighed`; store sets `intake_type`; the status change sets `return_type`. Add: `// shortcut: kilos for load-priced lines are estimated from kilos_per_load, add a weighed-kg input if the owner needs exact kilos`.
- [ ] **Step 5: Run** the filter, then `php artisan test --filter=JobOrder` — expect PASS.
- [ ] **Step 6: Commit** `feat: kilos, intake and return type on job orders`

---

### Task 4: Bills carry a type and a billing period

**Files:**
- Create: `database/migrations/2026_11_23_000003_add_bill_fields_to_branch_expenses.php`
- Modify: `app/Models/BranchExpense.php`, `app/Http/Controllers/Admin/ExpenseController.php` (validation near 289, save near 254 and 337), `resources/views/admin/expenses/` form
- Test: `tests/Feature/OwnerDashboard/BillFieldsTest.php`

**Interfaces:**
- Produces columns on `branch_expenses`: `bill_type string(20) null`, `period_start date null`, `period_end date null`.
- Produces `BranchExpense::BILL_TYPES = ['rent' => 'Rent', 'electricity' => 'Electricity', 'lpg' => 'LPG (dryers)', 'water' => 'Water', 'wifi' => 'WiFi', 'taxes' => 'Taxes (BIR)', 'cell_phone' => 'Cell phone']`.
- Produces `BranchExpense::scopeCounted($query)` — `whereIn('drawer_status', ['none', 'confirmed'])`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_bill_needs_a_type_and_a_period(): void
// expense_type rent without bill_type/period → errors on bill_type, period_start, period_end

public function test_period_end_before_period_start_is_rejected(): void

public function test_non_bill_expense_saves_without_bill_fields(): void
// expense_type 'other' → bill_type, period_start, period_end all null

public function test_counted_scope_excludes_pending_and_disputed(): void
```

- [ ] **Step 2: Run** `php artisan test --filter=BillFieldsTest` — expect FAIL.
- [ ] **Step 3: Migration + backfill.** For existing rows with `expense_type` in `rent`, `utilities`, `government_fees`: period = the calendar month of `expense_date`; `bill_type` = `rent`, `taxes`, or for utilities by title/category containing `water` → `water`, `lpg|gas` → `lpg`, `wifi|internet` → `wifi`, `phone|load` → `cell_phone`, otherwise `electricity` (the same matching the old dashboard used at `DashboardController.php:1268`).
- [ ] **Step 4: Form + validation.** Bill fields are required when `expense_type` is `rent`, `utilities` or `government_fees`, and nulled otherwise. Period defaults in the form to the month of `expense_date`.
- [ ] **Step 5: Run** the filter, then `php artisan test --filter=Expense` — expect PASS.
- [ ] **Step 6: Commit** `feat: bill type and billing period on expenses`

---

### Task 5: Employer share rate per employee

**Files:**
- Create: `database/migrations/2026_11_23_000004_add_employer_share_rate_to_attendance_employees.php`
- Modify: `app/Models/AttendanceEmployee.php`, `app/Http/Controllers/Admin/EmployeeController.php`, the employee form view
- Test: `tests/Feature/OwnerDashboard/EmployerShareRateTest.php`

**Interfaces:**
- Produces `attendance_employees.employer_share_rate decimal(5,2) default 14.50` (percent of wages covering SSS, PhilHealth, Pag-IBIG), cast `decimal:2`, validated `numeric|min:0|max:100`.

- [ ] **Step 1: Failing test** — `test_admin_can_set_employer_share_rate`: update an employee with `employer_share_rate=12.5` → stored `12.50`; `101` → validation error; a new employee defaults to `14.50`.
- [ ] **Step 2: Run** `php artisan test --filter=EmployerShareRateTest` — FAIL.
- [ ] **Step 3: Implement** migration, fillable/cast, validation rule, form field labelled "Employer share (% of wages)".
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: employer share rate per employee`

---

### Task 6: Overview endpoint

**Files:**
- Create: `app/Support/OwnerDashboard/Shares.php`, `app/Support/OwnerDashboard/OverviewStats.php`, `app/Http/Controllers/Admin/OwnerDashboardController.php`, `tests/Feature/OwnerDashboard/SeedsOwnerDashboard.php`
- Modify: `routes/web.php` (inside the existing authenticated group, next to the `/dashboard` routes at line 220)
- Test: `tests/Feature/OwnerDashboard/OverviewEndpointTest.php`

**Interfaces:**
- Consumes: `Period` (Task 1); `job_orders.kg_weighed|intake_type|return_type` (Task 3); `ClosingView` is added in Task 7 — until then `closings` returns the summary only.
- Produces:
  - `Shares::percentages(array $amounts): array` — same keys, whole numbers summing to 100 (all zeros when the total is 0); the largest part absorbs the rounding remainder.
  - `OverviewStats::for(Period $period): array`
  - `OverviewStats::sales(Period $period): float`, `::orders(Period $period): int`, `::kg(Period $period): float` — reused by Tasks 8 and 9.
  - Route `GET /api/dashboard/overview` named `owner-dashboard.api.overview`, admin only.
  - Test trait `SeedsOwnerDashboard` with: `seedBranch(string $name): Branch`, `admin(): User`, `service(string $name, string $pricingType, float $price, bool $addon = false): LaundryService`, `order(Branch $b, string $dateTime, array $lines, array $attrs = []): JobOrder` (`$lines` = list of `[LaundryService, qty]`), `pay(JobOrder $o, string $dateTime, string $type, float $amount): Payment`, `closing(Branch $b, string $date, array $attrs = []): ZReading`.

Response shape:

```json
{
  "period": {"from": "", "to": "", "calendar_days": 0, "open_days": 0, "bucket": "day", "branch": "all"},
  "kpis": {"sales": 0, "sales_per_day": 0, "orders": 0, "avg_order": 0, "orders_per_day": 0,
           "kg_washed": 0, "kg_per_order": 0, "kg_per_day": 0, "profit": 0, "profit_pct": 0},
  "payments": {"total": 0, "methods": [{"method": "cash", "amount": 0, "pct": 0}]},
  "unpaid": 0,
  "intake": {"walk_in": {"count": 0, "pct": 0}, "house_pickup": {"count": 0, "pct": 0},
             "shop_pickup": {"count": 0, "pct": 0}, "delivery": {"count": 0, "pct": 0}, "ebike_trips": 0},
  "closings": {"summary": {"count": 0, "balanced": 0, "with_difference": 0, "cash_over_short": 0,
               "cash_deposited": 0, "gcash_sent_to_bank": 0}, "unbalanced": [], "full": null},
  "busiest": {"mode": "weekday", "rows": []},
  "services": {"services_total": 0, "addons_total": 0, "rows": [], "top": {"name": "", "pct": 0}}
}
```

`busiest.rows` in `weekday` mode: 7 rows Sun–Sat, each `{day, in_range, before_noon, after_noon, total, kg, peak}` where the averages divide by the number of that weekday that are open days in the range, `peak` is the busiest 2-hour window label (`"8–10 AM"`) by `created_at` hour within opening hours (Mon–Sat 8–19, Sun 10–17), and `in_range: false` marks a weekday that never occurs. When `from = to`, `mode` is `hourly` and rows are `{hour, orders}` for that day. `kpis.profit` and `profit_pct` are `0` until Task 9 wires them.

- [ ] **Step 1: Write the failing tests**

Fixture: branch A, travel to `2026-11-23`. Regular Laundry ₱30/kg, Bed Sheets ₱175/load, Ariel add-on ₱20.
Order 1 — Sep 28 09:15, 10 kg Regular + 1 Ariel (₱320), walk-in, shop pickup, paid ₱320 cash Sep 28.
Order 2 — Sep 30 14:30, 5 kg Regular (₱150), house pickup, delivery, paid ₱100 GCash **Oct 1**.
Order 3 — Sep 29, 4 kg Regular, **cancelled**.
Closings on Sep 28 and Sep 30 (Sep 29 is closed).

```php
public function test_kpis_for_the_range(): void
// GET ?from=2026-09-28&to=2026-09-30&branch=all
// kpis.sales 470, orders 2, avg_order 235, kg_washed 15, period.open_days 2, sales_per_day 235

public function test_sale_counts_on_order_date_and_payment_on_payment_date(): void   // spec test 6
// Sep range: payments.total 320 (cash only). ?from=2026-10-01&to=2026-10-01: sales 0, payments.total 100 (gcash)

public function test_still_unpaid_is_total_minus_paid_for_orders_created_in_range(): void
// Sep range: unpaid 50

public function test_cancelled_orders_are_in_no_total(): void                        // spec test 7 (sales half)
public function test_voided_payment_is_not_counted(): void
public function test_intake_and_return_tiles_and_ebike_trips(): void
// walk_in 1 (50%), house_pickup 1 (50%), shop_pickup 1, delivery 1, ebike_trips 2

public function test_services_sorted_by_amount_with_addons_total_and_top_service(): void
// rows[0].name 'Regular Laundry', qty 15, amount 450; addons_total 20; top.pct 96

public function test_single_day_switches_busiest_to_hourly(): void                   // spec test 2 (chart half)
// ?from=2026-09-28&to=2026-09-28 → busiest.mode 'hourly', row for hour 9 has orders 1

public function test_closed_day_does_not_change_averages(): void                     // spec test 10
public function test_all_branches_equals_sum_of_branches(): void                     // spec test 11
public function test_empty_range_returns_zeros(): void                               // Review Focus 1
public function test_non_admin_gets_403_and_bad_range_gets_400(): void               // spec test 14
public function test_shares_add_to_100(): void
// Shares::percentages(['a' => 1, 'b' => 1, 'c' => 1]) sums to 100
```

- [ ] **Step 2: Run** `php artisan test --filter=OverviewEndpointTest` — expect FAIL.
- [ ] **Step 3: Implement** `Shares`, `OverviewStats`, the controller method `overview(Request)` and the route. Filter orders with `whereBetween('created_at', [from 00:00:00, to 23:59:59])` so the index is used. Group by hour in PHP, not SQL (the tests run on SQLite, production on MySQL).
- [ ] **Step 4: Run** — expect PASS.
- [ ] **Step 5: Commit** `feat: owner dashboard overview endpoint`

---

### Task 7: Closing view and closing endpoint

**Files:**
- Create: `app/Support/OwnerDashboard/ClosingView.php`
- Modify: `app/Support/OwnerDashboard/OverviewStats.php`, `app/Http/Controllers/Admin/OwnerDashboardController.php`, `routes/web.php`
- Test: `tests/Feature/OwnerDashboard/ClosingEndpointTest.php`

**Interfaces:**
- Consumes: `ZReading` (`cash_diff`, `gcash_diff`, `deposit_diff`, `deposit_bag_amount`, `float_for_tomorrow`, `gcash_transfer_amount`, `closed_by`, `machine_counters` shaped `[machine => ['wash' => row, 'dry' => row]]`, each row carrying `unexplained_cycles` and `reconciliation_status` as written by `ZReadingController::machineRow`).
- Produces:
  - `ClosingView::from(ZReading $reading): array` — `{date, branch, closed_by, status, shop_cash: {expected, counted, difference}, gcash: {expected, counted, difference}, envelope_amount, shop_cash_left, gcash_sent_to_bank, machines: [{machine_no, type, booked, counted, difference}], failed_checks: [string], what_to_check: string}`
  - `status` is `balanced` only when cash, GCash and deposit differences are all 0 **and** no machine row has `reconciliation_status` in `off`, `end_low`, `counter_low`; otherwise `difference`.
  - Route `GET /api/dashboard/closing?date=&branch=` named `owner-dashboard.api.closing` → `{closings: [ClosingView...]}` (one per branch for `all`). Same validation as `Period` for the date.
  - Overview `closings.unbalanced` = `ClosingView` rows with `status = difference`, newest first; `closings.full` = that day's views when `from = to`, and `unbalanced` is then `[]`.

Message rules (exact copy): cash short → `"Cash ₱{n} short — recount, check expenses"`; cash over → `"Cash ₱{n} over — recount, check payments"`; GCash → `"GCash ₱{n} {short|over} — check the app balance"`; machine with more cycles than booked → `"{Washer|Dryer} #{n}: {k} cycle(s) more than loads booked"`; fewer → `"{Washer|Dryer} #{n}: {k} cycle(s) fewer than loads booked"`. `what_to_check` joins the failed checks with `" · "`. Machine #5 is labelled `Titan`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_dryer_one_cycle_over_is_a_difference_naming_the_machine(): void   // spec test 16
// reading with cash_diff 0, gcash_diff 0, machine 3 dry row unexplained_cycles 1, status 'off'
// → status 'difference', failed_checks contains 'Dryer #3: 1 cycle more than loads booked'

public function test_cash_short_message(): void
// cash_diff -120 → 'Cash ₱120 short — recount, check expenses'

public function test_explained_extra_cycle_stays_balanced(): void
public function test_overview_summary_counts_and_sums(): void
// 3 closings: balanced, cash -120, dryer over; deposit_bag_amount 5000 each; gcash_transfer_amount 1000 on one
// → count 3, balanced 1, with_difference 2, cash_over_short -120, cash_deposited 15000, gcash_sent_to_bank 1000

public function test_overview_lists_only_unbalanced_days_newest_first(): void
public function test_single_day_overview_returns_the_full_closing(): void              // spec test 2 (closing half)
public function test_closing_endpoint_returns_one_view_per_branch_for_all(): void
```

- [ ] **Step 2: Run** `php artisan test --filter=ClosingEndpointTest` — expect FAIL.
- [ ] **Step 3: Implement.** Read only; do not change how `ZReadingController` stores `status`.
- [ ] **Step 4: Run** — expect PASS. Also `php artisan test --filter=ZReading` stays green.
- [ ] **Step 5: Commit** `feat: owner dashboard closing view and endpoint`

---

### Task 8: Supplies endpoint

**Files:**
- Create: `app/Support/OwnerDashboard/SuppliesStats.php`
- Modify: `app/Http/Controllers/Admin/OwnerDashboardController.php`, `routes/web.php`
- Test: `tests/Feature/OwnerDashboard/SuppliesEndpointTest.php`

**Interfaces:**
- Consumes: `Period`; movement columns from Task 2; `OverviewStats::sales|orders|kg`.
- Produces:
  - `SuppliesStats::for(Period $period): array`
  - `SuppliesStats::usedCost(Period $period): float` — cost of `out` movements **minus** the cost of positive adjustments and **plus** the cost of negative ones (so "use + adjust", as section 6 defines the Supplies cost line). Reused by Task 9.
  - Route `GET /api/dashboard/supplies` named `owner-dashboard.api.supplies`.
- A movement's cost is `abs(change_qty) × COALESCE(inventory_movements.unit_cost, inventories.unit_cost)`, signed like `change_qty` for adjustments.

Response keys: `period`, `kpis {supplies_used, per_order, per_kg, stock_purchased, purchases_count, adjustments, addons_sold, addons_count, addons_pct}`, `usage_buckets [{label, start, end, cost, is_saturday}]` plus `usage_average`, `items [{name, unit, used, avg_per_day, per_order, purchased, adjusted, cost_used}]` plus `items_total`, `purchases [{date, item, qty, unit, source, cost}]` (newest first, all rows; the page shows 5), `adjustments [{date, reason, item, qty, unit, note, cost}]`, `addons {groups [{group, rows [{name, price, count, revenue}]}], most_popular, top_earner}`.

- [ ] **Step 1: Write the failing tests**

Fixture: detergent, unit `mL`, `unit_cost` 0.06. Movements in Sep 2026: out 600 mL on Sep 5 (a Saturday) and 400 mL on Sep 7; in 20,000 mL on Sep 18; adjustment −1,000 mL reason `count` on Sep 24; in 500 mL reason `cancelled_order` on Sep 27. Two orders (15 kg total) and two closings in the month; one Ariel add-on sold for ₱20.

```php
public function test_kpi_cards(): void
// supplies_used 60 (1000 mL × 0.06), per_order 30, per_kg 4, stock_purchased 1200, purchases_count 1,
// adjustments -30 (-60 count, +30 returned), addons_sold 20, addons_count 1

public function test_item_row(): void
// used 1000, avg_per_day 500 (÷ 2 open days), per_order 500, purchased 20000, adjusted -500, cost_used 60

public function test_cancelled_order_return_is_an_adjustment_not_a_purchase(): void       // spec test 7 (stock half)
public function test_usage_buckets_follow_the_bucket_rule_and_flag_saturdays(): void
// Sep 1–30 → 30 buckets, bucket for Sep 5 has cost 36 and is_saturday true
// Jan 1–Sep 30 → bucket 'month', 9 buckets                                               // spec test 12
// Aug 1–Sep 30 → bucket 'week', first bucket ends on a Friday                            // spec test 13

public function test_movement_without_stored_cost_uses_current_item_cost(): void          // Review Focus 3
public function test_addons_grouped_by_category_with_most_popular_and_top_earner(): void
public function test_empty_range_returns_zeros(): void                                    // Review Focus 1
public function test_all_branches_equals_sum_of_branches(): void                          // spec test 11
```

- [ ] **Step 2: Run** `php artisan test --filter=SuppliesEndpointTest` — expect FAIL.
- [ ] **Step 3: Implement.** One grouped query per section (not one query per item, as the old `buildSuppliesData` does). Add-on group = the service category name. Purchase `source` = the item's supplier name, else the movement's `remarks`.
- [ ] **Step 4: Run** — expect PASS.
- [ ] **Step 5: Commit** `feat: owner dashboard supplies endpoint`

---

### Task 9: Profit endpoint

**Files:**
- Create: `app/Support/OwnerDashboard/ProfitStats.php`
- Modify: `app/Support/OwnerDashboard/OverviewStats.php` (fill `kpis.profit`, `kpis.profit_pct`), `app/Http/Controllers/Admin/OwnerDashboardController.php`, `routes/web.php`
- Test: `tests/Feature/OwnerDashboard/ProfitEndpointTest.php`

**Interfaces:**
- Consumes: `Period`; `OverviewStats::sales|orders|kg`; `SuppliesStats::usedCost`; `BranchExpense::scopeCounted`, `BILL_TYPES` (Task 4); `employer_share_rate` (Task 5).
- Produces:
  - `ProfitStats::for(Period $period): array`
  - `ProfitStats::costs(Period $period): array` — `['staff' => float, 'bills' => float, 'supplies' => float, 'other' => float]`
  - `ProfitStats::costsForDay(string $date, ?int $branchId): float` — used for the break-even day.
  - Route `GET /api/dashboard/profit` named `owner-dashboard.api.profit`.

Formulas:
- **Staff** = for each attendance record in range with a clock-in: `daily_rate` (null → 0) + `daily_rate × employer_share_rate ÷ 100`.
- **Bills** = for each counted bill overlapping the range: `amount ÷ days in period_start..period_end × days of overlap`. Keep full precision; round only the final totals.
- **Supplies** = `SuppliesStats::usedCost`.
- **Other** = counted expenses with `bill_type` null and `expense_type` not in `payroll`, `supplies`, `inventory_purchase`, by `expense_date`.
- **Profit** = Sales − (Staff + Bills + Supplies + Other).
- **Break-even:** `per_day` = total costs ÷ open days; `actual_per_day` = Sales ÷ open days; `covered_on` = first day, counting from `from`, where cumulative Sales ≥ cumulative costs (`{day: N, of: calendar_days}`), or `null` if never.

Response keys: `period`, `money_in`, `orders`, `money_out {staff, bills, supplies, other, total}` each with `pct` of Sales (via `Shares`, including profit), `profit`, `profit_pct` (1 decimal), `per_order {sales, staff, bills, supplies, other, profit}`, `break_even {per_day, actual_per_day, difference, covered_on}`, `staff {total, wages, employer_share, attendance_pct, days_present, days_scheduled, per_order, per_kg, employees [{name, role, daily_rate, days_worked, days_scheduled, wages, employer_share, total}]}`, `bills {total, pct_of_sales, rows [{type, label, amount, pct}]}`, `other_purchases {total, pct_of_sales, rows [{category, amount}]}`.

- [ ] **Step 1: Write the failing tests**

Fixture: employee Maria, `daily_rate` 650, share rate 14.5, present Mon Sep 21–Sat Sep 26 (6 days), scheduled 7. Rent ₱12,000 for Sep 1–30 and ₱15,500 for Oct 1–31, both confirmed. One confirmed "gloves" expense ₱320 on Sep 22, one **pending** ₱999 expense on Sep 22, one payroll expense ₱3,900 paid from shop cash on Sep 26.

```php
public function test_staff_cost_follows_work_days(): void                                  // spec tests 3 and 4
// ?from=2026-09-19&to=2026-09-25 (Sat–Fri): wages 3250, employer_share 471.25, staff.total 3721.25
// attendance: days_present 5 of the scheduled days in that range

public function test_single_day_bill_is_the_daily_share(): void                            // spec test 2
// ?from=2026-09-15&to=2026-09-15 → money_out.bills 400

public function test_range_across_two_months_uses_each_months_daily_share(): void          // spec test 5
// ?from=2026-09-21&to=2026-10-10 → bills = 10×400 + 10×500 = 9000

public function test_unconfirmed_expense_is_counted_nowhere(): void                        // spec test 8
// other 320, not 1319

public function test_payroll_expense_is_not_an_other_purchase(): void                      // spec test 9
// other still 320; staff comes from attendance only

public function test_supplies_cost_is_usage_not_purchases(): void
public function test_profit_and_per_order_and_shares_add_to_100(): void
public function test_break_even_covered_on_day(): void
public function test_employee_without_daily_rate_costs_zero_and_is_listed(): void          // Review Focus 4
public function test_bill_period_partly_outside_range_counts_overlap_only(): void          // Review Focus 5
public function test_empty_range_returns_zeros(): void                                     // Review Focus 1
public function test_overview_profit_card_matches_profit_endpoint(): void
```

- [ ] **Step 2: Run** `php artisan test --filter=ProfitEndpointTest` — expect FAIL.
- [ ] **Step 3: Implement** `ProfitStats`, then have `OverviewStats::for` read `profit` and `profit_pct` from it.
- [ ] **Step 4: Run** — expect PASS, and `php artisan test --filter=OverviewEndpointTest` still PASS.
- [ ] **Step 5: Commit** `feat: owner dashboard profit endpoint`

---

### Task 10: Range consistency (spec test 1)

**Files:**
- Test: `tests/Feature/OwnerDashboard/RangeConsistencyTest.php`

**Interfaces:**
- Consumes: the three endpoints and `SeedsOwnerDashboard`.

- [ ] **Step 1: Write the test**

Seed September 2026 on two branches with a seeded `mt_srand(1)` loop: 2–5 orders a day (mixed kilo/load/add-on lines), a closing on every day except Sep 13, stock usage per order, attendance for 3 employees, rent and electricity bills for the month, a handful of confirmed other purchases.

```php
public function test_thirty_one_day_views_add_up_to_the_month_view(): void
// for each of sales, orders, kg_washed, supplies_used, money_out.staff, money_out.bills, profit:
// assertEqualsWithDelta(monthValue, sumOfDailyValues, 0.5)   // "match to the peso"

public function test_all_branches_equals_sum_of_branches_for_every_total(): void           // spec test 11
```

- [ ] **Step 2: Run** `php artisan test --filter=RangeConsistencyTest`. If it fails, the fix belongs in the stats class that drifts (most likely rounding per day instead of once at the end), not in the test.
- [ ] **Step 3: Commit** `test: owner dashboard daily views add up to the range view`

---

### Task 11: Pages, calendar filter and navigation

**Files:**
- Create: `resources/views/admin/owner-dashboard/layout.blade.php`, `overview.blade.php`, `supplies.blade.php`, `profit.blade.php`, `resources/js/owner-dashboard.js`
- Modify: `app/Http/Controllers/Admin/OwnerDashboardController.php`, `routes/web.php`, `app/Support/Menu.php`, `vite.config.js` (add the JS entry if entries are listed explicitly)
- Test: `tests/Feature/OwnerDashboard/PagesTest.php`

**Interfaces:**
- Consumes: the four endpoints and their JSON keys exactly as defined in Tasks 6–9.
- Produces routes `GET /owner-dashboard/overview|supplies|profit` named `owner-dashboard.overview|supplies|profit`, admin only. Each view extends `layouts.app` (as `resources/views/dashboard.blade.php` does) and includes the shared `layout` partial.

Requirements the tests cannot fully pin:
- **Filter bar** (identical on all 3 pages): label `Dates`, a button with a calendar icon showing `From Sep 1, 2026 → To Sep 30, 2026`, the day count (`30 days`), and on the right `Branch` with `All branches` plus each active branch. No Day/Week/Month/Year buttons, no previous/next arrows.
- **Calendar:** flatpickr with `mode: 'range'`, `showMonths: 2`, `maxDate: 'today'`, `closeOnSelect: false`, plus From/To read-only fields on top and `Cancel` / `Apply` buttons appended to the flatpickr container. `Apply` navigates to the same page with the new `from`, `to`, `branch`; `Cancel` restores the previous selection. Clicking one day twice selects a single day.
- **State lives in the URL** (`?from=2026-09-01&to=2026-09-30&branch=all`); the three tab links carry the current query string.
- **Rendering:** on load, fetch the page's endpoint with the URL's query and render. Format money as whole pesos with `₱` and thousands separators; do no arithmetic in JS.
- **Layout, wording and colours** follow the mockups on spec pages 8, 12 and 15, block order as listed at the top of spec sections 4, 5 and 6. Charts use Chart.js: stacked bars + line for "Busiest days and times" (totals above bars, AM/PM values inside segments, peak chip under each day, an empty bar labelled `not in range`), bars with a dashed average line for "Supplies used over the period" (Saturdays dark orange only when the bucket is `day`), two same-scale stacked bars for "Money in vs money out".
- **Links:** Profit card "See the breakdown →" and the Supplies link row open the other page with the same query string. "Show all N closings →" lists every closing of the range from `/api/dashboard/closing`.
- **Single day:** the Overview shows the full closing cards (Shop Cash, GCash, bank envelope, machine counters) and the hourly chart instead of the tiles/table and weekday chart.

- [ ] **Step 1: Write the failing tests**

```php
public function test_admin_sees_each_page_and_staff_get_403(): void

public function test_tab_links_keep_the_range_and_branch(): void                           // spec test 15
// GET owner-dashboard.overview?from=2026-09-01&to=2026-09-30&branch=all
// → assertSee('from=2026-09-01&amp;to=2026-09-30&amp;branch=all', false) on the Profit tab link

public function test_default_range_is_first_of_month_to_today(): void
// travel to 2026-11-23, no query → page shows 'Nov 1, 2026' and 'Nov 23, 2026'

public function test_pages_carry_no_fixed_period_wording(): void                           // spec test 12
// for each page: assertDontSee each of 'Today', 'This week', 'This month', 'last 7 days', 'vs last', 'Next payday', 'Still to pay'

public function test_menu_shows_owner_dashboard_to_admins_only(): void
```

- [ ] **Step 2: Run** `php artisan test --filter=PagesTest` — expect FAIL.
- [ ] **Step 3: Implement** the controller page methods, routes, the menu entry (`owner_dashboard`, label `Owner Dashboard`, admins only, following the array shape in `Menu.php`), the layout partial and filter bar.
- [ ] **Step 4: Implement** the three pages and `owner-dashboard.js`.
- [ ] **Step 5: Run** `php artisan test --filter=PagesTest`, then `npm run build` — expect PASS and a clean build.
- [ ] **Step 6: Commit** `feat: owner dashboard pages and calendar filter`

---

### Task 12: Price snapshot check and full run

**Files:**
- Test: `tests/Feature/OwnerDashboard/PriceSnapshotTest.php`

- [ ] **Step 1: Write the test**

```php
public function test_old_orders_keep_their_saved_unit_price_after_a_price_change(): void   // spec test 17
// order 10 kg Regular at ₱30 → change the service price to ₱35 → new order 10 kg
// overview sales for the range = 300 + 350; the first line's unit_price is still 30
```

- [ ] **Step 2: Run** `php artisan test` (whole suite) — expect all green, including the pre-existing `DashboardProductionTest`.
- [ ] **Step 3: Commit** `test: price changes never rewrite old sales`

---

## Spec acceptance test → plan test

| # | Covered in |
|---|---|
| 1 | Task 10 |
| 2 | Tasks 6, 7, 9 |
| 3, 4, 5, 8, 9 | Task 9 |
| 6, 10, 11, 14 | Task 6 (11 also in 8 and 10; 14 also in 1) |
| 7 | Tasks 2, 6, 8 |
| 12, 13 | Tasks 1, 8, 11 |
| 15 | Task 11 |
| 16 | Task 7 |
| 17 | Task 12 |

## Not automated

The visual match to the mockups, and the calendar's click behaviour (two-month pop-up, shading, Apply/Cancel, future dates disabled), have no automated test here. Check them by hand on staging after deploy.
