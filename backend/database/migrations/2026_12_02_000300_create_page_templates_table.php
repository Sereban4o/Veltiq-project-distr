<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // MySQL cannot use a literal default for JSON columns; the value is
        // always provided by the application, so only PostgreSQL/SQLite get one.
        $driver = DB::connection()->getDriverName();

        Schema::create('page_templates', function (Blueprint $table) use ($driver) {
            $table->id();
            // Null space_id marks a built-in global template available in every space.
            $table->foreignId('space_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('body')->nullable();
            $labels = $table->json('labels');
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                $labels->default('[]');
            }
            $table->timestamps();

            $table->index('space_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_templates');
    }
};
