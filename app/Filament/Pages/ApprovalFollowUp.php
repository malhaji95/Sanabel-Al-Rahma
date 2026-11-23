<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\Region;
use App\Models\Setting;
use App\Models\User;
use App\Services\CaseService;
use App\Services\PermissionService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * T-125 — who is holding a file up, and for how long.
 *
 * «يجب ان تظهر كم حالة متأخرة للمندوب او كم المدة يلي عدت 24 ساعة او 48 ساعة
 * … ولما يفوت مشرف المنطقة على حالات بانتظار اعتماد المندوب يقدر يشوف الحالات
 * لكل مندوب ويشوف وين التأخر حتى هو يتواصل مع المندوب المحدد», 26 October.
 *
 * The dashboard counted the queue and said nothing about whose it was. Two
 * figures do not tell a director which delegate to call, and a supervisor
 * reading «twelve waiting» cannot tell whether that is one person's week or
 * four people's day.
 *
 * So the same two queues, broken down by the person they wait on and by how
 * long they have waited. The hours are measured from the moment each file
 * began waiting, which is a different column for each queue and is named on
 * the screen rather than assumed:
 *
 *   · waiting on the delegate — from when the file was opened;
 *   · waiting on the supervisor — from the delegate's own sign-off.
 *
 * Nothing here is new data. It is the same rows the lists hold, counted the
 * way the association asked to read them, and every row opens the list it
 * was counted from.
 */
class ApprovalFollowUp extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static string $view = 'filament.pages.approval-follow-up';

    /*
     | T-316 — «مُهَل الاستجابة», phase two, item 2.
     |
     | The two lines the association drew on 26 October were constants here.
     | They are reference amounts, and a reference amount is data (§5): these
     | are the hours by which the association judges its own desks, and it has
     | to be able to move them without us. So they are settings, with the
     | agreed twenty-four and forty-eight as their defaults, and the column
     | headings read the number rather than spelling it out.
     */
    public static function lateHours(): int
    {
        return max(1, (int) Setting::value(
            'follow_up_late_hours',
            config('sanabel.setting_defaults.follow_up_late_hours')
        ));
    }

    /**
     * Always at least an hour past the first line. Two lines at the same hour
     * would leave the middle band empty for ever, and a second line below the
     * first would put every late file in both bands at once.
     */
    public static function veryLateHours(): int
    {
        return max(static::lateHours() + 1, (int) Setting::value(
            'follow_up_very_late_hours',
            config('sanabel.setting_defaults.follow_up_very_late_hours')
        ));
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.assessment');
    }

    public function getTitle(): string
    {
        return __('sanabel.follow_up.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.follow_up.title');
    }

    /**
     * The same gate the operational reports use: an account whose reports are
     * the association's rather than its own work.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user?->can_('view_reports')) {
            return false;
        }

        return in_array(
            app(PermissionService::class)->scopeFor($user, 'view_reports'),
            ['all', 'area'],
            true,
        );
    }

    /**
     * Files waiting on a field visit, by the account that opened each one.
     *
     * @return array<int,array<string,mixed>>
     */
    public function awaitingDelegate(): array
    {
        $cases = Beneficiary::whereIn('status', CaseService::AWAITING_FIELD)
            ->with('region')
            ->get(['id', 'created_by', 'region_id', 'created_at']);

        return $this->rowsBy(
            $cases,
            fn (Beneficiary $case) => $case->created_by,
            fn (Beneficiary $case) => $case->created_at,
            fn (?int $id) => User::find($id)?->name ?? __('sanabel.follow_up.unassigned'),
            'pending_visit',
        );
    }

    /**
     * Files the delegate has signed and the supervisor has not, by area.
     *
     * By area rather than by person, because that is how a supervisor is
     * attached: a file waits on whoever covers the place it is in, and
     * naming them is what turns the row into a telephone call.
     *
     * @return array<int,array<string,mixed>>
     */
    public function awaitingSupervisor(): array
    {
        $cases = Beneficiary::where('status', 'verified')
            ->with('region')
            ->get(['id', 'region_id', 'field_verified_at', 'created_at']);

        return $this->rowsBy(
            $cases,
            fn (Beneficiary $case) => $this->areaIdOf($case->region),
            fn (Beneficiary $case) => $case->field_verified_at ?? $case->created_at,
            fn (?int $id) => Region::find($id)?->name_ar ?? __('sanabel.follow_up.no_area'),
            'verified',
            fn (?int $id) => $this->supervisorsOf($id),
        );
    }

    /**
     * One table, built twice: group the files, count the three age bands,
     * and remember the oldest.
     *
     * @param  Collection<int,Beneficiary>  $cases
     * @return array<int,array<string,mixed>>
     */
    private function rowsBy(
        $cases,
        callable $key,
        callable $since,
        callable $label,
        string $status,
        ?callable $people = null,
    ): array {
        $now = now();

        // Read once for the whole table rather than per row.
        $late = static::lateHours();
        $veryLate = static::veryLateHours();

        return $cases
            ->groupBy($key)
            ->map(function ($group, $id) use ($since, $label, $status, $people, $now, $late, $veryLate) {
                $hours = $group->map(fn (Beneficiary $case) => $this->hoursSince($since($case), $now));

                $id = $id === '' ? null : (int) $id;

                return [
                    'id' => $id,
                    'label' => $label($id),
                    'people' => $people ? $people($id) : null,
                    'total' => $group->count(),
                    'late' => $hours->filter(fn (float $h) => $h >= $late && $h < $veryLate)->count(),
                    'very_late' => $hours->filter(fn (float $h) => $h >= $veryLate)->count(),
                    'oldest_hours' => (int) floor($hours->max() ?? 0),
                    'url' => BeneficiaryResource::getUrl('index', [
                        'tableFilters' => ['status' => ['value' => $status]],
                    ]),
                ];
            })
            // The longest wait first: this screen exists to be acted on.
            ->sortByDesc('oldest_hours')
            ->values()
            ->all();
    }

    private function hoursSince(?Carbon $from, Carbon $now): float
    {
        return $from ? $from->diffInRealHours($now) : 0.0;
    }

    /** The area a file sits in, whether it is attached to one or to a place inside it. */
    private function areaIdOf(?Region $region): ?int
    {
        if (! $region) {
            return null;
        }

        return $region->type === 'area' ? $region->id : $region->parent_id;
    }

    /** The accounts that would endorse a file in this area. */
    private function supervisorsOf(?int $areaId): string
    {
        if (! $areaId) {
            return '—';
        }

        $places = Region::where('parent_id', $areaId)->pluck('id')->push($areaId);

        $names = User::whereHas('role', fn ($q) => $q->whereIn('key', ['area_supervisor', 'deputy_area_supervisor']))
            ->whereIn('region_id', $places)
            ->pluck('name');

        return $names->isEmpty() ? __('sanabel.follow_up.no_supervisor') : $names->join('، ');
    }
}
