<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Services\Billing\PaymentReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Money owed by clients: ageing buckets, payment reminder settings and sending reminders. */
class ReceivablesController extends Controller
{
    public const BUCKETS = ['not_due' => 'Not due', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90_plus' => '90+ days'];

    public function __construct(private PaymentReminderService $reminders) {}

    public function index(): JsonResponse
    {
        $today = now()->startOfDay();
        $profile = BusinessProfile::current();
        $open = Invoice::invoices()->with(['reminders', 'client:id,name,company,no_reminders', 'product:id,name'])
            ->where('status', 'issued')->whereRaw('grand_total - amount_paid - amount_credited > 0.005')
            ->orderBy('due_date')->orderBy('invoice_date')->orderBy('id')->get();

        $zero = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        $summary = $zero + ['total' => 0.0, 'count' => 0, 'overdue' => 0.0, 'overdue_count' => 0];
        $clients = [];
        $rows = [];
        $pending = 0;
        foreach ($open as $inv) {
            $late = (int) $this->reminders->dueDate($inv)->diffInDays($today, false);
            $bucket = match (true) {
                $late <= 0 => 'not_due',
                $late <= 30 => 'd1_30',
                $late <= 60 => 'd31_60',
                $late <= 90 => 'd61_90',
                default => 'd90_plus',
            };
            $balance = $inv->balance_due;
            $summary[$bucket] += $balance;
            $summary['total'] += $balance;
            $summary['count']++;
            if ($late > 0) {
                $summary['overdue'] += $balance;
                $summary['overdue_count']++;
            }

            $key = $inv->client_id;
            $clients[$key] ??= $zero + ['client_id' => $key, 'name' => $inv->client?->company ?: ($inv->client?->name ?? $inv->bill_name), 'total' => 0.0, 'invoices' => 0, 'oldest_days' => 0];
            $clients[$key][$bucket] += $balance;
            $clients[$key]['total'] += $balance;
            $clients[$key]['invoices']++;
            $clients[$key]['oldest_days'] = max($clients[$key]['oldest_days'], $late);

            $stage = $profile->reminders_enabled ? $this->reminders->dueStage($inv, $profile, $today) : null;
            $pending += $stage !== null ? 1 : 0;
            $last = $inv->reminders->last();
            $rows[] = [
                'id' => $inv->id, 'number' => $inv->number, 'client_id' => $inv->client_id, 'client' => $clients[$key]['name'],
                'product' => $inv->product?->name, 'invoice_date' => $inv->invoice_date->toDateString(), 'due_date' => $inv->due_date?->toDateString(),
                'total' => $inv->grand_total, 'balance' => $balance, 'days_overdue' => max(0, $late), 'bucket' => $bucket,
                'email' => $inv->bill_email, 'source' => $inv->source, 'reminders_paused' => $inv->reminders_paused,
                'client_opted_out' => (bool) $inv->client?->no_reminders,
                'reminders_count' => $inv->reminders->count(), 'last_reminder_at' => $last?->created_at?->toIso8601String(),
                'next_reminder' => $this->reminders->nextReminder($inv, $profile, $today),
                'blocked' => $this->blockedReason($inv),
            ];
        }

        $round = fn (array $a) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $a);
        usort($clients, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'summary' => $round($summary),
            'buckets' => self::BUCKETS,
            'clients' => array_map($round, $clients),
            'invoices' => $rows,
            'settings' => $this->settings($profile) + ['pending_now' => $pending],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reminders_enabled' => 'required|boolean',
            'reminder_days' => ['required', 'string', 'max:60', function ($attr, $value, $fail) {
                if (PaymentReminderService::stages($value) === []) {
                    $fail('Enter days relative to the due date, e.g. -3, 1, 7, 15, 30.');
                }
            }],
            'reminder_subject' => 'nullable|string|max:255',
            'reminder_message' => 'nullable|string|max:5000',
        ]);
        $profile = BusinessProfile::current();
        $data['reminder_days'] = implode(', ', PaymentReminderService::stages($data['reminder_days']));
        // Switching on starts from today: reminders that "should" have gone out earlier are not sent in a burst.
        if ($data['reminders_enabled'] && ! $profile->reminders_enabled) {
            $data['reminders_started_on'] = now()->toDateString();
        }
        foreach (['reminder_subject' => PaymentReminderService::DEFAULT_SUBJECT, 'reminder_message' => PaymentReminderService::DEFAULT_MESSAGE] as $k => $default) {
            if (trim((string) ($data[$k] ?? '')) === '' || trim(str_replace("\r\n", "\n", $data[$k])) === $default) {
                $data[$k] = null;
            }
        }
        $profile->update($data);

