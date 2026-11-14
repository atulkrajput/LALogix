<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\IngestLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'products' => Product::withCount('invoices')->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validated($request) + ['sort' => (int) Product::max('sort') + 1]);

        return response()->json(['product' => $product->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $data = $this->validated($request, $product);

        // Changing a prefix after documents were numbered would split the series mid-year.
        foreach (['invoice_prefix', 'credit_note_prefix'] as $field) {
            if ($product->{$field} && $data[$field] !== $product->{$field}
                && $product->invoices()->whereNotNull('number')->where('series', $product->{$field})->exists()) {
                return response()->json(['error' => 'This prefix is already used on issued documents and can no longer be changed.'], 422);
            }
        }

        $product->update($data);

        return response()->json(['product' => $product->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        if ($product->invoices()->exists()) {
            return response()->json(['error' => 'This product has invoices. Mark it inactive instead.'], 422);
        }
        $product->delete();

        return response()->json(['success' => true]);
    }

    /** Create (or replace) the product's ingest API key. The key is returned once and only its hash is kept. */
    public function generateKey(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $key = 'lxk_'.Str::random(40);
        $product->forceFill([
            'api_key_hash' => hash('sha256', $key),
            'api_key_hint' => '…'.substr($key, -4),
            'api_key_created_at' => now(),
        ])->save();

        return response()->json(['key' => $key, 'product' => $product->fresh()]);
    }

    public function revokeKey(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->forceFill(['api_key_hash' => null, 'api_key_hint' => null, 'api_key_created_at' => null])->save();

        return response()->json(['product' => $product->fresh()]);
    }

    public function logs(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        return response()->json([
            'logs' => IngestLog::where('product_id', $product->id)->latest('id')->limit(100)->get(),
            'synced' => [
                'invoices' => $product->invoices()->where('type', 'invoice')->whereIn('source', ['api', 'import'])->count(),
                'credit_notes' => $product->invoices()->where('type', 'credit_note')->whereIn('source', ['api', 'import'])->count(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $request->merge([
            'invoice_prefix' => $request->filled('invoice_prefix') ? strtoupper(trim($request->input('invoice_prefix'))) : null,
            'credit_note_prefix' => $request->filled('credit_note_prefix') ? strtoupper(trim($request->input('credit_note_prefix'))) : null,
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'kind' => ['required', Rule::in(['services', 'saas', 'ecommerce'])],
            'website' => 'nullable|string|max:255',
            'invoice_prefix' => ['nullable', 'regex:/^[A-Z0-9]{1,5}$/', 'different:credit_note_prefix',
                Rule::unique('products', 'invoice_prefix')->ignore($product?->id),
                Rule::unique('products', 'credit_note_prefix')->ignore($product?->id)],
            'credit_note_prefix' => ['nullable', 'regex:/^[A-Z0-9]{1,5}$/',
                Rule::unique('products', 'credit_note_prefix')->ignore($product?->id),
                Rule::unique('products', 'invoice_prefix')->ignore($product?->id)],
            'default_sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'default_gst_rate' => ['required', 'numeric', Rule::in(SettingsController::GST_RATES)],
            'is_active' => 'boolean',
        ], [
            'invoice_prefix.regex' => 'Use 1-5 letters or digits for the invoice prefix (GST numbers are limited to 16 characters).',
            'credit_note_prefix.regex' => 'Use 1-5 letters or digits for the credit note prefix.',
            'invoice_prefix.unique' => 'Another product already uses this prefix.',
            'credit_note_prefix.unique' => 'Another product already uses this prefix.',
            'invoice_prefix.different' => 'Invoice and credit note prefixes must differ.',
            'default_sac.regex' => 'SAC/HSN codes are 4 to 8 digits.',
        ]);

        $data['slug'] = $product?->slug ?? $this->uniqueSlug($data['name']);

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        for ($i = 2; Product::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
