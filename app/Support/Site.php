<?php

namespace App\Support;

/**
 * Read access to the website content in config/lalogix.php.
 *
 * Every value goes through clean(): while `lalogix.show_todos` is false
 * (production by default) "TODO:" placeholders are removed, so they never
 * reach the public site, the SEO tags or the chat assistant.
 */
class Site
{
    /** Fallbacks used when a TODO value is removed. */
    private const FALLBACKS = [
        'price_hint' => 'Price on request',
    ];

    public static function showTodos(): bool
    {
        return (bool) config('lalogix.show_todos');
    }

    public static function isTodo(?string $value): bool
    {
        return $value !== null && str_contains($value, 'TODO');
    }

    /**
     * Strip TODO placeholders (recursively) unless show_todos is on.
     * - "Real text. TODO: more" keeps "Real text."
     * - a string that is only/partly a TODO becomes null
     * - list items that become null, or rows whose title/q became null, are dropped
     */
    public static function clean(mixed $value, ?string $key = null): mixed
    {
        if (self::showTodos()) {
            return $value;
        }

        if (is_string($value)) {
            if (! self::isTodo($value)) {
                return $value;
            }
            $pos = strpos($value, 'TODO');
            $before = rtrim(substr($value, 0, $pos));
            if ($before !== '' && preg_match('/[.!?]$/u', $before) && str_starts_with(substr($value, $pos), 'TODO:')) {
                return $before;
            }

            return self::FALLBACKS[$key] ?? null;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::clean($v, is_string($k) ? $k : $key);
            }
            if (array_is_list($value)) {
                $out = array_values(array_filter($out, function ($item) {
                    if ($item === null) {
                        return false;
                    }
                    if (is_array($item)) {
                        foreach (['title', 'q', 'quote', 'value'] as $required) {
                            if (array_key_exists($required, $item) && $item[$required] === null) {
                                return false;
                            }
                        }
                    }

                    return true;
                }));
            }

            return $out;
        }

        return $value;
    }

    public static function company(): array
    {
        $c = config('lalogix.company');
        $username = self::whatsappUsername();
        unset($c['whatsapp_username']);

        return [
            ...self::clean($c),
            'address' => trim(implode(', ', array_filter([
                self::isTodo($c['street']) ? null : $c['street'],
                $c['city'], $c['state'], $c['country'],
            ])), ', '),
            // WhatsApp by username only (no phone number on the site). Null until configured.
            'whatsapp' => $username,
            'whatsapp_handle' => $username ? '@'.$username : null,
        ];
    }

    /** The WhatsApp username without "@", or null while it is not set. */
    public static function whatsappUsername(): ?string
    {
        $u = ltrim(trim((string) config('lalogix.company.whatsapp_username')), '@');

        return ($u === '' || self::isTodo($u) || ! preg_match('/^[A-Za-z0-9._]{3,35}$/', $u)) ? null : $u;
    }

    /** @return array<string, array> all products keyed by slug, cleaned */
    public static function products(): array
    {
        $out = [];
        foreach (config('lalogix.products') as $slug => $p) {
            $out[$slug] = self::clean(['slug' => $slug, ...$p]);
        }

        return $out;
    }

    public static function product(string $slug): ?array
    {
        return self::products()[$slug] ?? null;
    }

    public static function slugs(): array
    {
        return array_keys(config('lalogix.products'));
    }

    /** product_interest values accepted from website forms. */
    public static function leadKeys(): array
    {
        return array_values(array_unique([...array_column(config('lalogix.products'), 'lead_key'), 'general']));
    }

    /** Fields every page needs (navigation, footer, floating buttons). */
    public static function shared(): array
    {
        $products = array_map(fn ($p) => [
            'slug' => $p['slug'],
            'name' => $p['name'],
            'category' => $p['category'],
            'icon' => $p['icon'],
            'status' => $p['status'],
            'in_house' => ! empty($p['in_house']),
            'lead_key' => $p['lead_key'],
            'whatsapp_text' => $p['whatsapp_text'],
        ], array_values(self::products()));

        return [
            'company' => self::company(),
            'products' => $products,
            'showTodos' => self::showTodos(),
            'calendlyUrl' => config('lalogix.calendly_url') ?: null,
            'chatEnabled' => (bool) config('lalogix.chat.enabled'),
            'chatAskAfter' => (int) config('lalogix.chat.ask_contact_after', 3),
            'chatName' => trim((string) config('lalogix.chat.assistant_name')) ?: 'LAL Ji',
            'exitOffer' => self::clean(config('lalogix.exit_offer')),
        ];
    }

    /** Card data for product grids (home, /products, /pricing). */
    /**
     * Card data for the business products (home grid, /products, /pricing, related).
     * In-house apps (in_house => true, e.g. AISHO) are not offered to businesses, so they
     * are left out of these comparisons; they keep their own product page.
     */
    public static function cards(): array
    {
        $business = array_filter(self::products(), fn ($p) => empty($p['in_house']));

        return array_map(fn ($p) => array_intersect_key($p, array_flip([
            'slug', 'name', 'brand', 'category', 'icon', 'status', 'lead_key', 'promise', 'bullets',
            'price_hint', 'trial', 'demo', 'site_url', 'play_store_url', 'billed', 'screenshots', 'book_demo',
        ])), array_values($business));
    }

    public static function whatsappUrl(string $text): ?string
    {
        $u = self::whatsappUsername();

        return $u ? 'https://wa.me/'.$u.'?text='.rawurlencode($text) : null;
    }
}
