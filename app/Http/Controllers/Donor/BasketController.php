<?php

namespace App\Http\Controllers\Donor;

use App\Exceptions\ReservationUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Services\BasketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BasketController extends Controller
{
    public function __construct(private readonly BasketService $baskets) {}

    public function show(Request $request): JsonResponse
    {
        $basket = $this->baskets->openFor($request->user()->donor);

        return response()->json($this->present($basket));
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'beneficiary_id' => ['required', 'integer', 'exists:beneficiaries,id'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $case = Beneficiary::published()->findOrFail($validated['beneficiary_id']);
        $basket = $this->baskets->openFor($request->user()->donor);
        $this->baskets->addItem($basket, $case, $validated['amount']);

        return response()->json($this->present($basket->refresh()));
    }

    public function reserve(Request $request): JsonResponse
    {
        $basket = $this->baskets->openFor($request->user()->donor);

        try {
            $reserved = $this->baskets->reserve($basket);
        } catch (ReservationUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($reserved));
    }

    /** Basket contents are still cases — so they are still masked. */
    private function present($basket): array
    {
        $items = $basket->items()->with('beneficiary')->get();

        return [
            'status' => $basket->status,
            'reserved_until' => $basket->reserved_until?->toIso8601String(),
            'total' => $basket->total(),
            'currency' => config('sanabel.currency'),
            'items' => $items->map(fn ($item) => [
                'amount' => $item->amount,
                'case' => (new MaskedCaseResource($item->beneficiary))->resolve(),
            ])->all(),
        ];
    }
}
