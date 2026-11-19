<?php

namespace App\Http\Controllers;

use App\Application\Invitations;
use App\Application\SubmitFeedback;
use App\Http\Controllers\Internal\FeedbackController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $response = $internal->config($request);

        return response()->json($response->getData(true)['app_config'])->header('Cache-Control', 'private, max-age=60');
    }

    public function eligibility(Request $request, Invitations $invitations): JsonResponse
    {
        $data = $request->validate([
            'survey_version_id' => 'required|uuid',
            'transaction_id' => 'required|string|max:100',
            'transaction_type' => 'required|in:BUY_GOLD,SALE_GOLD',
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
        $data = $request->only(['client_submission_id', 'survey_version_id', 'source', 'invitation_id', 'submitted_at', 'answers', 'metadata']);
        $data['actor_user_id'] = $request->attributes->get('customer_actor');
        [$result, $status] = $feedback->execute('customer-app', $this->key($request), $data);

        return response()->json($result, $status);
    }
}
