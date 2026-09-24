<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Blind index for the vendor TIN. `tin` is an encrypted cast (random IV), so
 * two vendors with the same TIN had different ciphertext and the vendor master
 * accepted duplicates — the same supplier could be set up, billed and paid
 * twice. `tin_hash` is an HMAC of the digits only, unique among live vendors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('tin_hash', 64)->nullable()->after('tin');
        });

        // Live vendors first, oldest first: when legacy duplicates exist the
        // original keeps the hash (and the guard); later copies stay NULL so
        // the unique index can be built. Soft-deleted rows are outside the
        // index and always get their hash.
        $claimed = [];
        $rows = DB::table('vendors')
            ->whereNotNull('tin')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('id')
            ->get(['id', 'tin', 'deleted_at']);
        foreach ($rows as $row) {
            $hash = Vendor::tinHash(Crypt::decryptString($row->tin));
            if ($hash === null) {
                continue;
            }
            if ($row->deleted_at === null) {
                if (isset($claimed[$hash])) {
                    Log::warning('Duplicate vendor TIN left unindexed by 0557', [
                        'vendor_id' => $row->id,
                        'original_vendor_id' => $claimed[$hash],
                    ]);
                    continue;
                }
                $claimed[$hash] = $row->id;
            }
            DB::table('vendors')->where('id', $row->id)->update(['tin_hash' => $hash]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX vendors_tin_hash_unique ON vendors (tin_hash) WHERE tin_hash IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vendors_tin_hash_unique');
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('tin_hash');
        });
    }
};
