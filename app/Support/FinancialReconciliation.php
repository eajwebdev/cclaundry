<?php

namespace App\Support;

use App\Models\AccountsPayable;
use App\Models\AccountsPayablePayment;
use App\Models\BranchExpense;
use App\Models\CashOpening;
use App\Models\JobOrder;
use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Models\ZReading;

class FinancialReconciliation
{
    public static function forPeriod(?int $branchId, string $dateFrom, string $dateTo): array
    {
        $salesPayments = Payment::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $collectedPayments = Payment::query()
            ->when($branchId, fn ($query) => $query->where('collected_branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $paymentAmounts = (clone $collectedPayments)
            ->selectRaw('payment_type, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('payment_type')
            ->pluck('total_amount', 'payment_type')
            ->map(fn ($amount) => round((float) $amount, 2))
            ->all();

        $paymentCounts = (clone $collectedPayments)
            ->selectRaw('payment_type, COUNT(*) as payments_count')
            ->groupBy('payment_type')
            ->pluck('payments_count', 'payment_type')
            ->map(fn ($count) => (int) $count)
            ->all();

        $expenses = BranchExpense::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo);

        $expenseSummary = (clone $expenses)
            ->selectRaw("COALESCE(SUM(amount), 0) as total,
                COALESCE(SUM(CASE WHEN drawer_status = 'confirmed' THEN drawer_amount ELSE 0 END), 0) as store_cash,
                COALESCE(SUM(gcash_amount), 0) as store_gcash,
                COALESCE(SUM(bank_amount), 0) as store_bank,
                COALESCE(SUM(owner_amount), 0) as owner_paid")
            ->first();

        $movements = MoneyMovement::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNull('voided_at')
            ->whereDate('movement_date', '>=', $dateFrom)
            ->whereDate('movement_date', '<=', $dateTo)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as cash_in,
                COALESCE(SUM(CASE WHEN type = 'bank_deposit' THEN amount ELSE 0 END), 0) as bank_deposits,
                COALESCE(SUM(CASE WHEN type = 'bank_deposit' THEN drawer_amount ELSE 0 END), 0) as extra_deposited,
                COALESCE(SUM(CASE WHEN type = 'bank_deposit' THEN bag_amount ELSE 0 END), 0) as bag_deposited,
                COALESCE(SUM(CASE WHEN direction = 'out' AND type <> 'bank_deposit' THEN amount ELSE 0 END), 0) as owner_took")
            ->first();

        $cashIn = round((float) $movements->cash_in, 2);
        $bankDeposits = round((float) $movements->bank_deposits, 2);
        $extraDeposited = round((float) $movements->extra_deposited, 2);
        $ownerTook = round((float) $movements->owner_took, 2);
        // Only the drawer part of a bank deposit leaves the drawer; the sealed bag was already out.
        $cashOut = round($extraDeposited + $ownerTook, 2);
        $openingFloat = round((float) CashOpening::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', '>=', $dateFrom)
            ->whereDate('business_date', '<=', $dateTo)
            ->sum('counted_amount'), 2);

        $cashlessFunding = AccountsPayable::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('source_type', 'owner_funding')
            ->whereIn('funding_method', ['gcash', 'bank'])
            ->whereDate('funded_at', '>=', $dateFrom)
            ->whereDate('funded_at', '<=', $dateTo)
            ->selectRaw('funding_method, COALESCE(SUM(original_amount), 0) as total_amount')
            ->groupBy('funding_method')
            ->pluck('total_amount', 'funding_method')
            ->map(fn ($amount) => round((float) $amount, 2));

        $cashlessRepayments = AccountsPayablePayment::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereIn('payment_method', ['gcash', 'bank'])
            ->whereDate('payment_date', '>=', $dateFrom)
            ->whereDate('payment_date', '<=', $dateTo)
            ->selectRaw('payment_method, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('payment_method')
            ->pluck('total_amount', 'payment_method')
            ->map(fn ($amount) => round((float) $amount, 2));

        $cashCollections = round((float) ($paymentAmounts['cash'] ?? 0), 2);
        $gcashCollections = round((float) ($paymentAmounts['gcash'] ?? 0), 2);
        $bankCollections = round((float) ($paymentAmounts['bank'] ?? 0), 2);
        $storeCashExpenses = round((float) ($expenseSummary->store_cash ?? 0), 2);
        $storeGcashExpenses = round((float) ($expenseSummary->store_gcash ?? 0), 2);
        $storeBankExpenses = round((float) ($expenseSummary->store_bank ?? 0), 2);
        $expectedCashDrawer = round($openingFloat + $cashCollections + $cashIn - $storeCashExpenses - $cashOut, 2);
        $expectedGcash = round($gcashCollections + (float) ($cashlessFunding['gcash'] ?? 0) - (float) ($cashlessRepayments['gcash'] ?? 0) - $storeGcashExpenses, 2);
        $expectedBank = round($bankCollections + $bankDeposits + (float) ($cashlessFunding['bank'] ?? 0) - (float) ($cashlessRepayments['bank'] ?? 0) - $storeBankExpenses, 2);

        $unpaidBalance = round((float) JobOrder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('balance', '>', 0)
            ->where('status', '!=', 'cancelled')
            ->sum('balance'), 2);

        $accountsPayable = round((float) AccountsPayable::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('balance'), 2);

        $overShort = round((float) ZReading::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', '>=', $dateFrom)
            ->whereDate('business_date', '<=', $dateTo)
            ->sum('over_short_amount'), 2);

        return [
            'sales_owned' => round((float) (clone $salesPayments)->sum('amount'), 2),
            'physical_collections' => round($cashCollections + $gcashCollections + $bankCollections, 2),
            'cash_collections' => $cashCollections,
            'gcash_collections' => $gcashCollections,
            'bank_collections' => $bankCollections,
            'po_collections' => round((float) ($paymentAmounts['po'] ?? 0), 2),
            'monthly_billing_collections' => round((float) ($paymentAmounts['monthly_billing'] ?? 0), 2),
            'payment_amounts' => $paymentAmounts,
            'payment_counts' => $paymentCounts,
            'expenses_total' => round((float) ($expenseSummary->total ?? 0), 2),
            'store_cash_expenses' => $storeCashExpenses,
            'store_gcash_expenses' => $storeGcashExpenses,
            'store_bank_expenses' => $storeBankExpenses,
            'owner_paid_expenses' => round((float) ($expenseSummary->owner_paid ?? 0), 2),
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'bank_deposits' => $bankDeposits,
            'opening_float' => $openingFloat,
            'extra_deposited' => $extraDeposited,
            'bag_deposited' => round((float) $movements->bag_deposited, 2),
            'owner_took' => $ownerTook,
            'expected_cash_drawer' => $expectedCashDrawer,
            'gcash_owner_funding' => (float) ($cashlessFunding['gcash'] ?? 0),
            'bank_owner_funding' => (float) ($cashlessFunding['bank'] ?? 0),
            'gcash_payable_repayments' => (float) ($cashlessRepayments['gcash'] ?? 0),
            'bank_payable_repayments' => (float) ($cashlessRepayments['bank'] ?? 0),
            'expected_gcash' => $expectedGcash,
            'expected_bank' => $expectedBank,
            'expected_total' => round($expectedCashDrawer + $expectedGcash + $expectedBank, 2),
            'unpaid_balance' => $unpaidBalance,
            'accounts_payable' => $accountsPayable,
            'over_short' => $overShort,
        ];
    }
}
