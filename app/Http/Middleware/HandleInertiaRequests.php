<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** Props available to every React page via usePage().props */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? $user->only(['id', 'name', 'email', 'role']) : null,
            ],
            'config' => [
                'calendlyUrl' => config('lalogix.calendly_url'),
            ],
        ];
    }
}
