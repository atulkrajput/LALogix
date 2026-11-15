<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Services\EmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * E-mails payment reminders for unpaid invoices on a schedule of days relative to the due date
 * (e.g. -3 = three days before, 7 = a week late). Runs from the same three places as recurring
 * invoices: admin page loads (throttled), the secret cron URL and `php artisan billing:reminders`.
 *
 * Rules that keep it from spamming clients:
 *  - only the latest stage that has come due is sent (a run after a long gap sends one e-mail, not five);
 *  - stages that fell due before reminders were switched on are never sent automatically;
 *  - nothing goes out within 2 days of another reminder to the same invoice (manual ones included);
 *  - each (invoice, stage) is claimed with a unique row before sending, so overlapping runs can't double-send;
 *  - invoices paused individually, clients opted out, invoices from product connectors and invoices
 *    without an e-mail address are skipped.
 */
class PaymentReminderService
{
    public const MAX_PER_RUN = 50;
    public const QUIET_DAYS = 2;

    public const DEFAULT_SUBJECT = 'Payment reminder: invoice {number} {due_text}';
    public const DEFAULT_MESSAGE = "Dear {client},\n\nThis is a friendly reminder that invoice {number} dated {invoice_date} for {amount} {due_text}.\n\n{payment_details}\n\nIf you have already paid, please ignore this e-mail or reply with the payment reference so we can update our records. The invoice is attached for your reference.\n\nThank you,\n{business}";

    public function __construct(private EmailService $mailer) {}

