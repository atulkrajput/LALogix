<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/chat — the website chat widget. Proxies the conversation to the
 * VIVK API (VIVK_API_URL / VIVK_API_KEY in .env, never sent to the browser)
 * with a system prompt built from config/lalogix.php.
 *
 * TODO: confirm the VIVK API contract. This sends an OpenAI-style
 * chat-completions body ({model, messages}) with a Bearer key and reads
 * choices[0].message.content (or reply / message / content / response).
 */
class ChatController extends Controller
{
    public function reply(Request $request): JsonResponse
    {
        abort_unless(config('lalogix.chat.enabled'), 404);

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:1000'],
        ]);

        $handle = Site::company()['whatsapp_handle'];
        $fallback = 'Sorry, I cannot answer right now. Please '
            .($handle ? "message us on WhatsApp ({$handle}) or " : '')
            .'book a free demo at '.config('lalogix.site_url').'/book-demo.';

        $url = config('lalogix.chat.api_url');
        if (! $url) {
            return response()->json(['reply' => $fallback, 'fallback' => true]);
        }

        $messages = [['role' => 'system', 'content' => self::systemPrompt()]];
        foreach ($data['messages'] as $m) {
            $messages[] = ['role' => $m['role'], 'content' => strip_tags($m['content'])];
        }

        try {
            $response = Http::withToken((string) config('lalogix.chat.api_key'))
                ->acceptJson()
                ->timeout(config('lalogix.chat.timeout', 20))
                ->post($url, array_filter([
                    'model' => config('lalogix.chat.model') ?: null,
                    'messages' => $messages,
                    'max_tokens' => 400,
                    'temperature' => 0.3,
                ], fn ($v) => $v !== null));

            if (! $response->successful()) {
                Log::warning('[Chat] VIVK API error', ['status' => $response->status()]);

                return response()->json(['reply' => $fallback, 'fallback' => true]);
            }

            $json = $response->json() ?? [];
            $reply = data_get($json, 'choices.0.message.content')
                ?? data_get($json, 'reply')
                ?? data_get($json, 'message')
                ?? data_get($json, 'content')
                ?? data_get($json, 'response');

            if (! is_string($reply) || trim($reply) === '') {
                return response()->json(['reply' => $fallback, 'fallback' => true]);
            }

            return response()->json(['reply' => mb_substr(trim(strip_tags($reply)), 0, 2000)]);
        } catch (Throwable $e) {
            Log::warning('[Chat] VIVK API unreachable: '.$e->getMessage());

            return response()->json(['reply' => $fallback, 'fallback' => true]);
        }
    }

    /** Grounding: company, products and FAQs from config/lalogix.php (TODOs already removed in production). */
    public static function assistantName(): string
    {
        return trim((string) config('lalogix.chat.assistant_name')) ?: 'LAL Ji';
    }

    public static function systemPrompt(): string
    {
        $c = Site::company();
        $lines = [
            'Your name is '.self::assistantName().". You are the website assistant for {$c['name']}, a software company in {$c['address']}. If asked who you are, say you are ".self::assistantName().', the LALogix assistant.',
            'LALogix sells ready-made software products only (no custom development). Answer only questions about these products, briefly (under 80 words), in the language the visitor uses (English or Hindi).',
            'Use ONLY the facts below. Never invent prices, discounts, customer numbers, testimonials or features. If a fact is missing below, say the team will confirm and suggest WhatsApp or booking a free demo.',
            'Contact: '.($c['whatsapp_handle'] ? "WhatsApp username {$c['whatsapp_handle']}, " : '')."e-mail {$c['email']}, free demo: ".config('lalogix.site_url').'/book-demo. Never give out a phone number.',
            '',
            'PRODUCTS:',
        ];

        foreach (Site::products() as $p) {
            $features = implode('; ', array_filter(array_map(fn ($f) => $f['title'].($f['text'] ? ": {$f['text']}" : ''), $p['features'] ?? [])));
            $lines[] = "- {$p['name']}".($p['category'] ? " ({$p['category']})" : '').($p['status'] === 'coming_soon' ? ' [coming soon]' : '');
            foreach ([
                'Promise' => $p['promise'] ?? null,
                'Highlights' => implode(', ', $p['bullets'] ?? []),
                'Features' => $features,
                'Price' => $p['price_hint'] ?? null,
                'Trial' => $p['trial'] ?? null,
                'Demo' => $p['demo']['url'] ?? null,
                'Website' => $p['site_url'] ?? null,
                'Page' => config('lalogix.site_url')."/products/{$p['slug']}",
            ] as $label => $value) {
                if ($value) {
                    $lines[] = "  {$label}: {$value}";
                }
            }
            foreach ($p['faq'] ?? [] as $f) {
                if (! empty($f['a'])) {
                    $lines[] = "  Q: {$f['q']} A: {$f['a']}";
                }
            }
        }

        $lines[] = '';
        $lines[] = 'GENERAL FAQ:';
        foreach (Site::clean(config('lalogix.home.faq')) as $f) {
            if (! empty($f['a'])) {
                $lines[] = "Q: {$f['q']} A: {$f['a']}";
            }
        }

        return implode("\n", $lines);
    }
}
