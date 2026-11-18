<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_job_responses', function (Blueprint $table) {
            $table->foreignUuid('export_job_id')->constrained();
            $table->foreignUuid('feedback_response_id')->constrained();
            $table->primary(['export_job_id', 'feedback_response_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_job_responses');
    }
};
