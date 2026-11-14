<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\Billing\ExternalInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingest API (routes/ingest.php) used by the products to send the GST documents
 * they issue. Authenticated by the product's API key (ProductApiKey middleware).
 */
class IngestController extends Controller
{
    public function __construct(private ExternalInvoiceService $service) {}

    public function ping(Request $request): JsonResponse
    {
        $product = $this->product($request);

        return response()->json(['ok' => true, 'product' => $product->name, 'invoice_prefix' => $product->invoice_prefix]);
    }

    public function invoice(Request $request): JsonResponse
    {
        $result = $this->service->recordInvoice($this->product($request), $request->json()->all(), 'api', $request->ip());

        return $this->respond($result);
    }

    public function creditNote(Request $request): JsonResponse
    {
        $result = $this->service->recordCreditNote($this->product($request), $request->json()->all(), 'api', $request->ip());

        return $this->respond($result);
    }

    public function cancelInvoice(Request $request, string $externalId): JsonResponse
    {
        return $this->respond($this->service->cancel($this->product($request), Invoice::TYPE_INVOICE, $externalId, $request->input('reason'), 'api', $request->ip()));
    }

    public function cancelCreditNote(Request $request, string $externalId): JsonResponse
    {
        return $this->respond($this->service->cancel($this->product($request), Invoice::TYPE_CREDIT_NOTE, $externalId, $request->input('reason'), 'api', $request->ip()));
    }

    private function product(Request $request): Product
    {
        return $request->attributes->get('product');
    }

    /** @param  array{outcome: string, invoice: Invoice}  $result */
    private function respond(array $result): JsonResponse
    {
        $inv = $result['invoice'];

        return response()->json([
            'outcome' => $result['outcome'],
            'id' => $inv->id,
            'number' => $inv->number,
            'status' => $inv->status,
            'supply_type' => $inv->supply_type,
            'taxable_total' => $inv->taxable_total,
            'tax_total' => $inv->tax_total,
            'grand_total' => $inv->grand_total,
        ], $result['outcome'] === 'created' ? 201 : 200);
    }
}
