<?php

namespace Tests\Feature;

class CustomerFeedbackApiTest extends FeedbackTest
{
    private function token(array $overrides = [], string $secret = 'test-customer-secret'): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode(array_replace(['_id' => 'customer-1', 'role' => 'CUSTOMER', 'exp' => time() + 3600], $overrides));

        return $header.'.'.$payload.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), '+/', '-_'), '=');
    }

    public function test_customer_token_authentication(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $url = '/api/v1/feedback/config?survey_code=experience&source=always_on';
        $this->getJson($url)->assertUnauthorized();
        $this->withToken($this->token([], 'wrong-secret'))->getJson($url)->assertUnauthorized();
        $this->withToken($this->token(['exp' => time() - 1]))->getJson($url)->assertUnauthorized();
        $this->withToken($this->token(['role' => 'ADMIN']))->getJson($url)->assertForbidden();
        $this->withToken($this->token())->getJson($url)->assertOk()->assertJsonPath('survey_version_id', $this->survey->id);
    }

    public function test_customer_direct_popup_flow_records_client_report_and_jwt_actor(): void
    {
        config(['feedback.customer_jwt_secret' => 'test-customer-secret']);
        $headers = ['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'eligibility-1'];
        $transaction = ['survey_version_id' => $this->survey->id, 'transaction_id' => 'txn-1', 'transaction_type' => 'BUY_GOLD', 'transaction_occurred_at' => now()->subMinutes(1)->utc()->format('Y-m-d\TH:i:s\Z'), 'actor_user_id' => 'forged'];
        $invitation = $this->withHeaders($headers)->postJson('/api/v1/feedback/eligibility', $transaction)->assertCreated()->assertJsonPath('eligible', true)->json('invitation_id');
        $this->assertDatabaseHas('transaction_contexts', ['transaction_id' => 'txn-1', 'actor_user_id' => 'customer-1', 'verification_source' => 'client_reported', 'verified_at' => null]);
        $answer = $this->payload('submission-1', 'forged');
        $answer['source'] = 'auto_prompt';
        $answer['invitation_id'] = $invitation;
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(), 'Idempotency-Key' => 'response-1'])->postJson('/api/v1/feedback/responses', $answer)->assertCreated();
        $this->assertDatabaseHas('feedback_responses', ['actor_user_id' => 'customer-1', 'invitation_id' => $invitation]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token(['_id' => 'customer-2']), 'Idempotency-Key' => 'response-2'])->postJson('/api/v1/feedback/responses', $answer)->assertUnprocessable();
    }
}
