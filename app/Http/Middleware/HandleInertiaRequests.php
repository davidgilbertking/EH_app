<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $state = $user?->state()->with('ancientOne')->first();
        $ancient = $state?->ancientOne;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'gameState' => fn () => $state ? [
                'ancientOne' => $ancient ? [
                    'id' => $ancient->id,
                    'slug' => $ancient->slug,
                    'name' => $ancient->name,
                    'imageUrl' => $ancient->imageUrl(),
                    'bgImageUrl' => $ancient->bgImageUrl(),
                ] : null,
                'blobs' => $state->blobs ?? [],
            ] : null,
        ];
    }
}
