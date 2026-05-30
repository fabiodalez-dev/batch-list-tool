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

        $id = ($raw === null || $raw === '' || $raw === 'all')
            ? null
            : (int) $raw;

        $active->set($id);

        return redirect()->back();
    }
}
