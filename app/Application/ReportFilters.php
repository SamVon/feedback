<?php

namespace App\Application;

use App\Models\FeedbackResponse;
use App\Models\Question;
use App\Models\SurveyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class ReportFilters
{
    public static function validate(array $data): array
    {
        $data = array_filter($data, fn ($value) => $value !== null && $value !== '');
        $data = Validator::make($data, [
            'from' => 'sometimes|date_format:Y-m-d', 'until' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'survey_id' => 'sometimes|uuid|exists:surveys,id', 'survey_version_id' => 'sometimes|uuid|exists:survey_versions,id', 'question_id' => 'sometimes|uuid|exists:questions,id',
            'rating' => 'sometimes|integer|between:1,5', 'source' => 'sometimes|in:auto_prompt,always_on', 'transaction_type' => 'sometimes|string|max:50', 'app_version' => 'sometimes|string|max:40', 'os' => 'sometimes|in:android,ios,other', 'actor_user_id' => 'sometimes|string|max:100',
        ])->validate();
        if (isset($data['question_id'])) {
            $question = Question::findOrFail($data['question_id']);
            abort_if(isset($data['survey_version_id']) && $data['survey_version_id'] !== $question->survey_version_id, 422, 'Question/version mismatch.');
            $data['survey_version_id'] = $question->survey_version_id;
        }
        if (isset($data['survey_version_id'])) {
            $version = SurveyVersion::findOrFail($data['survey_version_id']);
            abort_if(isset($data['survey_id']) && $data['survey_id'] !== $version->survey_id, 422, 'Survey/version mismatch.');
            $data['survey_id'] = $version->survey_id;
        }

        return $data;
    }

    public static function query(array $filters)
    {
        $f = self::validate($filters);
        $q = FeedbackResponse::query();
        foreach (['survey_version_id', 'source', 'app_version', 'os', 'actor_user_id'] as $field) {
            if (isset($f[$field])) {
                $q->where($field, $f[$field]);
            }
        }
        if (isset($f['survey_id'])) {
            $q->whereHas('version', fn ($v) => $v->where('survey_id', $f['survey_id']));
        }
        if (isset($f['transaction_type'])) {
            $q->whereHas('context', fn ($v) => $v->where('transaction_type', $f['transaction_type']));
        }
        if (isset($f['rating'])) {
            $q->whereHas('answers', fn ($answer) => $answer->where('rating_value', $f['rating'])->when($f['question_id'] ?? null, fn ($answer, $id) => $answer->where('question_id', $id)));
        }
        if (isset($f['from'])) {
            $q->where('received_at', '>=', CarbonImmutable::parse($f['from'], 'Asia/Vientiane')->startOfDay()->utc());
        }
        if (isset($f['until'])) {
            $q->where('received_at', '<', CarbonImmutable::parse($f['until'], 'Asia/Vientiane')->addDay()->startOfDay()->utc());
        }

        return $q;
    }

    public static function defaults(): array
    {
        $version = SurveyVersion::where('status', 'published')->latest('published_at')->first();

        return ['from' => now('Asia/Vientiane')->subDays(29)->toDateString(), 'until' => now('Asia/Vientiane')->toDateString(), 'survey_version_id' => $version?->id, 'question_id' => $version?->questions()->where('type', 'rating')->first()?->id];
    }
}
