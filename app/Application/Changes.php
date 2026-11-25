<?php

namespace App\Application;

use App\Models\ChangeRequest;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\RuleVersion;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Changes
{
    public function surveyDraft(User $user, array $data, ?string $id = null, ?int $revision = null): SurveyVersion
    {
        Access::check($user, 'survey.edit');
        $data = Validator::make($data, [
            'code' => 'required|alpha_dash|max:60', 'name' => 'required|string|max:200', 'questions' => 'required|array|min:1|max:50',
            'channels' => 'sometimes|array|min:1|max:2', 'channels.*' => 'in:auto_prompt,always_on|distinct',
            'content' => 'sometimes|array:title,intro,submit,cancel,thank_you',
            'content.*' => 'array:en,lo', 'content.*.en' => 'required|string|max:500', 'content.*.lo' => 'required|string|max:500',
            'questions.*' => 'array:code,type,label,enabled,required,max_length,max_choices,options,rating_reasons_enabled,rating_reasons_required,rating_reasons',
            'questions.*.enabled' => 'sometimes|boolean',
            'questions.*.code' => 'required|alpha_dash|max:60|distinct', 'questions.*.type' => 'required|in:rating,choice,text',
            'questions.*.label' => 'required|array:en,lo', 'questions.*.label.en' => 'required|string|max:500', 'questions.*.label.lo' => 'required|string|max:500',
            'questions.*.required' => 'required|boolean', 'questions.*.max_length' => 'required|integer|between:1,10000', 'questions.*.max_choices' => 'required|integer|between:1,50',
            'questions.*.rating_reasons_enabled' => 'sometimes|boolean',
            'questions.*.rating_reasons_required' => 'sometimes|boolean',
            'questions.*.rating_reasons' => 'sometimes|array:1,2,3,4,5',
            'questions.*.rating_reasons.*' => 'array|max:50',
            'questions.*.rating_reasons.*.*' => 'array:code,label',
            'questions.*.rating_reasons.*.*.code' => 'required|alpha_dash|max:50',
            'questions.*.rating_reasons.*.*.label' => 'required|array:en,lo',
            'questions.*.rating_reasons.*.*.label.en' => 'required|string|max:100',
            'questions.*.rating_reasons.*.*.label.lo' => 'required|string|max:100',
            'questions.*.options' => 'present|array|max:50', 'questions.*.options.*.code' => 'required|alpha_dash|max:60',
            'questions.*.options.*' => 'array:code,label',
            'questions.*.options.*.label' => 'required|array:en,lo', 'questions.*.options.*.label.en' => 'required|string|max:200', 'questions.*.options.*.label.lo' => 'required|string|max:200',
        ])->validate();
        foreach ($data['questions'] as $q) {
            $withReasons = $q['rating_reasons_enabled'] ?? false;
            abort_if($withReasons && $q['type'] !== 'rating', 422, 'Only rating questions support rating reasons.');
            abort_if(($q['rating_reasons_required'] ?? false) && ! $withReasons, 422, 'Enable rating reasons before requiring them.');
            if ($withReasons) {
                foreach (range(1, 5) as $rating) {
                    $reasons = $q['rating_reasons'][$rating] ?? [];
                    abort_unless(array_key_exists($rating, $q['rating_reasons'] ?? []), 422, 'Configure a reason set for every rating from 1 to 5.');
                    abort_if(($q['rating_reasons_required'] ?? false) && count($reasons) === 0, 422, 'Required reasons need at least one option for every rating.');
                    abort_unless(count(array_unique(array_column($reasons, 'code'))) === count($reasons), 422, 'Duplicate reason code within a rating.');
                }
            } else {
                abort_if(collect($q['rating_reasons'] ?? [])->flatten(1)->isNotEmpty(), 422, 'Rating reasons are disabled.');
            }
            abort_unless(count(array_unique(array_column($q['options'], 'code'))) === count($q['options']), 422, 'Duplicate option code.');
            abort_unless($q['type'] === 'choice' ? count($q['options']) >= $q['max_choices'] : count($q['options']) === 0, 422, 'Choice options are invalid.');
        }

        return DB::transaction(function () use ($user, $data, $id, $revision) {
            Idempotency::lockActor('__configuration__');
            $survey = Survey::firstOrCreate(['code' => $data['code']], ['name' => $data['name']]);
            abort_unless($survey->name === $data['name'], 422, 'Survey name is fixed for this code; use a new code to rename.');
            $before = null;
            if ($id) {
                $version = SurveyVersion::lockForUpdate()->findOrFail($id);
                abort_unless($version->survey_id === $survey->id && $version->status === 'draft' && $version->revision === $revision, 409, 'Stale revision or immutable version.');
                $before = $version->load('questions.options')->toArray();
                foreach ($version->questions as $q) {
                    $q->options()->delete();
                    $q->delete();
                }
                $version->update(['revision' => $version->revision + 1]);
            } else {
                $version = SurveyVersion::create(['survey_id' => $survey->id, 'version' => ($survey->versions()->max('version') ?? 0) + 1, 'status' => 'draft', 'revision' => 1]);
            }
            $version->update(['channels' => $data['channels'] ?? ['auto_prompt', 'always_on'], 'content' => $data['content'] ?? null]);
            foreach (array_values($data['questions']) as $position => $q) {
                $question = Question::create(collect($q)->except(['options', 'rating_reasons'])->all() + ['survey_version_id' => $version->id, 'position' => $position]);
                foreach (array_values($q['options']) as $optionPosition => $option) {
                    QuestionOption::create($option + ['question_id' => $question->id, 'position' => $optionPosition]);
                }
                foreach ($q['rating_reasons'] ?? [] as $rating => $reasons) {
                    foreach (array_values($reasons) as $optionPosition => $option) {
                        QuestionOption::create([
                            'question_id' => $question->id, 'code' => 'r'.$rating.'_'.$option['code'],
                            'label' => $option['label'], 'rating_value' => (int) $rating, 'position' => $optionPosition,
                        ]);
                    }
                }
            }
            Audit::record((string) $user->id, 'survey.draft', 'survey', $version->id, ['revision' => $version->revision, 'before' => $before, 'after' => $data]);

            return $version;
        }, 5);
    }

    public function ruleDraft(User $user, array $rules, ?string $id = null, ?int $revision = null): RuleVersion
    {
        Access::check($user, 'rules.edit');
        $rules = Validator::make($rules, [
            'channels' => 'required|array:auto_prompt,always_on', 'channels.auto_prompt' => 'required|boolean', 'channels.always_on' => 'required|boolean',
            'transaction_rules' => 'present|array|max:50',
            'transaction_rules.*' => 'array:transaction_type,enabled,max_responses,cooldown_days,reset_policy',
            'transaction_rules.*.transaction_type' => 'required|string|max:50|regex:/^[A-Z][A-Z0-9_]*$/|distinct',
            'transaction_rules.*.enabled' => 'required|boolean',
            'transaction_rules.*.max_responses' => 'required|integer|in:3',
            'transaction_rules.*.cooldown_days' => 'required|integer|in:30',
            'transaction_rules.*.reset_policy' => 'required|in:pending_confirmation',
            'popup_schedule' => 'sometimes|array:starts_at,ends_at',
            'popup_schedule.starts_at' => 'nullable|date_format:Y-m-d\\TH:i:s\\Z',
            'popup_schedule.ends_at' => 'nullable|date_format:Y-m-d\\TH:i:s\\Z',
            'always_on_frequency' => 'sometimes|array:limit,unit,status,enforced',
            'always_on_frequency.limit' => 'required_with:always_on_frequency|integer|in:3',
            'always_on_frequency.unit' => 'required_with:always_on_frequency|in:day',
            'always_on_frequency.status' => 'required_with:always_on_frequency|in:pending_confirmation',
            'always_on_frequency.enforced' => 'sometimes|required_with:always_on_frequency|boolean|declined',
            'customer_service' => 'sometimes|array:enabled,icon,icon_path,name,description',
            'customer_service.enabled' => 'required_with:customer_service|boolean',
            'customer_service.icon' => 'required_with:customer_service|in:star,chat,headset',
            'customer_service.icon_path' => ['sometimes', 'nullable', 'string', 'max:240', 'regex:/\Acustomer-service-icons\/[A-Za-z0-9_-]+\.(?:png|jpe?g|webp)\z/i'],
            'customer_service.name' => 'required_with:customer_service|array:en,lo',
            'customer_service.name.*' => 'required|string|max:100',
            'customer_service.name.en' => 'required_with:customer_service', 'customer_service.name.lo' => 'required_with:customer_service',
            'customer_service.description' => 'required_with:customer_service|array:en,lo',
            'customer_service.description.*' => 'nullable|string|max:500',
            'transaction_types' => 'prohibited', 'cooldown_seconds' => 'prohibited', 'rate_limit' => 'required|integer|between:1,1000',
            'rate_window_seconds' => 'required|integer|between:60,86400', 'invitation_ttl_seconds' => 'required|integer|between:60,604800',
        ])->validate();

        if (filled($rules['customer_service']['icon_path'] ?? null)) {
            $path = $rules['customer_service']['icon_path'];
            $disk = Storage::disk('public');
            if (! $disk->exists($path) || $disk->size($path) > 1048576 || ! in_array($disk->mimeType($path), ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw ValidationException::withMessages(['customer_service.icon_path' => __('feedback.customer_service_icon_invalid')]);
            }
        }

        $schedule = ChannelPolicy::schedule($rules);
        abort_if($schedule['starts_at'] && $schedule['ends_at'] && $schedule['ends_at'] <= $schedule['starts_at'], 422, 'Schedule end must be after start.');
        $rules['always_on_frequency'] = ChannelPolicy::frequencyProposal();
        $rules['customer_service'] = ChannelPolicy::customerService($rules);
        $rules['popup_schedule'] = $schedule;

        return DB::transaction(function () use ($user, $rules, $id, $revision) {
            Idempotency::lockActor('__configuration__');
            $before = null;
            if ($id) {
                $version = RuleVersion::lockForUpdate()->findOrFail($id);
                abort_unless($version->status === 'draft' && $version->revision === $revision, 409, 'Stale revision or immutable version.');
                $before = $version->rules;
                $version->update(['rules' => $rules, 'revision' => $version->revision + 1]);
            } else {
                $version = RuleVersion::create(['version' => (RuleVersion::max('version') ?? 0) + 1, 'rules' => $rules, 'status' => 'draft', 'revision' => 1]);
            }
            Audit::record((string) $user->id, 'rules.draft', 'rules', $version->id, ['revision' => $version->revision, 'before' => $before, 'after' => $rules]);

            return $version;
        }, 5);
    }

    public function transition(User $user, string $type, string $id, int $revision, string $action, ?string $reason = null): void
    {
        abort_unless(in_array($type, ['survey', 'rules'], true), 422);
        abort_unless(in_array($action, ['submit', 'approve', 'reject', 'publish'], true), 422);
        Access::check($user, match ($action) {
            'submit' => $type === 'survey' ? 'survey.edit' : 'rules.edit', 'approve','reject' => 'changes.approve', 'publish' => 'changes.publish'
        });
        abort_if($action === 'reject' && (! is_string($reason) || trim($reason) === ''), 422, 'Rejection reason required.');
        abort_if(mb_strlen($reason ?? '') > 2000, 422, 'Reason too long.');
        DB::transaction(function () use ($user, $type, $id, $revision, $action, $reason) {
            Idempotency::lockActor('__configuration__');
            $model = $type === 'survey' ? SurveyVersion::class : RuleVersion::class;
            $version = $model::lockForUpdate()->findOrFail($id);
            abort_unless($version->revision === $revision, 409, 'Revision changed; reload.');
            $expected = $version->status;
            $allowed = match ($action) {
                'submit' => ['draft'], 'approve', 'reject' => ['submitted'], 'publish' => ['draft', 'submitted', 'approved'],
            };
            abort_unless(in_array($expected, $allowed, true), 409, 'Invalid transition.');
            if ($action === 'submit') {
                ChangeRequest::create(['entity_type' => $type, 'entity_id' => $id, 'revision' => $revision, 'status' => 'submitted', 'requested_by' => $user->id]);
            } elseif ($action === 'publish' && $expected === 'draft') {
                ChangeRequest::create(['entity_type' => $type, 'entity_id' => $id, 'revision' => $revision, 'status' => 'published', 'requested_by' => $user->id]);
            } else {
                $request = ChangeRequest::where('entity_type', $type)->where('entity_id', $id)->where('revision', $revision)->latest()->lockForUpdate()->firstOrFail();
                abort_unless($request->status === $expected, 409, 'Change request no longer valid.');
                $request->update(['status' => $action === 'publish' ? 'published' : ($action === 'approve' ? 'approved' : 'rejected'), 'reviewed_by' => $action === 'publish' ? $request->reviewed_by : $user->id, 'reason' => $reason ?? $request->reason]);
            }
            $status = match ($action) {
                'submit' => 'submitted','approve' => 'approved','reject' => 'draft','publish' => 'published'
            };
            $updates = ['status' => $status];
            if ($action === 'reject') {
                $updates['revision'] = $revision + 1;
            }
            if ($action === 'publish') {
                $updates['published_at'] = now();
                $newer = $model::where('status', 'published')->where('version', '>', $version->version);
                if ($type === 'survey') {
                    $newer->where('survey_id', $version->survey_id);
                } abort_if($newer->exists(), 409, 'A newer version is already published.');
            }
            $version->update($updates);
            Audit::record((string) $user->id, $type.'.'.$action, $type, $id, ['revision' => $revision, 'before' => $expected, 'after' => $status, 'reason' => $reason]);
        }, 5);
    }
}
