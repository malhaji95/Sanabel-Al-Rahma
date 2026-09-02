<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\JobProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** No phone, no address, no name — contact goes through admin (T-31). */
class JobMarketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profiles = JobProfile::query()
            ->where('status', 'published')
            // A revoked approval hides the profile with it.
            ->whereHas('beneficiary', fn ($q) => $q->whereIn('status', ['approved', 'published']))
            ->when($request->filled('trade_key'), fn ($q) => $q->where('trade_key', $request->string('trade_key')))
            ->with('beneficiary', 'region')
            ->paginate(20);

        return response()->json([
            'data' => $profiles->getCollection()->map(fn (JobProfile $p) => [
                'id' => $p->id,
                'file_number' => $p->beneficiary->file_number,
                'trade_key' => $p->trade_key,
                'summary_ar' => $p->summary_ar,
                'area_ar' => $p->region?->ancestorOfType('area')?->name_ar,
                'availability' => $p->availability,
            ])->all(),
        ]);
    }

    public function requestContact(Request $request, JobProfile $profile): JsonResponse
    {
        $validated = $request->validate([
            'requester_name_ar' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
        ]);

        abort_unless($profile->status === 'published', 404);

        // Hard rule 1 — a read-only role never writes, on this route either.
        abort_if($request->user()->isReadOnly(), 403, __('sanabel.permissions.read_only'));
        abort_unless($request->user()->can_('browse_job_market'), 403, __('sanabel.permissions.denied'));

        \App\Models\JobRequest::create([
            'requester_name_ar' => $validated['requester_name_ar'],
            'contact_encrypted' => $validated['contact'],
            'trade_key' => $profile->trade_key,
            'region_id' => $profile->region_id,
            'job_profile_id' => $profile->id,
            'description_ar' => $validated['description_ar'] ?? null,
            'status' => 'new',
        ]);

        // The requester never receives the family's details; admin makes contact.
        return response()->json(['message' => __('sanabel.job_profile.contact_received')], 201);
    }
}
