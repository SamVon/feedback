<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('permissions')->nullable();
            $t->boolean('active')->default(false);
            $t->string('external_id')->nullable()->unique();
        });
        Schema::create('surveys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('survey_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('survey_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('status')->default('draft')->index();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['survey_id', 'version']);
        });
        Schema::create('questions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('survey_version_id')->constrained();
            $t->string('code');
            $t->string('type');
            $t->json('label');
            $t->boolean('required')->default(true);
            $t->unsignedInteger('position');
            $t->unsignedInteger('max_length')->default(2000);
            $t->unsignedInteger('max_choices')->default(1);
            $t->unique(['survey_version_id', 'code']);
        });
        Schema::create('question_options', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('question_id')->constrained();
            $t->string('code');
            $t->json('label');
            $t->unique(['question_id', 'code']);
        });
        Schema::create('rule_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('version')->unique();
            $t->string('status')->default('draft')->index();
            $t->unsignedInteger('revision')->default(1);
            $t->json('rules');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('actor_locks', function (Blueprint $t) {
            $t->string('actor_user_id')->primary();
        });
        Schema::create('transaction_contexts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('caller');
            $t->string('transaction_id');
            $t->string('transaction_type')->index();
            $t->string('actor_user_id');
            $t->timestamp('transaction_occurred_at');
            $t->timestamp('verified_at');
            $t->unique(['caller', 'transaction_id']);
        });
        Schema::create('feedback_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('caller');
            $t->string('actor_user_id')->index();
            $t->foreignUuid('survey_version_id')->constrained();
            $t->foreignUuid('rule_version_id')->constrained();
            $t->foreignUuid('transaction_context_id')->constrained();
            $t->timestamp('eligible_at');
            $t->timestamp('expires_at');
            $t->unique(['transaction_context_id', 'survey_version_id'], 'invitation_context_version_unique');
        });
        Schema::create('prompt_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('caller');
            $t->string('event_id');
            $t->foreignUuid('invitation_id')->constrained('feedback_invitations');
            $t->string('event_type');
            $t->timestamp('occurred_at');
            $t->timestamp('received_at');
            $t->string('payload_hash', 64);
            $t->unique(['caller', 'event_id']);
        });
        Schema::create('feedback_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('caller');
            $t->string('client_submission_id');
            $t->string('payload_hash', 64);
            $t->string('actor_user_id')->index();
            $t->string('one_id')->nullable();
            $t->foreignUuid('survey_version_id')->constrained();
            $t->string('source')->index();
            $t->foreignUuid('invitation_id')->nullable()->unique()->constrained('feedback_invitations');
            $t->foreignUuid('transaction_context_id')->nullable()->constrained();
            $t->timestamp('submitted_at');
            $t->timestamp('received_at')->index();
            $t->string('app_version')->nullable()->index();
            $t->string('os')->nullable()->index();
            $t->unique(['caller', 'actor_user_id', 'client_submission_id'], 'response_submission_unique');
            $t->index(['survey_version_id', 'received_at']);
            $t->index(['actor_user_id', 'received_at']);
        });
        Schema::create('feedback_answers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('feedback_response_id')->constrained();
            $t->foreignUuid('question_id')->constrained();
            $t->unsignedTinyInteger('rating_value')->nullable();
            $t->text('text_value')->nullable();
            $t->unique(['feedback_response_id', 'question_id']);
        });
        Schema::create('answer_options', function (Blueprint $t) {
            $t->foreignUuid('feedback_answer_id')->constrained();
            $t->foreignUuid('question_option_id')->constrained();
            $t->primary(['feedback_answer_id', 'question_option_id']);
        });
        Schema::create('cohort_snapshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('caller');
            $t->string('external_id');
            $t->string('status')->default('incomplete')->index();
            $t->timestamp('captured_at');
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('expected_count');
            $t->unsignedInteger('expected_batches');
            $t->string('identity_type')->default('account');
            $t->timestamps();
            $t->unique(['caller', 'external_id']);
        });
        Schema::create('cohort_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('cohort_snapshot_id')->constrained();
            $t->string('batch_id');
            $t->string('payload_hash', 64);
            $t->unique(['cohort_snapshot_id', 'batch_id']);
        });
        Schema::create('cohort_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('cohort_snapshot_id')->constrained();
            $t->string('actor_user_id')->index();
            $t->string('one_id')->nullable();
            $t->text('display_name')->nullable();
            $t->text('phone')->nullable();
            $t->boolean('identity_complete')->default(true);
            $t->unique(['cohort_snapshot_id', 'actor_user_id']);
        });
        Schema::create('change_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('entity_type');
            $t->uuid('entity_id');
            $t->unsignedInteger('revision');
            $t->string('status');
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->text('reason')->nullable();
            $t->timestamps();
            $t->index(['entity_type', 'entity_id']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor');
            $t->string('operation')->index();
            $t->string('entity_type');
            $t->string('entity_id');
            $t->json('details');
            $t->timestamp('created_at')->index();
        });
        Schema::create('export_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->json('filters');
            $t->timestamp('snapshot_at');
            $t->string('status')->default('queued');
            $t->string('file_ref')->nullable();
            $t->timestamp('expires_at');
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('idempotency_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('caller');
            $t->string('operation');
            $t->string('key', 128);
            $t->string('payload_hash', 64);
            $t->json('response')->nullable();
            $t->timestamp('created_at');
            $t->unique(['caller', 'operation', 'key'], 'idempotency_scope_unique');
        });
    }

    public function down(): void
    {
        foreach (['idempotency_records', 'export_jobs', 'audit_events', 'change_requests', 'cohort_members', 'cohort_batches', 'cohort_snapshots', 'answer_options', 'feedback_answers', 'feedback_responses', 'prompt_events', 'feedback_invitations', 'transaction_contexts', 'actor_locks', 'rule_versions', 'question_options', 'questions', 'survey_versions', 'surveys'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['permissions', 'active', 'external_id']));
    }
};
