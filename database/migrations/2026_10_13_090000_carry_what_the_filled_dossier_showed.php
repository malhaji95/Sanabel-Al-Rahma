<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-69 — what a filled dossier showed that the column list did not.
 *
 * The association sent one completed survey (SR0000035) on 12 October. Reading
 * a real one against the 104 header names found ten things the names alone
 * could not show, because they are about how a field is actually used rather
 * than what it is called.
 *
 * The largest is `تصنيف الحالة`: the header is singular and the filled value
 * is «فقيرة، يتيمة» — two of them. It was built as one string on 12 October,
 * on their own answer that it was added for filtering, so every imported file
 * would have silently lost a value. It becomes a list.
 *
 * Then four consents, none of which the system had anywhere to put. One of
 * them gates a feature: a family is asked whether it agrees to receive a visit
 * from a donor, and sending a donor to a family without that answer on the
 * record is not something the system should be able to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // The dossier carries both «البلدة / الحي» and «الحي (اختياري)».
            $table->string('quarter_ar')->nullable()->after('town_ar');

            /*
             | «الدخل الشهري التقريبي للعائلة». Their own sheet computes a
             | total from the income rows as well; this is the figure the
             | family stated. Recorded, never computed from: the engine reads
             | the `incomes` rows, as it always has.
             */
            $table->bigInteger('declared_monthly_income')->nullable()->after('documented_debt');

            /*
             | The four consents on the last two pages of the dossier.
             |
             | The privacy pair is kept as two columns rather than one on
             | purpose: their own sample answers «اطلاع: لا» and «موافقة: نعم»,
             | a consent to a text nobody read. One column could not express
             | that, and it has to be expressible before it can be reported.
             */
            $table->boolean('donor_visit_consent')->nullable()->after('is_shareable');
            $table->date('donor_visit_consent_on')->nullable()->after('donor_visit_consent');
            $table->boolean('privacy_policy_seen')->nullable()->after('donor_visit_consent_on');
            $table->boolean('privacy_consent')->nullable()->after('privacy_policy_seen');
            $table->date('privacy_consent_on')->nullable()->after('privacy_consent');
            // Which text they agreed to. The association supplies the version.
            $table->string('privacy_policy_version')->nullable()->after('privacy_consent_on');
            $table->date('declaration_on')->nullable()->after('privacy_policy_version');

            // «تصنيف الحالة», as the list the filled dossier shows it to be.
            $table->json('case_classifications')->nullable()->after('zakat_category');
        });

        // Whatever single value was already recorded becomes a one-item list.
        foreach (DB::table('beneficiaries')->whereNotNull('case_classification')->get(['id', 'case_classification']) as $row) {
            DB::table('beneficiaries')->where('id', $row->id)->update([
                'case_classifications' => json_encode([$row->case_classification]),
            ]);
        }

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['case_classification']);
            $table->dropColumn('case_classification');
        });

        Schema::table('household_members', function (Blueprint $table) {
            /*
             | «تصنيف اليتم», answered on each dependant. A boolean, because
             | the only values their form offers are يتيم and يتيمة, which is
             | the same answer twice with the gender already on the row.
             |
             | Recorded, not computed from: the V factor of the score reads a
             | member's `relation` against the orphan keys in config, exactly
             | as before, so this adds nothing to a score.
             */
            $table->boolean('is_orphan')->nullable()->after('person_class');

            // A married dependant's husband, from «رقم هوية الزوج (اختياري)».
            // Somebody else's identity number is still an identity number.
            $table->text('spouse_national_id_encrypted')->nullable()->after('spouse_name_ar');
        });

        Schema::table('debts', function (Blueprint $table) {
            // The dossier asks «سبب الاستدانة» and «طبيعة الاستدانة» apart.
            $table->string('nature_ar')->nullable()->after('reason_ar');
        });

        Schema::table('assets', function (Blueprint $table) {
            // head | spouse. The dossier asks after the spouse's property in
            // its own right, and a committee judging what a family controls
            // needs to know whose it is.
            $table->string('belongs_to')->nullable()->after('kind_other_ar');
        });

        Schema::table('caregivers', function (Blueprint $table) {
            /*
             | The dossier records two different amounts: what the carer gives
             | monthly (200 in the sample) and what relatives and neighbours
             | give (50). One column could only hold one of them.
             */
            $table->bigInteger('neighbours_support')->nullable()->after('neighbours_help');
            $table->string('neighbours_relation')->nullable()->after('neighbours_support');
        });
    }

    public function down(): void
    {
        Schema::table('caregivers', fn (Blueprint $t) => $t->dropColumn(['neighbours_support', 'neighbours_relation']));
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn('belongs_to'));
        Schema::table('debts', fn (Blueprint $t) => $t->dropColumn('nature_ar'));
        Schema::table('household_members', fn (Blueprint $t) => $t->dropColumn(['is_orphan', 'spouse_national_id_encrypted']));

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('case_classification')->nullable();
        });

        foreach (DB::table('beneficiaries')->whereNotNull('case_classifications')->get(['id', 'case_classifications']) as $row) {
            $first = json_decode($row->case_classifications, true)[0] ?? null;
            DB::table('beneficiaries')->where('id', $row->id)->update(['case_classification' => $first]);
        }

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->index('case_classification');
            $table->dropColumn([
                'quarter_ar', 'declared_monthly_income', 'donor_visit_consent',
                'donor_visit_consent_on', 'privacy_policy_seen', 'privacy_consent',
                'privacy_consent_on', 'privacy_policy_version', 'declaration_on',
                'case_classifications',
            ]);
        });
    }
};
