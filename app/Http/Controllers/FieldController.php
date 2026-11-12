<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** T-14 — the delegate field app, served as a PWA route from the same Laravel app. */
class FieldController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can_('record_visit'), 403, __('sanabel.permissions.denied'));

        // Cases in the delegate's own region scope, pre-loaded so the form works offline.
        $cases = Beneficiary::query()
            // T-206 — «أُعيد للتعديل» is the delegate's queue as much as
            // «بانتظار الزيارة» is, so the field app carries it too.
            ->whereIn('status', ['draft', 'pending_visit', 'returned', 'verified', 'approved', 'published', 'needs_reassessment'])
            ->orderBy('file_number')
            // T-296 — the delegate picks the person they are going to see.
            ->get(['id', 'file_number', 'first_name', 'region_id', 'updated_at']);

        return view('field.index', ['cases' => $cases]);
    }

    public function manifest()
    {
        return response()
            ->file(public_path('field-manifest.json'), ['Content-Type' => 'application/manifest+json']);
    }
}
