<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Gstr2bEntry;
use App\Models\Purchase;
use App\Services\Billing\Gstr2bService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class Gstr2bController extends Controller
{
    public function __construct(private Gstr2bService $service) {}

    public function show(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', '');
        if (! preg_match('/^(0[1-9]|1[0-2])\d{4}$/', $period)) {
            $latest = Gstr2bEntry::query()->select('return_period')->distinct()->pluck('return_period')
                ->sortByDesc(fn ($p) => substr($p, 2).substr($p, 0, 2))->first();
            if (! $latest) {
                return response()->json(['period' => null, 'entries' => [], 'books_only' => [], 'summary' => null, 'periods' => []]);
            }
            $period = $latest;
        }

        return response()->json($this->service->report($period));
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:20480'], ['file.max' => 'The file must be smaller than 20 MB.']);

        $result = $this->service->import((string) file_get_contents($request->file('file')->getRealPath()));

        return response()->json(['result' => $result, ...$this->service->report($result['period'])]);
    }

    /** Re-run matching (e.g. after adding missing bills). */
    public function reconcile(string $period): JsonResponse
    {
        abort_unless(preg_match('/^(0[1-9]|1[0-2])\d{4}$/', $period), 404);
        $this->service->reconcile($period);

        return response()->json($this->service->report($period));
    }

    public function createPurchase(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', Rule::in(array_keys(Purchase::CATEGORIES))]]);
        $entry = Gstr2bEntry::findOrFail($id);
        if ($entry->purchase_id) {
            return response()->json(['error' => 'This document is already linked to a purchase.'], 422);
        }
        $purchase = $this->service->createPurchase($entry, $data['category'] ?? 'other');

        return response()->json(['purchase' => $purchase, ...$this->service->report($entry->return_period)], 201);
    }
}
