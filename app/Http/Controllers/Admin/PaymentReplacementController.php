<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerLedger;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReplacementController extends Controller
{
    public function store(Request $request, JobOrder $jobOrder)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'replacement_number' => ['required', 'string', 'max:255'],
            'correction_reason' => ['required', 'string', 'max:1000'],
            'void_duplicate' => ['nullable', 'boolean'],
        ]);

        $target = DB::transaction(function () use ($request, $jobOrder, $data) {
            $candidate = JobOrder::where('job_order_number', trim($data['replacement_number']))->first();
            $this->require($candidate && $candidate->id !== $jobOrder->id, 'Choose a different, existing replacement job order.');
            // Every payment writer locks its job order. Lock both in ID order so
            // a concurrent payment or correction cannot reuse this money twice.
            $orders = JobOrder::whereIn('id', [$jobOrder->id, $candidate->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $orders->get($jobOrder->id);
            $target = $orders->get($candidate->id);
            $this->require($source && $target, 'One of these job orders is no longer available.');
            $this->require($source->status === 'cancelled' && ! $source->replacement_job_order_id, 'The original order must be cancelled and its payments must not have been reused already.');
            $this->require($target->status !== 'cancelled' && ! $target->archived_at, 'The replacement must be a current, non-cancelled order.');
            $this->require((int) $source->customer_id === (int) $target->customer_id && (int) $source->branch_id === (int) $target->branch_id, 'Both orders must belong to the same customer and branch.');
            $this->require(! JobOrder::withTrashed()->where('replacement_job_order_id', $target->id)->exists(), 'This replacement is already linked to another cancelled order.');

            $payments = Payment::where('job_order_id', $source->id)->lockForUpdate()->get();
            $amount = $this->cents($payments->sum('amount'));
            $this->require($amount > 0 && $payments->every(fn ($payment) => in_array($payment->payment_type, ['cash', 'gcash', 'bank'], true)), 'The original must have cash, GCash, or bank payments available to reuse.');
            $targetPayments = Payment::where('job_order_id', $target->id)->lockForUpdate()->get();
            $existing = $this->cents($targetPayments->sum('amount'));
            $voidDuplicate = $request->boolean('void_duplicate');
            if ($voidDuplicate) {
                $this->require($targetPayments->isNotEmpty() && $existing === $amount, 'To void a duplicate, the replacement payments must match the original payment total exactly.');
            }
            $paidAfter = ($voidDuplicate ? 0 : $existing) + $amount;
            $this->require($paidAfter <= $this->cents($target->total), 'The replacement has insufficient unpaid balance. Only confirm duplicate payments if no additional money was actually received.');

            $ledger = CustomerLedger::where('job_order_id', $source->id)->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $credits = $ledger->where('payment_id', $payment->id)->where('entry_type', 'credit')->sum('amount');
                $this->require($this->cents($credits) === $this->cents($payment->amount), 'The original payment ledger needs reconciliation before these payments can be reused.');
            }
            // Remove the cancelled charge with a journal entry, rather than
            // turning the transferred payment into a new debt on the old order.
            $charge = $ledger->reject(fn ($entry) => in_array($entry->payment_id, $payments->modelKeys(), true))
                ->sum(fn ($entry) => $entry->entry_type === 'credit' ? -$this->cents($entry->amount) : $this->cents($entry->amount));
            $this->require($charge >= $amount, 'The original ledger contains adjustments or refunds that must be reconciled first.');
            $voidedIds = [];
            if ($voidDuplicate) {
                foreach ($targetPayments as $duplicate) {
                    $credits = CustomerLedger::where('payment_id', $duplicate->id)->where('job_order_id', $target->id)->where('entry_type', 'credit')->sum('amount');
                    $this->require($this->cents($credits) === $this->cents($duplicate->amount), 'The replacement payment ledger needs reconciliation before a duplicate can be voided.');
                    $duplicate->update(['voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['correction_reason']]);
                    $this->journal($target, 'debit', $this->cents($duplicate->amount), 'Duplicate payment voided: '.$duplicate->payment_number.' — '.$data['correction_reason'], $duplicate->id);
                    $voidedIds[] = $duplicate->id;
                }
            }
            foreach ($payments as $payment) {
                $payment->update([
                    'job_order_id' => $target->id,
                    'remarks' => trim(($payment->remarks ? $payment->remarks."\n" : '').'Reused from '.$source->job_order_number.' for '.$target->job_order_number),
                ]);
            }
            CustomerLedger::whereIn('payment_id', $payments->modelKeys())->where('job_order_id', $source->id)
                ->where('entry_type', 'credit')->update(['job_order_id' => $target->id]);
            $this->journal($source, 'credit', $charge, 'Cancelled charge replaced by '.$target->job_order_number.' — '.$data['correction_reason']);
            $source->update(['paid_amount' => 0, 'balance' => 0, 'replacement_job_order_id' => $target->id]);
            $target->update(['paid_amount' => $paidAfter / 100, 'balance' => ($this->cents($target->total) - $paidAfter) / 100]);
            $running = 0;
            CustomerLedger::where('customer_id', $source->customer_id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()
                ->each(function ($entry) use (&$running) {
                    $running = max(0, $running + ($entry->entry_type === 'credit' ? -1 : 1) * $this->cents($entry->amount));
                    $entry->update(['running_balance' => $running / 100]);
                });
            $audit = [
                'source_job_order' => $source->job_order_number, 'replacement_job_order' => $target->job_order_number,
                'payment_ids' => $payments->modelKeys(), 'voided_duplicate_payment_ids' => $voidedIds,
                'amount' => $amount / 100, 'cancelled_charge_removed' => $charge / 100, 'reason' => $data['correction_reason'],
            ];
            Activity::log($request, 'job_order_payments_transferred', $source, $audit, $source->branch_id);
            Activity::log($request, 'job_order_payment_reused', $target, $audit, $target->branch_id);

            return $target;
        });

        return redirect()->route('admin.job-orders.show', $target)->with('success', 'Original payments reused. No new payment was recorded; any confirmed duplicate was voided with an audit trail.');
    }

    private function cents($value): int
    {
        return (int) round((float) $value * 100);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['replacement_number' => $message]);
        }
    }

    private function journal(JobOrder $order, string $type, int $amount, string $description, ?int $paymentId = null): void
    {
        CustomerLedger::create([
            'branch_id' => $order->branch_id, 'customer_id' => $order->customer_id,
            'job_order_id' => $order->id, 'payment_id' => $paymentId,
            'entry_type' => $type, 'amount' => $amount / 100, 'running_balance' => 0, 'description' => mb_substr($description, 0, 255),
        ]);
    }
}
