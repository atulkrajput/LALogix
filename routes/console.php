<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recurring invoices: create what is due. Also runs from admin page loads and the
// secret /cron/recurring/{token} URL, so a server cron is optional:
//   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
Artisan::command('billing:recurring', function (\App\Services\Billing\RecurringInvoiceService $service) {
    $r = $service->runDue();
    $this->info("Created {$r['created']}, issued {$r['issued']}, e-mailed {$r['emailed']}.");
    foreach ($r['errors'] as $error) {
        $this->warn($error);
    }
})->purpose('Create invoices that recurring schedules are due for');

\Illuminate\Support\Facades\Schedule::command('billing:recurring')->hourly()->withoutOverlapping();
