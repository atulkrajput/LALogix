<?php

namespace App\Http\Middleware;

use App\Support\Site;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next)
    {
        // Server-side rendering is for the public website only; the admin is
        // behind a login and renders in the browser.
        if ($request->is('admin', 'admin/*')) {
            config(['inertia.ssr.enabled' => false]);
        }

        return parent::handle($request, $next);
    }

    /** Props available to every React page via usePage().props */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? $user->only(['id', 'name', 'email', 'role']) : null,
            ],
            // Company, contact and product names from config/lalogix.php (not on admin pages).
            'site' => $request->is('admin', 'admin/*') ? null : fn () => Site::shared(),
            'config' => [
                'calendlyUrl' => config('lalogix.calendly_url'),
            ],
        ];
    }
}
