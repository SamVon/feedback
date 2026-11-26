<?php

namespace App\Application;

use App\Models\FeedbackResponse;
use App\Models\Question;
use App\Models\SurveyVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

final class ReportFilters
{
    public static function searchPhone(Builder $query, string $search, string $phoneColumn, string $keyColumn): Builder
    {
        $digits = preg_replace('/\D/', '', $search);
        if (! auth()->user()?->can('customer_identity.read') || $digits === '' || preg_match('/[^0-9+\s().-]/', $search)) {
            return $query->whereRaw('1=0');
        }

        $keys = [];
        foreach ((clone $query)->withoutEagerLoads()->cursor() as $record) {
            $phone = preg_replace('/\D/', '', $record->{$phoneColumn} ?? '');
            if ($phone !== '' && str_contains($phone, $digits)) {
                $keys[] = $record->getKey();
            }
        }

        return $query->whereIn($keyColumn, $keys);
    }

    public static function dateInput(string|DateTimeInterface $value, bool $until = false): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('Asia/Vientiane')->format('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return $value.($until ? ' 23:59:00' : ' 00:00:00');
        }
        $value = str_replace('T', ' ', $value);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value) ? $value.':00' : $value;
    }

    public static function dateRange(array $data): array
    {
        $dates = [];
        foreach (['from', 'until'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $dates[$field] = is_string($data[$field]) ? self::dateInput($data[$field], $field === 'until') : $data[$field];
            }
        }

        return Validator::make($dates, [
            'from' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'until' => ['sometimes', 'date_format:Y-m-d H:i:s', ...(isset($dates['from']) ? ['after_or_equal:from'] : [])],
        ])->validate();
    }

    public static function applyDateRange(Builder $query, array $filters, string $column): Builder
    {
        $dates = self::dateRange($filters);

        return $query
            ->when($dates['from'] ?? null, fn (Builder $q, string $from): Builder => $q->where($column, '>=', CarbonImmutable::parse($from, 'Asia/Vientiane')->utc()))
            ->when($dates['until'] ?? null, fn (Builder $q, string $until): Builder => $q->where($column, '<', CarbonImmutable::parse($until, 'Asia/Vientiane')->startOfMinute()->addMinute()->utc()));
    }

    public static function validate(array $data): array
    {
        $data = array_filter($data, fn ($value) => $value !== null && $value !== '');
        $data = array_replace($data, self::dateRange($data));
        $data = Validator::make($data, [
            'from' => 'sometimes|date_format:Y-m-d H:i:s', 'until' => 'sometimes|date_format:Y-m-d H:i:s',
            'survey_id' => 'sometimes|uuid|exists:surveys,id', 'survey_version_id' => 'sometimes|uuid|exists:survey_versions,id', 'question_id' => 'sometimes|uuid|exists:questions,id',
            'rating' => 'sometimes|integer|between:1,5', 'source' => 'sometimes|in:auto_prompt,always_on', 'transaction_type' => 'sometimes|string|max:50', 'app_version' => 'sometimes|string|max:40', 'os' => 'sometimes|in:android,ios,other', 'actor_user_id' => 'sometimes|string|max:100',
            'exclude_demo' => 'sometimes|boolean', 'default_rating_question' => 'sometimes|boolean',
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
        if ($f['exclude_demo'] ?? false) {
            $q->where('caller', '!=', 'demo');
        }
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

        return self::applyDateRange($q, $f, 'received_at');
    }

    public static function defaults(): array
    {
        $version = SurveyVersion::where('status', 'published')->latest('published_at')->first();

        return ['from' => now('Asia/Vientiane')->subDays(29)->startOfDay()->format('Y-m-d H:i:s'), 'until' => now('Asia/Vientiane')->endOfDay()->startOfMinute()->format('Y-m-d H:i:s'), 'survey_version_id' => $version?->id, 'question_id' => $version?->questions()->where('type', 'rating')->first()?->id];
    }
}
