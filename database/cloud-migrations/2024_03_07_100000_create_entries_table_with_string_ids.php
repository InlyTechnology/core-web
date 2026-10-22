<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statamic\Eloquent\Database\BaseMigration as Migration;

return new class extends Migration
{
    public function up()
    {
        // The eloquent driver's own copy, with string ids (the home page's id is `home`) and json, not jsonb, which
        // re-sorts keys and so changes every file an export back to content/ writes.
        Schema::create($this->prefix('entries'), function (Blueprint $table) {
            $table->string('id');
            $table->string('site')->index();
            $table->string('origin_id')->nullable()->index();
            $table->boolean('published')->default(true);
            $table->string('slug')->nullable();
            $table->string('uri')->nullable()->index();
            $table->string('date')->nullable()->index();
            $table->integer('order')->nullable()->index();
            $table->string('collection')->index();
            $table->string('blueprint', 30)->nullable()->index();
            $table->json('data');
            $table->timestamps();

            $table->primary('id');
        });
    }

    public function down()
    {
        Schema::dropIfExists($this->prefix('entries'));
    }
};
