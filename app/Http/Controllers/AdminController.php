<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdf;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRM screens. Each page loads its data from the JSON API in routes/api.php,
 * exactly like the old Next.js admin did.
 */
class AdminController extends Controller
{
    private function render(string $component, array $props = []): Response
    {
        $meta = Seo::resolve(['title' => 'Admin CRM', 'noindex' => true]);

        return Inertia::render("Admin/{$component}", [...$props, 'meta' => ['title' => $meta['title']]])
            ->withViewData(['seo' => $meta]);
    }

    public function login(): Response
    {
        return $this->render('Login');
    }

    public function dashboard(): Response
    {
        return $this->render('Dashboard');
    }

    public function leads(): Response
    {
        return $this->render('Leads/Index');
    }

    public function lead(int $id): Response
    {
        return $this->render('Leads/Show', ['id' => $id]);
    }

    public function campaigns(): Response
    {
        return $this->render('Campaigns/Index');
    }

    public function campaign(int $id): Response
    {
        return $this->render('Campaigns/Show', ['id' => $id]);
    }

    public function demos(): Response
    {
        return $this->render('Demos');
    }

    public function followups(): Response
    {
        return $this->render('Followups');
    }

    public function outreach(): Response
    {
        return $this->render('Outreach');
    }

    public function automation(): Response
    {
        return $this->render('Automation');
    }

    public function activities(): Response
    {
        return $this->render('Activities');
    }

    // ---- GST billing ----------------------------------------------------------

    public function invoices(): Response
    {
        return $this->render('Billing/Invoices/Index', ['type' => 'invoice']);
    }

    public function creditNotes(): Response
    {
        return $this->render('Billing/Invoices/Index', ['type' => 'credit_note']);
    }

    public function invoiceCreate(Request $request): Response
    {
        return $this->render('Billing/Invoices/Edit', [
            'id' => null,
            'clientId' => $request->integer('client') ?: null,
            'workIds' => array_values(array_filter(array_map('intval', explode(',', (string) $request->query('work'))))),
        ]);
    }

    public function invoice(int $id): Response
    {
        return $this->render('Billing/Invoices/Show', ['id' => $id]);
    }

    public function invoiceEdit(int $id): Response
    {
        return $this->render('Billing/Invoices/Edit', ['id' => $id, 'clientId' => null, 'workIds' => []]);
    }

    public function invoicePdf(Request $request, int $id): HttpResponse
    {
        $invoice = Invoice::findOrFail($id);
        // Product-issued invoices have their own PDF in that product; ours would differ from what the customer got.
        abort_if($invoice->source !== 'manual', 404);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response(InvoicePdf::render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.InvoicePdf::filename($invoice).'"',
        ]);
    }

    public function clients(): Response
    {
        return $this->render('Billing/Clients/Index');
    }

    public function client(int $id): Response
    {
        return $this->render('Billing/Clients/Show', ['id' => $id]);
    }

    public function work(): Response
    {
        return $this->render('Billing/Work');
    }

    public function products(): Response
    {
        return $this->render('Billing/Products');
    }

    public function billingSettings(): Response
    {
        return $this->render('Billing/Settings');
    }

    public function gstReports(): Response
    {
        return $this->render('Billing/Reports');
    }

    public function billingImport(): Response
    {
        return $this->render('Billing/Import', ['ingestUrl' => url('/api/ingest/v1')]);
    }
}
