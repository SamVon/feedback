<?php

namespace App\Application;

use App\Models\FeedbackAnswer;
use App\Models\Question;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class Analytics
{
    public function summary(User $user, array $filters, ?Builder $members = null): array
    {
        Access::check($user, 'analytics.read');
        $f = ReportFilters::validate($filters);
        $responses = ReportFilters::query($f);
        if ($members) {
            $responses->whereIn('id', $members);
        }
        $result = ['response_count' => (clone $responses)->count(), 'unique_customers' => (clone $responses)->distinct()->count('actor_user_id'), 'rating_count' => 0, 'average' => null, 'csat' => null, 'distribution' => array_fill(1, 5, 0), 'trend' => [], 'choices' => [], 'filters' => $f, 'timezone' => 'Asia/Vientiane', 'time_basis' => 'received_at'];
        // Never combine unlike rating questions. Explicit question + version identifies the denominator.
        $question = isset($f['question_id']) ? Question::findOrFail($f['question_id']) : null;
        if (! $question && ($f['default_rating_question'] ?? false)) {
            $question = $this->latestAnsweredQuestion($responses, 'rating');
        }
        if (! $question) {
            return $result;
        }
        $answers = FeedbackAnswer::whereIn('feedback_response_id', (clone $responses)->select('id'))->where('question_id', $question->id);
        if ($question->type === 'rating') {
            $result['rating_question_label'] = $question->label[app()->getLocale()] ?? $question->label['en'];
            $result['rating_question_survey'] = $question->version->survey->name.' · v'.$question->version->version;
            $answers->whereBetween('rating_value', [1, 5]);
            $stats = (clone $answers)->selectRaw('COUNT(*) AS n, SUM(rating_value) AS total, SUM(CASE WHEN rating_value >= 4 THEN 1 ELSE 0 END) AS satisfied')->first();
            $result['rating_count'] = (int) $stats->n;
            if ($stats->n > 0) {
                $result['average'] = round($stats->total / $stats->n, 2);
                $result['csat'] = round(100 * $stats->satisfied / $stats->n, 2);
            }
            foreach ((clone $answers)->selectRaw('rating_value, COUNT(*) AS n')->groupBy('rating_value')->get() as $row) {
                $result['distribution'][$row->rating_value] = (int) $row->n;
            }
            $daily = [];
            foreach ((clone $answers)->join('feedback_responses', 'feedback_responses.id', '=', 'feedback_answers.feedback_response_id')->select('rating_value', 'received_at')->cursor() as $row) {
                $day = CarbonImmutable::parse($row->received_at, 'UTC')->setTimezone('Asia/Vientiane')->toDateString();
                $daily[$day] ??= ['count' => 0, 'sum' => 0];
                $daily[$day]['count']++;
                $daily[$day]['sum'] += $row->rating_value;
            }
            ksort($daily);
            foreach ($daily as $day => $values) {
                $result['trend'][] = ['date' => $day, 'count' => $values['count'], 'average' => round($values['sum'] / $values['count'], 2)];
            }
        }
        if ($question->type === 'choice') {
            $denominator = (clone $answers)->count();
            foreach ($question->options as $option) {
                $n = DB::table('answer_options')->whereIn('feedback_answer_id', (clone $answers)->select('id'))->where('question_option_id', $option->id)->count();
                $result['choices'][] = ['label' => $option->label[app()->getLocale()] ?? $option->label['en'], 'count' => $n, 'denominator' => $denominator, 'percent' => $denominator ? round(100 * $n / $denominator, 2) : null];
            }
        }

        return $result;
    }

    public function latestAnsweredQuestion(Builder $responses, string $type): ?Question
    {
        $latest = FeedbackAnswer::query()
            ->whereIn('feedback_response_id', (clone $responses)->select('id'))
            ->whereHas('question', fn (Builder $question) => $question->where('type', $type))
            ->join('feedback_responses', 'feedback_responses.id', '=', 'feedback_answers.feedback_response_id')
            ->orderByDesc('feedback_responses.received_at')
            ->orderByDesc('feedback_answers.id')
            ->select('feedback_answers.question_id')
            ->limit(1);

        return Question::where('id', $latest)->first();
    }
}
