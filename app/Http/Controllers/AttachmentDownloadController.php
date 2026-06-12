<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * F032 — authenticated, policy-checked download of a media-library attachment.
 *
 * Attachments (Digriet / Conservation Report / Emails — RFQ feedback1) live on
 * the private `media` disk (storage/app/private/media, outside the web docroot,
 * no public /storage URL). The ONLY way to fetch one is through this route,
 * which:
 *
 *   1. requires an authenticated panel session (route `auth` middleware),
 *   2. resolves the OWNING model via the media morph relation,
 *   3. authorizes the user with the owner's `view` policy (which, combined with
 *      RepositoryScope on the owner lookup, blocks cross-tenant access — a
 *      foreign-repo owner is invisible to a non-privileged user and resolves to
 *      null → 404),
 *   4. streams the file straight off the private disk.
 *
 * Mirrors the gating shape of BackupDownloadController (web + auth route
 * middleware + an in-controller authorization check before streaming).
 */
class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, Media $media): Response
    {
        // Only the two attachment-bearing collections are downloadable here.
        // Anything else (thumbnails, conversions, future collections) is 404 —
        // this route is exclusively the attachments egress point.
        abort_unless($media->collection_name === 'attachments', 404);

        // Resolve the owning model through the morph relation. RepositoryScope
        // applies on the owner's query for non-privileged users, so an owner in
        // a foreign repository resolves to null → 404 (no cross-tenant leak).
        $owner = $media->model;

        abort_if($owner === null, 404, 'Attachment owner not found.');

        // Authorize against the owning model's policy. AccessionPolicy /
        // DocumentPolicy::view() gate on the per-resource `view_*` permission.
        // 403 for an authenticated user without view rights on the owner.
        abort_unless((bool) $request->user()?->can('view', $owner), 403);

        // Stream from the private disk. toResponse() emits the stored file with
        // its recorded mime type and a content-disposition for the file name.
        return $media->toResponse($request);
    }
}
