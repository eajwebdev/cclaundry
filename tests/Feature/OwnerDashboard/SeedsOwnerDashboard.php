<?php

namespace Tests\Feature\OwnerDashboard;

use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\Customer;
use App\Models\EmployeeAttendanceRecord;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\LaundryServiceCategory;
use App\Models\Payment;
use App\Models\ScheduleShift;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;

/** Minimal rows for owner-dashboard tests, created straight through the models. */
trait SeedsOwnerDashboard
{
    protected function seedBranch(string $name): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $name, 'branch_type' => 'full_service', 'machine_count' => 1, 'is_active' => true]);
    }

    protected function admin(): User
    {
        SystemSetting::query()->firstOrCreate([], [
            'business_name' => 'Spin Klean Laundry', 'currency' => 'PHP', 'job_order_prefix' => 'JO',
            'invoice_prefix' => 'INV', 'primary_color' => '#2E7D32', 'is_completed' => true,
        ]);

        return User::factory()->create(['role' => 'super_admin']);
    }

    protected function service(string $name, string $pricingType, float $price, bool $addon = false, string $category = 'Add-ons'): LaundryService
    {
        $category = $addon ? LaundryServiceCategory::query()->firstOrCreate(['name' => $category], ['is_addon' => true]) : null;

        return LaundryService::query()->create([
            'name' => $name, 'report_category' => 'regular', 'pricing_type' => $pricingType, 'price' => $price,
            'service_category_id' => $category?->id, 'is_active' => true,
        ]);
    }

    /** @param list<array{0: LaundryService, 1: float|int}> $lines */
    protected function order(Branch $b, string $dateTime, array $lines, array $attrs = []): JobOrder
    {
        $customer = Customer::query()->firstOrCreate(['branch_id' => $b->id, 'name' => 'Customer'], ['billing_type' => 'regular', 'is_active' => true]);
        $items = array_map(fn ($line) => (object) ['service' => $line[0], 'quantity' => $line[1], 'total' => $line[1] * (float) $line[0]->price], $lines);
        $total = array_sum(array_column($items, 'total'));

        // forceFill: created_at is not fillable.
        $order = (new JobOrder)->forceFill($attrs + [
            'branch_id' => $b->id, 'customer_id' => $customer->id, 'job_order_number' => 'JO-'.(JobOrder::withTrashed()->count() + 1),
            'status' => 'pending', 'transaction_type' => 'walk_in', 'intake_type' => 'walk_in', 'return_type' => 'shop_pickup',
            'kg_weighed' => JobOrder::kgFromItems($items), 'subtotal' => $total, 'total' => $total, 'paid_amount' => 0, 'balance' => $total,
            'created_at' => $dateTime, 'updated_at' => $dateTime,
        ]);
        $order->save();

        foreach ($items as $item) {
            $order->items()->create([
                'laundry_service_id' => $item->service->id, 'description' => $item->service->name,
                'quantity' => $item->quantity, 'unit_price' => $item->service->price, 'total' => $item->total,
            ]);
        }

        return $order;
    }

    protected function pay(JobOrder $o, string $dateTime, string $type, float $amount): Payment
    {
        $payment = Payment::query()->create([
            'branch_id' => $o->branch_id, 'job_order_id' => $o->id, 'customer_id' => $o->customer_id,
            'payment_number' => 'PAY-'.(Payment::withoutGlobalScopes()->count() + 1), 'payment_type' => $type,
            'amount' => $amount, 'paid_at' => $dateTime,
        ]);
        $paid = (float) $o->paid_amount + $amount;
        $o->update(['paid_amount' => $paid, 'balance' => max(0, (float) $o->total - $paid)]);

        return $payment;
    }

    protected function closing(Branch $b, string $date, array $attrs = []): ZReading
    {
        return ZReading::query()->create($attrs + [
            'branch_id' => $b->id, 'reading_number' => "ZR-{$b->code}-{$date}", 'business_date' => $date,
            'signature_name' => 'Cashier', 'status' => 'balanced',
        ]);
    }

    protected function stockItem(Branch $b, string $name, string $unit, float $unitCost, array $attrs = []): Inventory
    {
        return Inventory::query()->create($attrs + [
            'branch_id' => $b->id, 'name' => $name, 'unit' => $unit, 'quantity' => 0, 'reorder_level' => 0,
            'unit_cost' => $unitCost, 'is_active' => true,
        ]);
    }

    /** Stores the item's current cost unless $attrs says otherwise; quantity defaults to |change| (pass the new level for adjustments). */
    protected function movement(Inventory $i, string $dateTime, string $type, float $changeQty, array $attrs = []): InventoryMovement
    {
        // forceFill: created_at is not fillable.
        $movement = (new InventoryMovement)->forceFill($attrs + [
            'inventory_id' => $i->id, 'movement_type' => $type, 'quantity' => abs($changeQty), 'change_qty' => $changeQty,
            'unit_cost' => $i->unit_cost, 'created_at' => $dateTime, 'updated_at' => $dateTime,
        ]);
        $movement->save();

        return $movement;
    }

    protected function employee(Branch $b, string $firstName, ?float $dailyRate, array $attrs = []): AttendanceEmployee
    {
        return AttendanceEmployee::query()->create($attrs + [
            'branch_id' => $b->id, 'first_name' => $firstName, 'last_name' => 'Staff', 'daily_rate' => $dailyRate,
            'username' => strtolower($firstName).'-'.(AttendanceEmployee::withTrashed()->count() + 1), 'password' => 'secret', 'status' => 'active',
        ]);
    }

    /** One attendance record; $clockedIn false leaves clock_in empty (not a paid day). Defaults to the employee's branch. */
    protected function attend(AttendanceEmployee $e, string $date, ?Branch $b = null, bool $clockedIn = true): EmployeeAttendanceRecord
    {
        return EmployeeAttendanceRecord::query()->create([
            'attendance_employee_id' => $e->id, 'branch_id' => $b?->id ?? $e->branch_id, 'work_date' => $date,
            'clock_in' => $clockedIn ? ['08:00:00'] : [], 'clock_out' => [],
        ]);
    }

    protected function shift(AttendanceEmployee $e, string $date, string $template = 'opening', bool $published = true, ?Branch $b = null): ScheduleShift
    {
        return ScheduleShift::query()->create([
            'attendance_employee_id' => $e->id, 'branch_id' => $b?->id ?? $e->branch_id, 'work_date' => $date,
            'template' => $template, 'published_at' => $published ? $date.' 00:00:00' : null,
        ]);
    }

    /** Paid from the drawer with the given drawer_status (default confirmed), so the model keeps that status. */
    protected function expense(Branch $b, string $date, string $type, string $title, float $amount, array $attrs = []): BranchExpense
    {
        return BranchExpense::query()->create($attrs + [
            'branch_id' => $b->id, 'category' => $type, 'expense_type' => $type, 'title' => $title, 'amount' => $amount,
            'expense_date' => $date, 'payment_method' => 'cash', 'paid_from' => 'store_cash',
            'drawer_amount' => $amount, 'gcash_amount' => 0, 'bank_amount' => 0, 'owner_amount' => 0, 'drawer_status' => 'confirmed',
        ]);
    }

    /** Payroll paid to $e for the salary period $start..$end (null = no period saved), entered on $date (default $end). */
    protected function payroll(AttendanceEmployee $e, float $amount, ?string $start, ?string $end, array $attrs = [], ?string $date = null): BranchExpense
    {
        return $this->expense($e->branch, $date ?? $end, 'payroll', 'Salary', $amount, $attrs + [
            'attendance_employee_id' => $e->id, 'salary_period_start' => $start, 'salary_period_end' => $end,
        ]);
    }

    /** A bill (bill_type set) covering $start..$end, entered on $start. */
    protected function bill(Branch $b, string $billType, float $amount, ?string $start, ?string $end, array $attrs = []): BranchExpense
    {
        $type = collect(BranchExpense::BILL_TYPES_BY_EXPENSE_TYPE)->search(fn ($bills) => in_array($billType, $bills, true));

        return $this->expense($b, $start ?? '2026-09-01', $type, BranchExpense::BILL_TYPES[$billType], $amount, $attrs + [
            'bill_type' => $billType, 'period_start' => $start, 'period_end' => $end,
        ]);
    }
}
