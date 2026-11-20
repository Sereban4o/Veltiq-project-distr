<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Admin-defined extra fields (extension point, §12). Values live in the
        // entity's own `custom_fields` JSON column, so no per-value table here.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 16); // issue | page
            $table->string('key', 64);
            $table->string('label', 120);
            $table->string('type', 16); // text | textarea | number | date | checkbox | select
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['entity', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
