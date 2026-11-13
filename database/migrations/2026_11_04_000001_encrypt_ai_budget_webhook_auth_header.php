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

        // The new code can run before this migration does, and a rerun after
        // a failure sees rows it already handled. A value that decrypts is
        // already ciphertext, so leave it: encrypting it twice would send the
        // inner ciphertext as the header.
        $this->rewrite(fn(string $value) => $this->isEncrypted($value) ? $value : Crypt::encryptString($value));
    }

    public function down(): void
    {
        // A header saved through the API can be up to 4096 characters, which
        // the old varchar(1024) cannot hold. Fail before anything is changed.
        $rows = DB::table('ai_budget')->whereNotNull('alert_webhook_auth_header')->pluck('alert_webhook_auth_header');
        foreach ($rows as $value) {
            if ($this->isEncrypted($value) && mb_strlen(Crypt::decryptString($value)) > 1024) {
                throw new \RuntimeException(
                    'ai_budget holds a webhook auth header longer than 1024 characters. Shorten or clear it, then roll back.'
                );
            }
        }

        $this->rewrite(fn(string $value) => $this->isEncrypted($value) ? Crypt::decryptString($value) : $value);

        Schema::table('ai_budget', function (Blueprint $table) {
            $table->string('alert_webhook_auth_header', 1024)->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
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
