<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | T-251 — «بعد تأكيد الدفع، ينشئ النظام رقمًا مرجعيًا فريدًا للإهداء …
 | ينشئ رابط تحقق خاصًا بالبطاقة ورمز QR مرتبطًا به», 4 November.
 |
 | The token has been the card's address since T-241, but an address is
 | not a reference: it is thirty-two random characters nobody reads aloud
 | and nobody quotes to the association over the telephone. The reference
 | is the readable one, in the shape every other reference in this system
 | already has (DN-26-00001 for a donation, DO-26-… for a payment order).
 |
 | The two are different on purpose. The reference is printed on the card
 | and said out loud; the token is the private address the QR points at,
 | and stays unguessable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gifts', function (Blueprint $table) {
            $table->string('reference_no')->nullable()->after('id');
        });

        // Rows written before today get theirs in order, so the unique index
        // below can be added without a gap.
        $year = now()->format('y');

        foreach (DB::table('gifts')->orderBy('id')->pluck('id') as $index => $id) {
            DB::table('gifts')->where('id', $id)->update([
                'reference_no' => sprintf('GFT-%s-%05d', $year, $index + 1),
            ]);
        }

        Schema::table('gifts', function (Blueprint $table) {
            $table->string('reference_no')->nullable(false)->change();
            $table->unique('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('gifts', function (Blueprint $table) {
            $table->dropUnique(['reference_no']);
            $table->dropColumn('reference_no');
        });
    }
};
