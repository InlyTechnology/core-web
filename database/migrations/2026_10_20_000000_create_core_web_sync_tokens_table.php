<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Content sync tokens kept in the database (core-web.content_sync.tokens.driver = database): only their sha256, never the token. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('core-web.content_sync.tokens.connection');
    }

    public function up(): void
    {
        Schema::create(config('core-web.content_sync.tokens.table', 'core_web_sync_tokens'), function (Blueprint $table) {
            $table->char('hash', 64)->primary();
            $table->uuid('id')->unique();
            $table->string('user_id')->index();
            $table->string('machine');
            $table->dateTime('created_at');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('core-web.content_sync.tokens.table', 'core_web_sync_tokens'));
    }
};
