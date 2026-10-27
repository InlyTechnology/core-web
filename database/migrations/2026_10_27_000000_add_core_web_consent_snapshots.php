<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What each consent row was given on: its fingerprint and locale, and the banner each fingerprint stands for. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('core-web.consent.log.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $log = config('core-web.consent.log.table', 'core_web_consents');
        $snapshots = config('core-web.consent.log.snapshot_table', 'core_web_consent_snapshots');

        if ($schema->hasTable($log) && ! $schema->hasColumn($log, 'fingerprint')) {
            $schema->table($log, function (Blueprint $table) {
                $table->char('fingerprint', 64)->nullable()->index();
                $table->string('locale', 32)->nullable();
                $table->index(['site', 'policy_version', 'fingerprint', 'action'], 'core_web_consents_banner_index');
            });
        }

        if (! $schema->hasTable($snapshots)) {
            $schema->create($snapshots, function (Blueprint $table) {
                $table->char('fingerprint', 64)->primary();
                $table->string('site', 64);
                $table->string('locale', 32);
                $table->unsignedInteger('policy_version');
                $table->json('content');
                $table->dateTime('created_at');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $log = config('core-web.consent.log.table', 'core_web_consents');

        $schema->dropIfExists(config('core-web.consent.log.snapshot_table', 'core_web_consent_snapshots'));

        if ($schema->hasColumn($log, 'fingerprint')) {
            $schema->table($log, function (Blueprint $table) {
                $table->dropIndex('core_web_consents_banner_index');
                $table->dropIndex(['fingerprint']);
                $table->dropColumn(['fingerprint', 'locale']);
            });
        }
    }
};
