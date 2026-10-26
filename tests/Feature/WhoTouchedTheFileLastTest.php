<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Region;
use App\Models\Role;
use Filament\Facades\Filament;

/*
 | T-159 — «رجعت عدلت ملف من قبل مشرف المنطقة, لازم يتم ذكر التعديل انه تم
 | بواسطة مشرف المنطقة مع اسم المستخدم, اي ان كل التعديلات يجب ان تكون
 | مسجلة», 29 October.
 |
 | Every write has been logged since the beginning (rule 4). What the file did
 | not carry was the one line everybody working it needs: who touched it last,
 | in what capacity, and when.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** A supervisor over the area a family's village belongs to. */
function supervisorOver(Region $governorate): array
{
    $delegate = userWithRole('delegate', ['region_id' => $governorate->id]);
    $village = Region::withoutGlobalScopes()->find($delegate->region_id);
    $area = $village->ancestorOfType('area');

    return [userWithRole('area_supervisor', ['region_id' => $area->id]), $village];
}

it('records the supervisor’s edit with their name and their role', function () {
    [$supervisor, $village] = supervisorOver($this->region);
    $case = familyOf($village, adults: 1, children: 0);

    $this->actingAs($supervisor);

    Livewire\Livewire::actingAs($supervisor)
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['town_ar' => 'حي جديد'])
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = AuditLog::where('entity_type', Beneficiary::class)
        ->where('entity_id', $case->getKey())
        ->latest('id')
        ->first();

    expect($entry->action)->toBe('updated')
        ->and($entry->actor_id)->toBe($supervisor->id)
        ->and($entry->actor_role)->toBe('area_supervisor');
});

it('says on the file who wrote to it last, and in what capacity', function () {
    [$supervisor, $village] = supervisorOver($this->region);
    $case = familyOf($village, adults: 1, children: 0);

    $this->actingAs($supervisor);

    Livewire\Livewire::actingAs($supervisor)
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['town_ar' => 'حي جديد'])
        ->call('save');

    $line = BeneficiaryResource::lastEditLine($case->fresh());

    expect($line)
        ->toContain($supervisor->name)
        ->toContain(__('sanabel.audit.actions.updated'))
        // The capacity, not only the person: the same name may hold another.
        ->toContain(Role::where('key', 'area_supervisor')->value('name_ar'));
});

it('names the system where nobody was signed in', function () {
    $case = familyOf($this->region, adults: 1, children: 0);

    // A seeder or an import writes with no user behind it.
    $case->forceFill(['town_ar' => 'بلا فاعل'])->save();

    expect(BeneficiaryResource::lastEditLine($case->fresh()))
        ->toBe(__('sanabel.beneficiary.last_edit_system', [
            'action' => __('sanabel.audit.actions.updated'),
            'at' => $case->fresh()->auditEntries()->latest('id')->first()->created_at->translatedFormat('Y-m-d H:i'),
        ]));
});

it('says nothing at all about a file that does not exist yet', function () {
    expect(BeneficiaryResource::lastEditLine(null))->toBeNull()
        ->and(BeneficiaryResource::lastEditLine(new Beneficiary))->toBeNull();
});
