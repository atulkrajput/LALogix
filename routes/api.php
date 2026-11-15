<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Billing;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CrmController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MarketingController;
use App\Http\Controllers\Api\OutreachController;
use Illuminate\Support\Facades\Route;

/*
| JSON API used by the React frontend (same paths and payloads as the old
| Next.js /api routes). Registered with the `web` middleware group under the
| /api prefix in bootstrap/app.php, so it shares the admin session and CSRF
| protection.
*/

// ---- Public (website forms + login) -----------------------------------------
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/contact', [FormController::class, 'contact']);
    Route::post('/demo', [FormController::class, 'demo']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);

// ---- Admin only ---------------------------------------------------------------
// (The old Next.js API did not check auth on these routes; they now require login.)
Route::middleware('auth')->group(function () {
    Route::get('/admin/stats', [CrmController::class, 'stats']);
    Route::get('/admin/activities', [CrmController::class, 'activities']);
    Route::post('/activity', [CrmController::class, 'storeActivity']);

    Route::get('/demo', [CrmController::class, 'demos']);
    Route::put('/demo/{id}', [CrmController::class, 'updateDemo'])->whereNumber('id');
    Route::get('/followups', [CrmController::class, 'followups']);

    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/export', [LeadController::class, 'export']);
    Route::post('/leads/import', [LeadController::class, 'import']);
    Route::get('/leads/{id}', [LeadController::class, 'show'])->whereNumber('id');
    Route::put('/leads/{id}', [LeadController::class, 'update'])->whereNumber('id');
    Route::put('/leads/{id}/followup', [LeadController::class, 'followup'])->whereNumber('id');
    Route::post('/leads/{id}/score', [LeadController::class, 'score'])->whereNumber('id');
    Route::post('/leads/{id}/tags', [LeadController::class, 'addTag'])->whereNumber('id');
    Route::delete('/leads/{id}/tags', [LeadController::class, 'removeTag'])->whereNumber('id');

    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::get('/campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id');
    Route::put('/campaigns/{id}', [CampaignController::class, 'update'])->whereNumber('id');
    Route::delete('/campaigns/{id}', [CampaignController::class, 'destroy'])->whereNumber('id');
    Route::post('/campaigns/{id}/leads', [CampaignController::class, 'addLeads'])->whereNumber('id');
    Route::delete('/campaigns/{id}/leads', [CampaignController::class, 'removeLead'])->whereNumber('id');

    Route::get('/email-templates', [OutreachController::class, 'templates']);
    Route::post('/email-templates', [OutreachController::class, 'storeTemplate']);
    Route::put('/email-templates/{id}', [OutreachController::class, 'updateTemplate'])->whereNumber('id');
    Route::delete('/email-templates/{id}', [OutreachController::class, 'destroyTemplate'])->whereNumber('id');

    Route::get('/automation', [OutreachController::class, 'rules']);
    Route::post('/automation', [OutreachController::class, 'storeRule']);
    Route::put('/automation/{id}', [OutreachController::class, 'updateRule'])->whereNumber('id');
    Route::delete('/automation/{id}', [OutreachController::class, 'destroyRule'])->whereNumber('id');

    Route::post('/outreach/email', [OutreachController::class, 'email']);
    Route::post('/outreach/whatsapp', [OutreachController::class, 'whatsapp']);
    Route::get('/outreach/whatsapp', [OutreachController::class, 'whatsappLogs']);

    // ---- Marketing (ad spend → leads → revenue) ---------------------------
    Route::get('/marketing', [MarketingController::class, 'report']);
    Route::put('/marketing/meta', [MarketingController::class, 'saveMeta']);
    Route::post('/marketing/meta/sync', [MarketingController::class, 'syncMeta']);
    Route::post('/marketing/spend/import', [MarketingController::class, 'importSpend']);

    // ---- GST billing ------------------------------------------------------
    Route::prefix('billing')->group(function () {
        Route::get('/settings', [Billing\SettingsController::class, 'show']);
        Route::put('/settings', [Billing\SettingsController::class, 'update']);

        Route::get('/products', [Billing\ProductController::class, 'index']);
        Route::post('/products', [Billing\ProductController::class, 'store']);
        Route::put('/products/{id}', [Billing\ProductController::class, 'update'])->whereNumber('id');
        Route::delete('/products/{id}', [Billing\ProductController::class, 'destroy'])->whereNumber('id');
        Route::post('/products/{id}/api-key', [Billing\ProductController::class, 'generateKey'])->whereNumber('id');
        Route::delete('/products/{id}/api-key', [Billing\ProductController::class, 'revokeKey'])->whereNumber('id');
        Route::get('/products/{id}/logs', [Billing\ProductController::class, 'logs'])->whereNumber('id');
        Route::put('/products/{id}/sync', [Billing\ProductController::class, 'saveSync'])->whereNumber('id');
        Route::post('/products/{id}/sync', [Billing\ProductController::class, 'runSync'])->whereNumber('id');
        Route::post('/import', [Billing\ImportController::class, 'store']);

        Route::get('/clients', [Billing\ClientController::class, 'index']);
        Route::post('/clients', [Billing\ClientController::class, 'store']);
        Route::post('/clients/from-lead/{leadId}', [Billing\ClientController::class, 'fromLead'])->whereNumber('leadId');
        Route::get('/clients/{id}', [Billing\ClientController::class, 'show'])->whereNumber('id');
        Route::put('/clients/{id}', [Billing\ClientController::class, 'update'])->whereNumber('id');
        Route::delete('/clients/{id}', [Billing\ClientController::class, 'destroy'])->whereNumber('id');

        Route::get('/work', [Billing\WorkItemController::class, 'index']);
        Route::post('/work', [Billing\WorkItemController::class, 'store']);
        Route::put('/work/{id}', [Billing\WorkItemController::class, 'update'])->whereNumber('id');
        Route::delete('/work/{id}', [Billing\WorkItemController::class, 'destroy'])->whereNumber('id');

        Route::get('/reports/gst', [Billing\ReportController::class, 'gst']);

        Route::get('/quotations', [Billing\QuotationController::class, 'index']);
        Route::post('/quotations', [Billing\QuotationController::class, 'store']);
        Route::get('/quotations/{id}', [Billing\QuotationController::class, 'show'])->whereNumber('id');
        Route::put('/quotations/{id}', [Billing\QuotationController::class, 'update'])->whereNumber('id');
        Route::delete('/quotations/{id}', [Billing\QuotationController::class, 'destroy'])->whereNumber('id');
        Route::post('/quotations/{id}/status', [Billing\QuotationController::class, 'status'])->whereNumber('id');
        Route::post('/quotations/{id}/convert', [Billing\QuotationController::class, 'convert'])->whereNumber('id');
        Route::post('/quotations/{id}/duplicate', [Billing\QuotationController::class, 'duplicate'])->whereNumber('id');
        Route::post('/quotations/{id}/email', [Billing\QuotationController::class, 'email'])->whereNumber('id');

        Route::get('/recurring', [Billing\RecurringInvoiceController::class, 'index']);
        Route::post('/recurring', [Billing\RecurringInvoiceController::class, 'store']);
        Route::post('/recurring/run', [Billing\RecurringInvoiceController::class, 'run']);
        Route::get('/recurring/cron-url', [Billing\RecurringInvoiceController::class, 'cronUrl']);
        Route::get('/recurring/{id}', [Billing\RecurringInvoiceController::class, 'show'])->whereNumber('id');
        Route::put('/recurring/{id}', [Billing\RecurringInvoiceController::class, 'update'])->whereNumber('id');
        Route::delete('/recurring/{id}', [Billing\RecurringInvoiceController::class, 'destroy'])->whereNumber('id');
        Route::post('/recurring/{id}/status', [Billing\RecurringInvoiceController::class, 'status'])->whereNumber('id');

        Route::get('/purchases', [Billing\PurchaseController::class, 'index']);
        Route::post('/purchases', [Billing\PurchaseController::class, 'store']);
        Route::post('/purchases/{id}', [Billing\PurchaseController::class, 'update'])->whereNumber('id');
        Route::delete('/purchases/{id}', [Billing\PurchaseController::class, 'destroy'])->whereNumber('id');
        Route::get('/vendors', [Billing\PurchaseController::class, 'vendors']);

        Route::get('/gstr2b', [Billing\Gstr2bController::class, 'show']);
        Route::post('/gstr2b/import', [Billing\Gstr2bController::class, 'import']);
        Route::post('/gstr2b/{period}/reconcile', [Billing\Gstr2bController::class, 'reconcile'])->where('period', '[0-9]{6}');
        Route::post('/gstr2b/entries/{id}/purchase', [Billing\Gstr2bController::class, 'createPurchase'])->whereNumber('id');
        Route::get('/growth', [Billing\GrowthController::class, 'show']);

        Route::get('/invoices/meta', [Billing\InvoiceController::class, 'meta']);
        Route::get('/invoices', [Billing\InvoiceController::class, 'index']);
        Route::post('/invoices', [Billing\InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [Billing\InvoiceController::class, 'show'])->whereNumber('id');
        Route::put('/invoices/{id}', [Billing\InvoiceController::class, 'update'])->whereNumber('id');
        Route::delete('/invoices/{id}', [Billing\InvoiceController::class, 'destroy'])->whereNumber('id');
        Route::post('/invoices/{id}/issue', [Billing\InvoiceController::class, 'issue'])->whereNumber('id');
        Route::post('/invoices/{id}/cancel', [Billing\InvoiceController::class, 'cancel'])->whereNumber('id');
        Route::post('/invoices/{id}/credit-note', [Billing\InvoiceController::class, 'creditNote'])->whereNumber('id');
        Route::post('/invoices/{id}/email', [Billing\InvoiceController::class, 'email'])->whereNumber('id');
        Route::post('/invoices/{id}/payments', [Billing\InvoiceController::class, 'storePayment'])->whereNumber('id');
        Route::delete('/invoices/{id}/payments/{paymentId}', [Billing\InvoiceController::class, 'destroyPayment'])->whereNumber(['id', 'paymentId']);
    });
});
