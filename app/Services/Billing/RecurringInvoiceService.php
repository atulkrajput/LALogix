<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Services\EmailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates the invoices recurring schedules are due for. Runs from three places, all safe
 * to overlap: when an admin page opens (at most every 10 minutes), the secret cron URL,
 * and `php artisan billing:recurring` (scheduler, if the host has cron).
 *
 * Each period is claimed by moving next_run_date forward with a compare-and-swap update,
 * and invoices(recurring_invoice_id, recurring_period) is unique, so a period never gets
 * two invoices. Missed periods are caught up (oldest first, at most 24 per run).
 */
class RecurringInvoiceService
{
    public function __construct(private InvoiceService $invoices, private EmailService $mailer) {}

    /** Called on admin page loads: cheap, throttled. */
    public function runIfStale(): void
    {
        if (Cache::add('recurring-invoices:checked', true, 600)) {
            try {
                $this->runDue();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array{created: int, issued: int, emailed: int, errors: list<string>} */
    public function runDue(?Carbon $today = null, ?RecurringInvoice $only = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $result = ['created' => 0, 'issued' => 0, 'emailed' => 0, 'errors' => []];
        $schedules = $only ? collect([$only->fresh()]) : RecurringInvoice::where('status', 'active')
            ->whereDate('next_run_date', '<=', $today->toDateString())->orderBy('next_run_date')->get();

        foreach ($schedules as $schedule) {
            for ($n = 0; $n < 24; $n++) {
                $schedule->refresh();
                $period = $schedule->next_run_date->copy();
                if ($schedule->status !== 'active' || $period->gt($today)) {
                    break;
                }
                if ($schedule->end_date && $period->gt($schedule->end_date)) {
                    $schedule->update(['status' => 'ended']);
                    break;
                }
                $next = $schedule->periodAfter($period);
                // Claim this period; another runner that got here first makes this a no-op.
                $claimed = RecurringInvoice::whereKey($schedule->id)->whereDate('next_run_date', $period->toDateString())
                    ->update(['next_run_date' => $next->toDateString()]);
                if (! $claimed) {
                    continue;
                }
                $this->createFor($schedule, $period, $result);
            }
            $schedule->refresh();
            if ($schedule->status === 'active' && $schedule->end_date && $schedule->next_run_date->gt($schedule->end_date)) {
                $schedule->update(['status' => 'ended']);
            }
        }

        return $result;
    }

    private function createFor(RecurringInvoice $schedule, Carbon $period, array &$result): void
    {
        if (Invoice::where('recurring_invoice_id', $schedule->id)->whereDate('recurring_period', $period->toDateString())->exists()) {
            return;
        }
        $label = $this->periodLabel($schedule, $period);
        $fill = fn (?string $text) => $text === null ? null : strtr($text, [
            '{month}' => $period->format('F'), '{month_short}' => $period->format('M'), '{year}' => $period->format('Y'), '{period}' => $label,
        ]);

        try {
            $invoice = DB::transaction(function () use ($schedule, $period, $fill) {
                $invoice = $this->invoices->saveDraft(null, [
                    'product_id' => $schedule->product_id,
                    'client_id' => $schedule->client_id,
                    'invoice_date' => $period->toDateString(),
                    'notes' => $fill($schedule->notes),
                    'terms' => $schedule->terms,
                    'items' => array_map(fn ($i) => [...$i, 'description' => $fill($i['description'])], $schedule->items),
                ]);
                $invoice->forceFill(['recurring_invoice_id' => $schedule->id, 'recurring_period' => $period->toDateString()])->save();

                return $invoice;
            });
        } catch (Throwable $e) {
            // Give the period back so the next run retries it, and show why on the schedule.
            RecurringInvoice::whereKey($schedule->id)->update(['next_run_date' => $period->toDateString(), 'last_error' => mb_substr($this->message($e), 0, 500), 'last_run_at' => now()]);
            $result['errors'][] = "{$schedule->name} ({$label}): ".$this->message($e);

            return;
        }

        $result['created']++;
        $error = null;
        if ($schedule->auto_issue) {
            try {
                $invoice = $this->invoices->issue($invoice);
                $result['issued']++;
            } catch (ValidationException $e) {
                $error = 'Created as draft, not issued: '.$this->message($e);
            }
        }
        if ($schedule->auto_email && $invoice->status === 'issued') {
            $to = $invoice->bill_email;
            if (! $to) {
                $error = 'Issued, but not e-mailed: the client has no e-mail address.';
            } else {
                $sent = $this->mailer->send($to, "Invoice {$invoice->number} from ".(($invoice->seller['trade_name'] ?? null) ?: ($invoice->seller['legal_name'] ?? 'LALogix Enterprises')),
                    nl2br(e("Dear {$invoice->bill_name},\n\nPlease find attached invoice {$invoice->number} for {$label}, amount ₹".number_format($invoice->grand_total, 2)
                        .($invoice->due_date ? ', due by '.$invoice->due_date->format('d M Y') : '').".\n\nThank you.")),
                    null, [['filename' => InvoicePdf::filename($invoice), 'content' => base64_encode(InvoicePdf::render($invoice))]]);
                if ($sent['success']) {
                    $invoice->update(['emailed_at' => now()]);
                    $result['emailed']++;
                } else {
                    $error = 'Issued, but the e-mail failed: '.($sent['error'] ?? 'unknown error');
                }
            }
        }
        if ($error) {
            $result['errors'][] = "{$schedule->name} ({$label}): {$error}";
        }
        RecurringInvoice::whereKey($schedule->id)->update([
            'invoices_created' => DB::raw('invoices_created + 1'), 'last_run_at' => now(), 'last_error' => $error,
        ]);
    }

    /** "November 2026", or "Nov 2026 – Jan 2027" for longer periods. */
    public function periodLabel(RecurringInvoice $schedule, Carbon $period): string
    {
        $months = RecurringInvoice::FREQUENCIES[$schedule->frequency] ?? 1;
        if ($months === 1) {
            return $period->format('F Y');
        }
        $end = $period->copy()->startOfMonth()->addMonthsNoOverflow($months - 1);

        return $period->format('M Y').' – '.$end->format('M Y');
    }

    private function message(Throwable $e): string
    {
        return $e instanceof ValidationException ? collect($e->errors())->flatten()->first() ?? $e->getMessage() : $e->getMessage();
    }
}
