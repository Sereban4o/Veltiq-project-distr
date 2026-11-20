<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('page_templates', function (Blueprint $table) {
            $table->id();
            // Null space_id marks a built-in global template available in every space.
            $table->foreignId('space_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('body')->nullable();
            $table->json('labels')->default('[]');
            $table->timestamps();

            $table->index('space_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_templates');
    }
};
