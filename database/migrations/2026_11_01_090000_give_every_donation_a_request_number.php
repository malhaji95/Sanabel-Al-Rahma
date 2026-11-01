<?php

use App\Models\Donation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | T-203 — «ضروري كل تبرع ياخذ رقم تذكرة أو طلب», 1 November.
 |
 | A donation has carried `transaction_ref` since the first day, and that is
 | the bank's number, typed by the donor off their own transfer slip. It is
 | the right thing to match the money against and the wrong thing to talk
 | about: the association cannot quote it before the donor sends it, two
 | people reading the same slip read it differently, and a donor who mistyped
 | it has no number at all.
 |
 | So the platform issues its own, the way it already does for a payment order
 | (DO-26-00001) and for a complaint (CMP-26-00001). `transaction_ref` stays
 | exactly what it is and keeps its unique index: one is the bank's, the other
 | is ours, and rule 1 is untouched.
 |
 | Written nullable, backfilled in order of creation, then made unique — the
 | column cannot be born NOT NULL on a table that already has rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('reference_no')->nullable()->after('id');
        });

        $byYear = [];

        Donation::withTrashed()->orderBy('id')->chunkById(200, function ($donations) use (&$byYear) {
            foreach ($donations as $donation) {
                $year = $donation->created_at?->format('y') ?? now()->format('y');
                $byYear[$year] = ($byYear[$year] ?? 0) + 1;

                $donation->newQuery()->withTrashed()->whereKey($donation->getKey())->update([
                    'reference_no' => sprintf('DN-%s-%05d', $year, $byYear[$year]),
                ]);
            }
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->string('reference_no')->nullable(false)->change();
            $table->unique('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropUnique(['reference_no']);
            $table->dropColumn('reference_no');
        });
    }
};
