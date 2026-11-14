<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CrmController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\LeadController;
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
});
