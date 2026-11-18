<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('rating_reasons_enabled')->default(false);
            $table->boolean('rating_reasons_required')->default(false);
        });
        Schema::table('question_options', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating_value')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->index(['question_id', 'rating_value', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('question_options', function (Blueprint $table) {
            $table->dropIndex(['question_id', 'rating_value', 'position']);
            $table->dropColumn(['rating_value', 'position']);
        });
        Schema::table('questions', fn (Blueprint $table) => $table->dropColumn(['rating_reasons_enabled', 'rating_reasons_required']));
    }
};
