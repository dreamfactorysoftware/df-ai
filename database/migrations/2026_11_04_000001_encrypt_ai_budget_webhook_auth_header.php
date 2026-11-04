<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * alert_webhook_auth_header is a credential. AiBudget now stores it with the
 * `encrypted` cast, and ciphertext does not fit varchar(1024). Rows written
 * before this migration hold plaintext, so encrypt them in place.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_budget', function (Blueprint $table) {
            $table->text('alert_webhook_auth_header')->nullable()->change();
        });

        $this->rewrite(fn(string $value) => Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->rewrite(fn(string $value) => Crypt::decryptString($value));

        Schema::table('ai_budget', function (Blueprint $table) {
            $table->string('alert_webhook_auth_header', 1024)->nullable()->change();
        });
    }

    private function rewrite(callable $transform): void
    {
        $rows = DB::table('ai_budget')->whereNotNull('alert_webhook_auth_header')->get(['id', 'alert_webhook_auth_header']);
        foreach ($rows as $row) {
            DB::table('ai_budget')->where('id', $row->id)->update([
                'alert_webhook_auth_header' => $transform($row->alert_webhook_auth_header),
            ]);
        }
    }
};
