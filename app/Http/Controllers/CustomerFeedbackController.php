<?php

namespace App\Http\Controllers;

use App\Application\ChannelPolicy;
use App\Application\Invitations;
use App\Application\PopupPolicy;
use App\Application\SubmitFeedback;
use App\Http\Controllers\Internal\FeedbackController;
use App\Models\Survey;
use App\Models\SurveyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CustomerFeedbackController
{
    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key', '');
        abort_unless((bool) preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422, 'A valid Idempotency-Key is required.');

        return hash('sha256', $request->attributes->get('customer_actor').':'.$key);
    }

    public function config(Request $request, FeedbackController $internal): JsonResponse
    {
        $response = $internal->config($request, true);
        $config = $response->getData(true)['app_config'];
        if ($config['source'] === 'always_on' && ! $request->filled('survey_code')) {
            $versions = SurveyVersion::with('survey')->where('status', 'published')
                ->orderByDesc(Survey::select('created_at')->whereColumn('surveys.id', 'survey_versions.survey_id'))
                ->orderByDesc('survey_id')->orderByDesc('version')->get()->unique('survey_id');
            $config['surveys'] = $versions->filter(fn ($version) => ChannelPolicy::surveySupports($version, 'always_on'))
                ->map(function ($version) use ($request, $internal) {
                    $specific = $request->duplicate(['source' => 'always_on', 'survey_code' => $version->survey->code]);

                    $survey = $internal->config($specific, true)->getData(true)['app_config'];
                    unset($survey['rating_levels']);

                    return $survey;
                })->values()->all();
        }

        return response()->json($config)->header('Cache-Control', 'private, no-store');
    }

    public function eligibility(Request $request, Invitations $invitations): JsonResponse
    {
        $data = $request->validate([
            'survey_version_id' => 'required|uuid',
            'transaction_id' => 'required|string|max:100',
            'transaction_type' => ['required', Rule::in(PopupPolicy::CUSTOMER_TRANSACTION_TYPES)],
            'transaction_occurred_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now',
        ]);
        $payload = [
            'actor_user_id' => $request->attributes->get('customer_actor'),
            'survey_version_id' => $data['survey_version_id'],
            'transaction_context' => [
                'transaction_id' => $data['transaction_id'],
                'transaction_type' => $data['transaction_type'],
                'transaction_occurred_at' => $data['transaction_occurred_at'],
            ],
        ];
        [$result, $status] = $invitations->evaluate('customer-app', $this->key($request), $payload, true);

        return response()->json($result, $status);
    }

    public function event(Request $request, Invitations $invitations): JsonResponse
    {
        $data = $request->only(['invitation_id', 'event_id', 'event_type', 'occurred_at']);
        $data['actor_user_id'] = $request->attributes->get('customer_actor');
        [$result, $status] = $invitations->event('customer-app', $this->key($request), $data);

        return response()->json($result, $status);
    }

    public function submit(Request $request, SubmitFeedback $feedback): JsonResponse
    {
        $request->validate(['name' => 'prohibited', 'phone' => 'prohibited']);
        $data = $request->only(['client_submission_id', 'survey_version_id', 'source', 'invitation_id', 'submitted_at', 'gender', 'answers', 'metadata']);
        $data['actor_user_id'] = $request->attributes->get('customer_actor');
        [$result, $status] = $feedback->execute('customer-app', $this->key($request), $data, $request->attributes->get('customer_profile', []));

        return response()->json($result, $status);
    }
}
