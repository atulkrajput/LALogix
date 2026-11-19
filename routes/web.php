<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// ---- Public website -------------------------------------------------------
Route::controller(PageController::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/products/{slug}', 'product')->where('slug', 'school-management|inventory-pos');
    Route::get('/about', 'about');
    Route::get('/insights', 'insights');
    Route::get('/insights/{slug}', 'insight');
    Route::get('/contact', 'contact');
    Route::get('/book-demo', 'bookDemo');
    Route::get('/thank-you', 'thankYou');
    Route::get('/sitemap.xml', 'sitemap');

    // Policy pages (required by Razorpay), linked in the footer of every page.
    Route::get('/{slug}', 'legal')->where('slug', implode('|', array_keys(PageController::LEGAL)));
});

// Permanent redirects (old URLs and removed pages).
Route::permanentRedirect('/demo', '/book-demo');
Route::permanentRedirect('/blog', '/insights');
Route::get('/blog/{slug}', fn (string $slug) => redirect("/insights/{$slug}", 301));
Route::permanentRedirect('/custom-automation', '/products');
Route::permanentRedirect('/services', '/products');
Route::permanentRedirect('/solutions', '/industries');
Route::permanentRedirect('/products/dairy-management', '/products/amritix');

// Secret URL for an external cron service (recurring invoices and payment reminders).
Route::get('/cron/recurring/{token}', [\App\Http\Controllers\CronController::class, 'recurring'])
    ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{20,64}');

// ---- Admin CRM -------------------------------------------------------------
Route::prefix('admin')->controller(AdminController::class)->group(function () {
    Route::get('/login', 'login')->middleware('guest')->name('login');

    Route::middleware('auth')->group(function () {
        Route::get('/', 'dashboard');
        Route::get('/growth', 'growth');
        Route::get('/marketing', 'marketing');
        Route::get('/leads', 'leads');
        Route::get('/leads/{id}', 'lead')->whereNumber('id');
        Route::get('/campaigns', 'campaigns');
        Route::get('/campaigns/{id}', 'campaign')->whereNumber('id');
        Route::get('/demos', 'demos');
        Route::get('/followups', 'followups');
        Route::get('/outreach', 'outreach');
        Route::get('/automation', 'automation');
        Route::get('/activities', 'activities');

        // GST billing
        Route::redirect('/billing', '/admin/billing/invoices');
        Route::get('/billing/invoices', 'invoices');
        Route::get('/billing/invoices/new', 'invoiceCreate');
        Route::get('/billing/invoices/{id}', 'invoice')->whereNumber('id');
        Route::get('/billing/invoices/{id}/edit', 'invoiceEdit')->whereNumber('id');
        Route::get('/billing/invoices/{id}/pdf', 'invoicePdf')->whereNumber('id');
        Route::get('/billing/credit-notes', 'creditNotes');
        Route::get('/billing/clients', 'clients');
        Route::get('/billing/clients/{id}', 'client')->whereNumber('id');
        Route::get('/billing/work', 'work');
        Route::get('/billing/products', 'products');
        Route::get('/billing/settings', 'billingSettings');
        Route::get('/billing/reports', 'gstReports');
        Route::get('/billing/purchases', 'purchases');
        Route::get('/billing/purchases/{id}/attachment', [\App\Http\Controllers\Api\Billing\PurchaseController::class, 'attachment'])->whereNumber('id');
        Route::get('/billing/gstr2b', 'gstr2b');
        Route::get('/billing/quotations', 'quotations');
        Route::get('/billing/quotations/new', 'quotationCreate');
        Route::get('/billing/quotations/{id}', 'quotation')->whereNumber('id');
        Route::get('/billing/quotations/{id}/edit', 'quotationEdit')->whereNumber('id');
        Route::get('/billing/quotations/{id}/pdf', [\App\Http\Controllers\Api\Billing\QuotationController::class, 'pdf'])->whereNumber('id');
        Route::get('/billing/recurring', 'recurring');
        Route::get('/billing/receivables', 'receivables');
        Route::get('/billing/export', 'accountingExport');
        Route::get('/billing/export/download', [\App\Http\Controllers\Api\Billing\ExportController::class, 'download']);
        Route::get('/billing/import', 'billingImport');
        Route::get('/billing/import/template', [\App\Http\Controllers\Api\Billing\ImportController::class, 'template']);
        Route::get('/billing/reports/download', [\App\Http\Controllers\Api\Billing\ReportController::class, 'download']);
    });
});
