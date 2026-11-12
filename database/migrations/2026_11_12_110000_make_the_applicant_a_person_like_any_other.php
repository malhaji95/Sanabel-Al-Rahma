<?php

use App\Services\DependencyRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-301 — «أن يُعامل مقدم الطلب كفرد من أفراد الأسرة في جميع المعادلات»,
 * 12 November.
 *
 * T-284 put the applicant into the pricing and into the household's size.
 * What it did not do was give them the two answers every other person in the
 * file carries: whether they are a dependant, and whether a documented
 * condition keeps them from earning. Those two are what the additional items
 * and the vulnerability factor read, and the applicant had neither — so a
 * grandmother of seventy keeping two grandchildren opened no elderly item and
 * counted as nobody's carer.
 *
 * So the file carries them now, derived from the same rules and editable in
 * the same way, and the engine reads the household as people rather than as
 * rows-plus-an-exception.
 *
 * The backfill is the derivation applied to what is already on each file: an
 * applicant of sixty-five or over with no work income is a dependant, exactly
 * as the rules would have said had they been asked. Nobody is marked unable
 * to earn, because that is a documented condition and no file documents one
 * yet — a thing nobody has stated is not a thing to assume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->boolean('dependent')->default(false)->after('work_income');
            $table->boolean('has_documented_condition')->default(false)->after('dependent');
            $table->boolean('unable_to_earn')->default(false)->after('has_documented_condition');
        });

        DB::table('beneficiaries')
            ->whereNotNull('birth_date')
            ->orderBy('id')
            ->select(['id', 'birth_date', 'work_income'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $age = (int) Carbon\Carbon::parse($row->birth_date)->diffInYears(now());

                    DB::table('beneficiaries')->where('id', $row->id)->update([
                        'dependent' => DependencyRules::isDependent(
                            $age,
                            isStudent: false,
                            hasOwnIncome: (int) $row->work_income > 0,
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['dependent', 'has_documented_condition', 'unable_to_earn']);
        });
    }
};
