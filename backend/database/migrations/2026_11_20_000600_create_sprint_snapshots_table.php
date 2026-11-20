<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('sprint_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprint_id')->constrained('sprints')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('issues_total')->default(0);
            $table->unsignedInteger('issues_done')->default(0);
            $table->decimal('points_total', 10, 2)->default(0);
            $table->decimal('points_done', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['sprint_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprint_snapshots');
    }
};
