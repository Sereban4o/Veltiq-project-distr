<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Outbound webhooks for external integrations (extension point, §12).
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('url', 2048);
            $table->text('secret')->nullable(); // encrypted at rest
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
