<?php

namespace App\Http\Controllers;

use App\Models\AncientOne;
use App\Models\UserState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * State endpoints called from Vue via Inertia's `router.post / router.delete`.
 * They MUST return an Inertia-compatible response (redirect / Inertia render),
 * not raw JSON, otherwise Inertia throws:
 *   "All Inertia requests must receive a valid Inertia response, however a
 *    plain JSON response was received."
 *
 * `back()` redirects to the previous URL; Inertia handles it by re-running the
 * shared-props closure (HandleInertiaRequests::share) so the client gets a
 * fresh `gameState` prop without a full page reload.
 */
class StateController extends Controller
{
    public function setAncientOne(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => 'nullable|string|exists:ancient_ones,slug',
        ]);

        $state = UserState::firstOrNew(['user_id' => $request->user()->id]);
        if (($data['slug'] ?? null) === null) {
            $state->current_ancient_one_id = null;
        } else {
            $ancient = AncientOne::where('slug', $data['slug'])->firstOrFail();
            $state->current_ancient_one_id = $ancient->id;
        }
        $state->blobs ??= [];
        $state->save();

        // Inertia mutation requests (POST/PUT/PATCH/DELETE) should redirect
        // with 303 so the follow-up request is always a GET across browsers.
        // 302 can produce non-Inertia HTML modal overlays on some clients.
        return back(status: 303);
    }

    public function setBlobs(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'blobs' => 'present|array',
            'blobs.*.id' => 'required|string',
            'blobs.*.label' => 'required|string',
            'blobs.*.folderSlug' => 'required|string',
            'blobs.*.mode' => 'nullable|string|in:random_pos_fade,from_start_no_fade',
            // Raw Tailwind class string captured from the source button at the
            // moment the blob was created. Pure presentation, ignored server-
            // side; persisted so the blob keeps its colour across reloads.
            'blobs.*.tone' => 'nullable|string|max:500',
            // Optional icon URL (e.g. investigator portrait) rendered inside
            // the blob button on the Home screen.
            'blobs.*.imageUrl' => 'nullable|string|max:500',
        ]);

        $state = UserState::firstOrNew(['user_id' => $request->user()->id]);
        $state->blobs = $data['blobs'];
        $state->save();

        return back(status: 303);
    }

    public function clearBlobs(Request $request): RedirectResponse
    {
        $state = UserState::firstOrNew(['user_id' => $request->user()->id]);
        $state->blobs = [];
        $state->save();

        return back(status: 303);
    }
}