        return response()->json(['settings' => $this->settings($profile->fresh())]);
    }

    public function run(): JsonResponse
    {
        if (! BusinessProfile::current()->reminders_enabled) {
            return response()->json(['error' => 'Switch payment reminders on first.'], 422);
        }

        return response()->json(['result' => $this->reminders->runDue()]);
    }

    /** The reminder as it would be sent now, plus the history, for the send dialog. */
    public function preview(int $id): JsonResponse
    {
        $invoice = Invoice::with(['reminders', 'client:id,no_reminders'])->findOrFail($id);
        $this->assertRemindable($invoice);
        $profile = BusinessProfile::current();
        [$subject, $message] = $this->reminders->render($invoice, $profile->reminder_subject, $profile->reminder_message);

        return response()->json([
            'to' => $invoice->bill_email, 'subject' => $subject, 'message' => $message,
            'history' => $invoice->reminders->map(fn ($r) => [
                'sent_at' => $r->created_at->toIso8601String(), 'to' => $r->sent_to, 'stage' => $r->stage, 'balance_due' => $r->balance_due,
            ])->values(),
        ]);
    }

    public function remind(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::with('client:id,no_reminders')->findOrFail($id);
        $this->assertRemindable($invoice);
        $data = $request->validate(['to' => 'required|email', 'subject' => 'required|string|max:255', 'message' => 'required|string|max:5000']);
        try {
            $this->reminders->send($invoice, $data['to'], $data['subject'], $data['message']);
        } catch (Throwable $e) {
            return response()->json(['error' => 'The reminder could not be sent: '.$e->getMessage()], 502);
        }

        return response()->json(['success' => true, 'stub' => ! config('lalogix.resend_api_key')]);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['paused' => 'required|boolean']);
        $invoice = Invoice::findOrFail($id);
        $invoice->update(['reminders_paused' => $data['paused']]);

        return response()->json(['success' => true, 'paused' => $invoice->reminders_paused]);
    }

    private function assertRemindable(Invoice $invoice): void
    {
        if ($invoice->isCreditNote() || $invoice->status !== 'issued' || $invoice->balance_due <= 0) {
            abort(response()->json(['error' => 'Only issued invoices with a balance due can be reminded.'], 422));
        }
    }

    private function blockedReason(Invoice $inv): ?string
    {
        return match (true) {
            $inv->source !== 'manual' => 'Issued by '.($inv->product?->name ?? 'a product').': no automatic reminders',
            (string) $inv->bill_email === '' => 'No e-mail address on the invoice',
            (bool) $inv->client?->no_reminders => 'Client has reminders turned off',
            $inv->reminders_paused => 'Paused for this invoice',
            default => null,
        };
    }

    private function settings(BusinessProfile $p): array
    {
        return [
            'reminders_enabled' => $p->reminders_enabled,
            'reminder_days' => $p->reminder_days,
            'reminder_subject' => $p->reminder_subject,
            'reminder_message' => $p->reminder_message,
            'default_subject' => PaymentReminderService::DEFAULT_SUBJECT,
            'default_message' => PaymentReminderService::DEFAULT_MESSAGE,
            'started_on' => $p->reminders_started_on?->toDateString(),
            'last_run_at' => $p->reminders_last_run_at?->toIso8601String(),
            'last_error' => $p->reminders_last_error,
        ];
    }
}
