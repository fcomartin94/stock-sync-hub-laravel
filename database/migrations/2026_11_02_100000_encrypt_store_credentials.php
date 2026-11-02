<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store credentials are now cast as `encrypted` on the model. An encrypted
 * payload is much longer than 255 characters, so the columns become TEXT, and
 * any plaintext value already stored is encrypted in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('api_key')->nullable()->change();
            $table->text('api_secret')->nullable()->change();
        });

        foreach (DB::table('stores')->get(['id', 'api_key', 'api_secret']) as $store) {
            DB::table('stores')->where('id', $store->id)->update([
                'api_key' => $this->encryptIfPlain($store->api_key),
                'api_secret' => $this->encryptIfPlain($store->api_secret),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('stores')->get(['id', 'api_key', 'api_secret']) as $store) {
            DB::table('stores')->where('id', $store->id)->update([
                'api_key' => $this->decryptIfEncrypted($store->api_key),
                'api_secret' => $this->decryptIfEncrypted($store->api_secret),
            ]);
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->string('api_key')->nullable()->change();
            $table->string('api_secret')->nullable()->change();
        });
    }

    private function encryptIfPlain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            Crypt::decryptString($value);

            return $value; // already encrypted
        } catch (\Throwable) {
            return Crypt::encryptString($value);
        }
    }

    private function decryptIfEncrypted(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
};
