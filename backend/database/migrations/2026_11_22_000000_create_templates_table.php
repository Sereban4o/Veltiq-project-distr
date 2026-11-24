<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uploadable templates (docs/13-template-system.md).
 *
 * The React SPA ships as the built-in template and is active by default; a
 * custom template is just files on disk plus this row. Only one row is active
 * at a time (enforced by the model), so the front controller never has to guess.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('slug', 64)->unique();
            $table->string('version', 32)->default('1.0.0');
            $table->string('author', 128)->nullable();
            $table->text('description')->nullable();
            $table->string('storage_path', 512)->default('');
            $table->string('main_file', 256)->default('index.html');
            $table->boolean('requires_auth')->default(true);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_builtin')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_builtin']);
        });

        DB::table('templates')->insert([
            'name' => 'Veltiq SPA (встроенный)',
            'slug' => 'default',
            'version' => '1.0.0',
            'author' => 'Veltiq',
            'description' => 'Встроенное React-приложение. Активируйте его, чтобы вернуться к стандартному интерфейсу.',
            'storage_path' => '',
            'main_file' => '',
            'requires_auth' => true,
            'is_active' => true,
            'is_builtin' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
