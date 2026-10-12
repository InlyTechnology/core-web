<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The `database` snapshot store's own tables (core-web.snapshots.store_driver = database), apart from the default cache's. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('core-web.snapshots.database.connection');
    }

    public function up(): void
    {
        Schema::create(config('core-web.snapshots.database.table', 'core_snapshots_cache'), function (Blueprint $table) {
            $table->string('key')->primary();
            // A whole snapshot is one value, and can outgrow MySQL's mediumtext.
            $table->longText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create(config('core-web.snapshots.database.lock_table', 'core_snapshots_cache_locks'), function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('core-web.snapshots.database.lock_table', 'core_snapshots_cache_locks'));
        Schema::dropIfExists(config('core-web.snapshots.database.table', 'core_snapshots_cache'));
    }
};
