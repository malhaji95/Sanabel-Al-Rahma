<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 | T-230 — a change request stops carrying a telephone number in clear.
 |
 | Nobody reported this; it came out of reading our own comment. The T-218
 | block on `ChangeRequest::CHANGEABLE_FIELDS` says of the spouse's identity
 | number: «والرقم مشفّر at rest متل الملف صاحبه, فالطلب يحمل قيمةً لا
 | يقرؤها أحدٌ من قاعدة البيانات». That was not true. `payload_json` was
 | cast as a plain array, so the proposed new value of every encrypted
 | column — the telephone, the identity number, the wallet — sat in clear in
 | a JSON column, while the file itself encrypted the same value.
 |
 | `AnonymiseService` has said so plainly all along, in the other direction:
 | «change_requests.payload_json … holds the very value a change moved a
 | field to, so a request to change a telephone number carries that number
 | in it.» Two comments in one codebase, one of them wrong.
 |
 | The columns become text because an encrypted cast writes a ciphertext
 | string, which is not valid JSON and which Postgres would refuse in a
 | `json` column.
 |
 | Encryption at rest is not anonymisation: the payload is still emptied
 | when a file is anonymised, because a key is one compromise away from
 | being a key somebody holds.
 */
return new class extends Migration
{
    private const COLUMNS = ['payload_json', 'old_json'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            // T-259 — the same widening, said the way each engine says it.
            DB::statement(DB::getDriverName() === 'pgsql'
                ? "alter table change_requests alter column {$column} type text"
                : "alter table change_requests modify {$column} longtext");
        }

        /*
         | The stored value is already the JSON string the cast would write,
         | so encrypting it as it stands is exactly what `encrypted:array`
         | reads back. Nothing is decoded and re-encoded here, which is what
         | keeps a value with an odd escape from changing on the way.
         */
        DB::table('change_requests')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $changes = [];

                foreach (self::COLUMNS as $column) {
                    $value = $row->{$column};

                    if ($value === null || $this->isEncrypted($value)) {
                        continue;
                    }

                    $changes[$column] = Crypt::encryptString($value);
                }

                if ($changes !== []) {
                    DB::table('change_requests')->where('id', $row->id)->update($changes);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('change_requests')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $changes = [];

                foreach (self::COLUMNS as $column) {
                    $value = $row->{$column};

                    if ($value === null || ! $this->isEncrypted($value)) {
                        continue;
                    }

                    $changes[$column] = Crypt::decryptString($value);
                }

                if ($changes !== []) {
                    DB::table('change_requests')->where('id', $row->id)->update($changes);
                }
            }
        });

        foreach (self::COLUMNS as $column) {
            DB::statement(DB::getDriverName() === 'pgsql'
                ? "alter table change_requests alter column {$column} type json using {$column}::json"
                : "alter table change_requests modify {$column} json");
        }
    }

    /** So the migration may be run twice without encrypting a ciphertext. */
    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
