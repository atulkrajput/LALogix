<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301 www.lalogix.com/... → https://lalogix.com/... (the host in SITE_URL).
 * public/.htaccess does the same before PHP starts; this covers servers
 * where that rule is not active.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = config('lalogix.site_url');
        $canonicalHost = parse_url($site, PHP_URL_HOST);

        if ($canonicalHost && strtolower($request->getHost()) === 'www.'.strtolower($canonicalHost)) {
            return redirect()->away($site.$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
