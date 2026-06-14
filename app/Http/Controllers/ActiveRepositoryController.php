<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ActiveRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endpoint behind the topbar repository switcher (RFQ Wave 2 Task 10).
 *
 * Receives the chosen repository id (empty / "all" = "All repositories" = null)
 * from the switcher <select>, hands it to App\Support\ActiveRepository (which
 * validates it against the user's allowed repositories — fail-closed), then
 * redirects back so the page reloads under the new scope.
 */
class ActiveRepositoryController extends Controller
{
    public function update(Request $request, ActiveRepository $active): RedirectResponse
    {
        $raw = $request->input('repository_id');

        $id = (in_array($raw, [null, '', 'all'], true))
            ? null
            : (int) $raw;

        $active->set($id);

        // Use a fixed same-origin fallback (the admin panel root) instead of a
        // bare back(): redirect()->back() ultimately trusts the Referer header,
        // and with no safe previous URL it would 302 to "/". Pinning the
        // fallback to the panel keeps the post-switch redirect predictable and
        // same-origin regardless of the incoming Referer (review F8).
        return back(fallback: url('/admin'));
    }
}
