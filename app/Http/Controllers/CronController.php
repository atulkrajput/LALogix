<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Services\Billing\PaymentReminderService;
use App\Services\Billing\RecurringInvoiceService;
use Illuminate\Http\JsonResponse;

/**
 * GET /cron/recurring/{token}: for hosts without cron, point a free cron service
 * (e.g. cron-job.org) at this URL hourly or daily. It creates due recurring invoices and
 * then sends due payment reminders. The token is created on the Recurring invoices page;
 * a wrong token gets 404.
 */
class CronController extends Controller
{
    public function recurring(string $token, RecurringInvoiceService $recurring, PaymentReminderService $reminders): JsonResponse
    {
        $expected = (string) BusinessProfile::current()->cron_token;
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        $result = $recurring->runDue();
        $sent = $reminders->runDue();

        return response()->json(['ok' => true, ...$result, 'reminders_sent' => $sent['sent'], 'errors' => [...$result['errors'], ...$sent['errors']]]);
    }
}
