<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The consent log (core-web.consent.log): one row per choice, keyed by the visitor's random consent id. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('core-web.consent.log.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $name = config('core-web.consent.log.table', 'core_web_consents');

        if ($schema->hasTable($name)) {
            return;
        }

        $schema->create($name, function (Blueprint $table) {
            $table->id();
            $table->uuid('consent_id')->index();
            $table->string('site', 64);
            $table->string('action', 16);
            $table->json('categories');
            $table->unsignedInteger('policy_version');
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(config('core-web.consent.log.table', 'core_web_consents'));
    }
};
