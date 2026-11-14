<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// ---- Public website -------------------------------------------------------
Route::controller(PageController::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/solutions', 'solutions');
    Route::get('/products/{slug}', 'product')->where('slug', 'school-management|inventory-pos|dairy-management');
    Route::get('/custom-automation', 'customAutomation');
    Route::get('/about', 'about');
    Route::get('/insights', 'insights');
    Route::get('/insights/{slug}', 'insight');
    Route::get('/contact', 'contact');
    Route::get('/book-demo', 'bookDemo');
    Route::get('/thank-you', 'thankYou');
    Route::get('/sitemap.xml', 'sitemap');
});

// Permanent redirects carried over from old/next.config.mjs.
Route::permanentRedirect('/demo', '/book-demo');
Route::permanentRedirect('/services', '/custom-automation');
Route::permanentRedirect('/blog', '/insights');
Route::get('/blog/{slug}', fn (string $slug) => redirect("/insights/{$slug}", 301));

// ---- Admin CRM -------------------------------------------------------------
Route::prefix('admin')->controller(AdminController::class)->group(function () {
    Route::get('/login', 'login')->middleware('guest')->name('login');

    Route::middleware('auth')->group(function () {
        Route::get('/', 'dashboard');
        Route::get('/growth', 'growth');
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
        Route::get('/billing/import', 'billingImport');
        Route::get('/billing/import/template', [\App\Http\Controllers\Api\Billing\ImportController::class, 'template']);
        Route::get('/billing/reports/download', [\App\Http\Controllers\Api\Billing\ReportController::class, 'download']);
    });
});
