<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Configures the shared Inertia browser shell for staged Vue page adoption.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template used for Inertia responses.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Return the current asset version for Inertia responses.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Return props shared by all Inertia pages.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email'),
            ],
        ];
    }
}
