Finishing spray consumes 3.5 mL per kilo of laundry when the spray add-on is selected. Add-on quantity does not multiply consumption. Kilo-priced lines use their recorded quantity; load-priced lines use quantity × configured kilos per load. Piece-only orders need a recorded laundry weight before spray can be calculated. Prices are unchanged.

Deploy the code and run `php artisan migrate --force`. New finishing-spray stock uses mL. Only empty, unused legacy `use` placeholders are converted automatically. Existing mL/liter balances retain their units. For populated legacy `use` stock, establish a measured balance and consistent volume-based movement history before backfilling; a use has no known volume conversion. The command refuses unsupported units and rolls back all adjustments if any order cannot be calculated.

Preview the requested historical corrections:

```sh
php artisan inventory:backfill-finishing-spray --from=2026-09-26
```

Apply the reviewed corrections:

```sh
php artisan inventory:backfill-finishing-spray --from=2026-09-26 --apply
```

The cutoff is inclusive, using the order creation date in the application's timezone. Only orders with spray selected and inventory already deducted are included; cancelled and deleted orders are excluded. Pending production orders receive the new rule when stock is deducted. Stock is adjusted in the production branch. Additional deductions retain the original inventory deduction date for reporting; excessive outgoing quantities are reduced in their existing movements. Restorations are included when calculating net prior usage. The preview lists the old and corrected amounts for review.

Repeated runs make no further adjustments once net usage matches. Edits and deletion continue to restore the corrected net usage through the existing order lifecycle. Historical corrections may expose a negative stock balance; reconcile that balance against measured stock. This command does not change sales totals or payments.
