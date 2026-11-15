<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Services\Billing\RecurringInvoiceService;
use Illuminate\Http\JsonResponse;

/**
 * GET /cron/recurring/{token}: for hosts without cron, point a free cron service
 * (e.g. cron-job.org) at this URL hourly or daily. The token is created on the
 * Recurring invoices page; a wrong token gets 404.
 */
class CronController extends Controller
{
    public function recurring(string $token, RecurringInvoiceService $service): JsonResponse
    {
        $expected = (string) BusinessProfile::current()->cron_token;
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        return response()->json(['ok' => true, ...$service->runDue()]);
    }
}
