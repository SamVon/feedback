<?php

namespace App\Http\Controllers\Internal;

use App\Application\ChannelPolicy;
use App\Application\Cohorts;
use App\Application\Invitations;
use App\Application\PopupPolicy;
use App\Application\SubmitFeedback;
use App\Application\SurveyPresentation;
use App\Models\FeedbackResponse;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use Illuminate\Http\Request;

final class FeedbackController
{
    private function reply(array $result)
    {
        return response()->json($result[0], $result[1]);
    }

    public function submit(Request $r, SubmitFeedback $service)
    {
        return $this->reply($service->execute($r->attributes->get('service_caller'), $r->header('Idempotency-Key', ''), $r->all()));
    }

    public function config(Request $r)
    {
        $r->validate(['survey_code' => 'required|string|max:100', 'source' => 'sometimes|in:auto_prompt,always_on']);
        $version = SurveyVersion::with('questions.options')->whereHas('survey', fn ($q) => $q->where('code', $r->input('survey_code')))->where('status', 'published')->orderByDesc('version')->firstOrFail();

        if ($r->filled('source')) {
            abort_unless(ChannelPolicy::surveySupports($version, $r->input('source')), 404);
        }
        $source = $r->input('source', 'always_on');
        $hasPopupQuestions = SurveyPresentation::questions($version, 'auto_prompt')->isNotEmpty();
        $version->setRelation('questions', SurveyPresentation::questions($version, $source));
        $survey = $version->toArray();
        $survey['channels'] = $version->channels ?? ['auto_prompt', 'always_on'];
        $survey['content'] = ChannelPolicy::content($version);
        $survey['questions'] = $version->questions->map(fn ($question) => SurveyPresentation::question($question))->all();

        $rules = PopupPolicy::normalizeRules(RuleVersion::where('status', 'published')->orderByDesc('version')->first()?->rules ?? []);
        $customerService = ChannelPolicy::customerService($rules);
        $channels = [
            'auto_prompt' => ($rules['channels']['auto_prompt'] ?? false) && $hasPopupQuestions && ChannelPolicy::popupOpen($rules) && ChannelPolicy::surveySupports($version, 'auto_prompt'),
            'always_on' => ($rules['channels']['always_on'] ?? false) && $customerService['enabled'] && ChannelPolicy::surveySupports($version, 'always_on'),
        ];

        return response()->json(['survey' => $survey, 'rules' => $rules, 'app_config' => [
            'source' => $source, 'survey_code' => $version->survey->code, 'survey_version' => $version->version,
            'published_at' => $version->published_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'rating_levels' => [5, 4, 3, 2, 1],
            'survey_version_id' => $version->id, 'channels' => $channels, 'content' => $survey['content'],
            'questions' => $survey['questions'], 'customer_service' => $customerService, 'popup_schedule' => ChannelPolicy::schedule($rules),
        ]])->header('Cache-Control', 'private, max-age=60');
    }

    public function read(Request $r, string $id)
    {
        $r->validate(['actor_user_id' => 'required|string|max:100']);

        return FeedbackResponse::with('answers.options')->where('caller', $r->attributes->get('service_caller'))->where('actor_user_id', $r->input('actor_user_id'))->findOrFail($id);
    }

    public function eligibility(Request $r, Invitations $service)
    {
        return $this->reply($service->evaluate($r->attributes->get('service_caller'), $r->header('Idempotency-Key', ''), $r->all()));
    }

    public function event(Request $r, Invitations $service)
    {
        return $this->reply($service->event($r->attributes->get('service_caller'), $r->header('Idempotency-Key', ''), $r->all()));
    }

    public function start(Request $r, Cohorts $service)
    {
        return $this->reply($service->start($r->attributes->get('service_caller'), $r->header('Idempotency-Key', ''), $r->all()));
    }

    public function members(Request $r, string $id, Cohorts $service)
    {
        return $this->reply($service->members($r->attributes->get('service_caller'), $r->header('Idempotency-Key', ''), $id, $r->all()));
    }

    public function complete(Request $r, string $id, Cohorts $service)
    {
        return $this->reply($service->complete($r->attributes->get('service_caller'), $r->header('Idempotency-Key', ''), $id));
    }
}
