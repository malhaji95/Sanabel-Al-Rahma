<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Services\CaseService;
use App\Services\DuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Internal, full-case routes. A donor never reaches these — the Policy denies them. */
class CaseController extends Controller
{
    public function __construct(private readonly CaseService $cases) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Beneficiary::class);

        // Every list is paginated and filtered by the user's region scope.
        $cases = Beneficiary::query()->latest('id')->paginate(20);

        return response()->json([
            'data' => $cases->getCollection()->map(fn (Beneficiary $c) => [
                'id' => $c->id,
                'file_number' => $c->file_number,
                'status' => $c->status,
                'region_id' => $c->region_id,
            ])->all(),
            'meta' => ['total' => $cases->total()],
        ]);
    }

    public function show(Request $request, Beneficiary $case): JsonResponse
    {
        $this->authorize('view', $case);

        return response()->json(['data' => $case->load('members', 'incomes', 'housing')]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Beneficiary::class);

        $validated = $request->validate([
            'national_id' => ['required', 'string', 'max:64'],
            'first_name' => ['required', 'string', 'max:191'],
            'father_name' => ['required', 'string', 'max:191'],
            'family_name' => ['required', 'string', 'max:191'],
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'support_type' => ['required', 'in:monthly,one_time'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        app(DuplicateService::class)->guardAgainstDuplicate($validated['national_id']);

        $case = Beneficiary::create([
            'file_number' => 'F-'.now()->format('y').'-'.str_pad((string) (Beneficiary::withoutGlobalScopes()->max('id') + 1), 6, '0', STR_PAD_LEFT),
            'national_id_encrypted' => $validated['national_id'],
            'national_id_hash' => Beneficiary::hashNationalId($validated['national_id']),
            'first_name' => $validated['first_name'],
            'father_name' => $validated['father_name'],
            'family_name' => $validated['family_name'],
            'phone_encrypted' => $validated['phone'] ?? null,
            'region_id' => $validated['region_id'],
            'support_type' => $validated['support_type'],
            'status' => 'draft',
            'source' => $request->user()->hasRole('association') ? 'association' : 'delegate',
        ]);

        app(DuplicateService::class)->flagIfSuspicious($case);

        return response()->json(['id' => $case->id, 'file_number' => $case->file_number], 201);
    }

    public function approve(Request $request, Beneficiary $case): JsonResponse
    {
        $this->authorize('approve', $case);

        return response()->json(['status' => $this->cases->approve($case, $request->user())->status]);
    }

    public function reject(Request $request, Beneficiary $case): JsonResponse
    {
        $this->authorize('reject', $case);

        $validated = $request->validate(['reason_ar' => ['required', 'string', 'max:500']]);

        return response()->json([
            'status' => $this->cases->reject($case, $request->user(), $validated['reason_ar'])->status,
        ]);
    }

    public function publish(Request $request, Beneficiary $case): JsonResponse
    {
        $this->authorize('publish', $case);

        return response()->json(['status' => $this->cases->publish($case)->status]);
    }

    public function requestChange(Request $request, Beneficiary $case): JsonResponse
    {
        $this->authorize('requestChange', $case);

        $validated = $request->validate([
            'payload' => ['required', 'array'],
            'reason_ar' => ['required', 'string', 'max:500'],
        ]);

        $changeRequest = $this->cases->requestChange(
            $case, $request->user(), $validated['payload'], $validated['reason_ar']
        );

        return response()->json(['id' => $changeRequest->id, 'is_material' => $changeRequest->is_material], 201);
    }

    public function confirmDelivery(Request $request, Beneficiary $case): JsonResponse
    {
        abort_unless($request->user()->can_('confirm_delivery'), 403, __('sanabel.permissions.denied'));

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'proof_media_id' => ['required', 'integer'],
            'donation_id' => ['nullable', 'integer', 'exists:donations,id'],
        ]);

        $delivery = $this->cases->confirmDelivery(
            $case, $request->user(), $validated['type'], $validated['proof_media_id'], $validated['donation_id'] ?? null
        );

        return response()->json(['id' => $delivery->id], 201);
    }
}
