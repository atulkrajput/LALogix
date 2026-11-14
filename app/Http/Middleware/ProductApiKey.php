<?php

namespace App\Http\Middleware;

use App\Models\Product;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a product (VIVK.in, Amritix, …) calling the ingest API with
 * "Authorization: Bearer lxk_…". Only the key's SHA-256 is stored.
 */
class ProductApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->bearerToken();
        $product = $key !== '' ? Product::where('api_key_hash', hash('sha256', $key))->first() : null;

        if (! $product) {
            return response()->json(['error' => 'Invalid or missing API key. Send it as "Authorization: Bearer <key>".'], 401);
        }
        if (! $product->is_active) {
            return response()->json(['error' => "{$product->name} is marked inactive in LALogix."], 403);
        }

        $request->attributes->set('product', $product);

        return $next($request);
    }
}
