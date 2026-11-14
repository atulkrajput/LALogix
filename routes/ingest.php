<?php

use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

/*
| Ingest API for the products (VIVK.in, ISO, Amritix, Ayurved Dhara, …).
| Mounted at /api/ingest/v1 in bootstrap/app.php, without sessions or CSRF;
| authenticated by the product's API key (App\Http\Middleware\ProductApiKey).
*/

Route::get('/ping', [IngestController::class, 'ping']);
Route::post('/invoices', [IngestController::class, 'invoice']);
Route::post('/invoices/{externalId}/cancel', [IngestController::class, 'cancelInvoice'])->where('externalId', '[^/]+');
Route::post('/credit-notes', [IngestController::class, 'creditNote']);
Route::post('/credit-notes/{externalId}/cancel', [IngestController::class, 'cancelCreditNote'])->where('externalId', '[^/]+');
