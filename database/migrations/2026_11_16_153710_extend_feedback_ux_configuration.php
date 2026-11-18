<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('survey_versions', function (Blueprint $table) {
            $table->json('channels')->nullable();
            $table->json('content')->nullable();
        });
        Schema::table('questions', fn (Blueprint $table) => $table->boolean('enabled')->default(true));
        Schema::table('export_jobs', function (Blueprint $table) {
            $table->string('format', 10)->default('csv');
            $table->string('locale', 5)->default('en');
            $table->json('summary')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_jobs', fn (Blueprint $table) => $table->dropColumn(['format', 'locale', 'summary']));
        Schema::table('questions', fn (Blueprint $table) => $table->dropColumn('enabled'));
        Schema::table('survey_versions', fn (Blueprint $table) => $table->dropColumn(['channels', 'content']));
    }
};