    public function runIfStale(): void
    {
        if (BusinessProfile::current()->reminders_enabled && Cache::add('payment-reminders:checked', true, 600)) {
            try {
                $this->runDue();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array{sent: int, errors: list<string>} */
    public function runDue(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $profile = BusinessProfile::current();
        $result = ['sent' => 0, 'errors' => []];
        if (! $profile->reminders_enabled) {
            return $result;
        }

        foreach ($this->openInvoices() as $invoice) {
            if ($result['sent'] >= self::MAX_PER_RUN) {
                break;
            }
            $stage = $this->dueStage($invoice, $profile, $today);
            if ($stage === null) {
                continue;
            }
            try {
                [$subject, $message] = $this->render($invoice, $profile->reminder_subject, $profile->reminder_message, $today);
                $this->send($invoice, (string) $invoice->bill_email, $subject, $message, $stage);
                $result['sent']++;
            } catch (UniqueConstraintViolationException) {
                // Another run claimed this stage first.
            } catch (Throwable $e) {
                $result['errors'][] = "{$invoice->number}: ".$e->getMessage();
            }
        }

        $profile->update(['reminders_last_run_at' => now(), 'reminders_last_error' => $result['errors'] ? mb_substr(implode(' ', $result['errors']), 0, 500) : null]);

        return $result;
    }

    /**
     * Sends one reminder and logs it. The log row is written first (it is the claim for automatic
     * stages) and removed again if the e-mail fails, so the next run retries.
     */
    public function send(Invoice $invoice, string $to, string $subject, string $message, ?int $stage = null): InvoiceReminder
    {
        $reminder = InvoiceReminder::create([
            'invoice_id' => $invoice->id, 'stage' => $stage, 'sent_to' => $to,
            'subject' => mb_substr($subject, 0, 255), 'balance_due' => $invoice->balance_due,
        ]);
        try {
            $sent = $this->mailer->send($to, $subject, nl2br(e($message)), null,
                [['filename' => InvoicePdf::filename($invoice), 'content' => base64_encode(InvoicePdf::render($invoice))]]);
        } catch (Throwable $e) {
            $reminder->delete();
            throw $e;
        }
        if (! $sent['success']) {
            $reminder->delete();
            throw new \RuntimeException($sent['error'] ?? 'The e-mail could not be sent.');
        }

        return $reminder;
    }

    /** Issued invoices with money still due that reminders may go to. */
    public function openInvoices(): Collection
    {
        return Invoice::invoices()->with(['reminders', 'client:id,no_reminders'])
            ->where('status', 'issued')->where('source', 'manual')->where('reminders_paused', false)
            ->whereRaw('grand_total - amount_paid - amount_credited > 0.005')
            ->whereNotNull('bill_email')->where('bill_email', '!=', '')
            ->whereHas('client', fn ($q) => $q->where('no_reminders', false))
            ->orderBy('due_date')->orderBy('id')->get();
    }

    /** The automatic stage to send today, or null. */
    public function dueStage(Invoice $invoice, BusinessProfile $profile, Carbon $today): ?int
    {
        if (! $this->eligible($invoice)) {
            return null;
        }
        $due = $this->dueDate($invoice);
        $started = $profile->reminders_started_on;
        $stage = null;
        foreach (self::stages($profile->reminder_days) as $s) {
            $on = $due->copy()->addDays($s);
            if ($on->lte($today) && (! $started || $on->gte($started))) {
                $stage = $s;
            }
        }
        if ($stage === null) {
            return null;
        }
        $reminders = $invoice->reminders;
        if ($reminders->whereNotNull('stage')->where('stage', '>=', $stage)->isNotEmpty()) {
            return null;
        }
        if ($reminders->contains(fn ($r) => $r->created_at->gt($today->copy()->subDays(self::QUIET_DAYS)->endOfDay()))) {
            return null;
        }

        return $stage;
    }

    /** When the next automatic reminder goes out (today if one is waiting), for the list. */
    public function nextReminder(Invoice $invoice, BusinessProfile $profile, Carbon $today): ?string
    {
        if (! $profile->reminders_enabled || ! $this->eligible($invoice)) {
            return null;
        }
        if ($this->dueStage($invoice, $profile, $today) !== null) {
            return $today->toDateString();
        }
        $due = $this->dueDate($invoice);
        $sent = $invoice->reminders->whereNotNull('stage')->max('stage');
        $last = $invoice->reminders->max('created_at');
        foreach (self::stages($profile->reminder_days) as $s) {
            $on = $due->copy()->addDays($s);
            if ($on->gt($today) && ($sent === null || $s > $sent)) {
                $quietUntil = $last ? Carbon::parse($last)->startOfDay()->addDays(self::QUIET_DAYS) : null;

                return ($quietUntil && $quietUntil->gt($on) ? $quietUntil : $on)->toDateString();
            }
        }

        return null;
    }

    public function eligible(Invoice $invoice): bool
    {
        return $invoice->status === 'issued' && ! $invoice->isCreditNote() && $invoice->source === 'manual'
            && ! $invoice->reminders_paused && $invoice->balance_due > 0 && (string) $invoice->bill_email !== ''
            && ! ($invoice->client?->no_reminders);
    }

    public function dueDate(Invoice $invoice): Carbon
    {
        return ($invoice->due_date ?? $invoice->invoice_date)->copy()->startOfDay();
    }

    /** @return array{0: string, 1: string} subject, message with placeholders filled in */
    public function render(Invoice $invoice, ?string $subject, ?string $message, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $due = $this->dueDate($invoice);
        $late = (int) $due->diffInDays($today, false);
        $seller = $invoice->seller ?: [];
        $business = ($seller['trade_name'] ?? null) ?: (($seller['legal_name'] ?? null) ?: (BusinessProfile::current()->trade_name ?: BusinessProfile::current()->legal_name));

        $dueText = match (true) {
            $late < 0 => 'is due on '.$due->format('d M Y'),
            $late === 0 => 'is due today',
            default => 'was due on '.$due->format('d M Y').' ('.$late.' '.($late === 1 ? 'day' : 'days').' ago)',
        };
        $vars = [
            '{client}' => $invoice->bill_company ?: $invoice->bill_name,   // contact person when billed to a company
            '{company}' => $invoice->bill_name,
            '{number}' => $invoice->number,
            '{amount}' => self::inr($invoice->balance_due),
            '{total}' => self::inr($invoice->grand_total),
            '{invoice_date}' => $invoice->invoice_date->format('d M Y'),
            '{due_date}' => $due->format('d M Y'),
            '{due_text}' => $dueText,
            '{days_overdue}' => (string) max(0, $late),
            '{business}' => $business,
            '{payment_details}' => $this->paymentDetails($seller),
        ];
        $fill = fn (string $t) => trim(preg_replace("/\n{3,}/", "\n\n", strtr($t, $vars)));

        return [$fill($subject ?: self::DEFAULT_SUBJECT), $fill($message ?: self::DEFAULT_MESSAGE)];
    }

    private function paymentDetails(array $seller): string
    {
        $lines = array_filter([
            ! empty($seller['upi_id']) ? 'UPI: '.$seller['upi_id'] : null,
            ! empty($seller['bank_account_number']) ? 'Bank transfer: '.implode(', ', array_filter([
                $seller['bank_account_name'] ?? null, 'A/c '.$seller['bank_account_number'],
                ! empty($seller['bank_ifsc']) ? 'IFSC '.$seller['bank_ifsc'] : null, $seller['bank_name'] ?? null,
            ])) : null,
        ]);

        return $lines ? "You can pay by\n".implode("\n", $lines) : '';
    }

    /** "-3, 1, 7" → [-3, 1, 7]; sorted, unique, between 60 days before and a year after the due date. */
    public static function stages(?string $days): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string) $days, -1, PREG_SPLIT_NO_EMPTY) as $d) {
            if (preg_match('/^[+-]?\d{1,3}$/', $d) && (int) $d >= -60 && (int) $d <= 365) {
                $out[(int) $d] = (int) $d;
            }
        }
        sort($out);

        return array_values($out);
    }

    /** ₹1,23,456.00 (Indian digit grouping). */
    public static function inr(float $amount): string
    {
        $neg = $amount < 0;
        [$int, $dec] = explode('.', number_format(abs($amount), 2, '.', ''));
        if (strlen($int) > 3) {
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)).','.substr($int, -3);
        }

        return ($neg ? '−' : '').'₹'.$int.'.'.$dec;
    }
}
