<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The url column switches to Laravel's encrypted cast. Rows written
     * before that still hold the plain URL and would fail to decrypt on read,
     * so they are encrypted in place. A row that already decrypts is left
     * alone, which makes this safe to run twice.
     */
    public function up(): void
    {
        DB::table('webhook_endpoints')->orderBy('id')->each(function (object $endpoint) {
            try {
                Crypt::decryptString($endpoint->url);

                return;
            } catch (DecryptException) {
                DB::table('webhook_endpoints')
                    ->where('id', $endpoint->id)
                    ->update(['url' => Crypt::encryptString($endpoint->url)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('webhook_endpoints')->orderBy('id')->each(function (object $endpoint) {
            DB::table('webhook_endpoints')
                ->where('id', $endpoint->id)
                ->update(['url' => Crypt::decryptString($endpoint->url)]);
        });
    }
};
