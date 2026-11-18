<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // Service identity middleware is mandatory on this route.

    public function rules(): array
    {
        return [
            'client_submission_id' => 'required|string|max:100', 'actor_user_id' => 'required|string|max:100', 'one_id' => 'nullable|string|max:100',
            'survey_version_id' => 'required|uuid', 'source' => 'required|in:auto_prompt,always_on', 'invitation_id' => 'nullable|uuid',
            'submitted_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now',
            'answers' => 'required|array|min:1|max:50', 'answers.*' => 'required|array:question_id,rating_value,text_value,option_ids',
            'answers.*.question_id' => 'required|uuid|distinct',
            'answers.*.rating_value' => 'sometimes|integer|between:1,5', 'answers.*.text_value' => 'sometimes|nullable|string|max:10000',
            'answers.*.option_ids' => 'sometimes|array|max:50', 'answers.*.option_ids.*' => 'uuid|distinct',
            'metadata' => 'sometimes|array:app_version,os', 'metadata.app_version' => 'nullable|string|max:40', 'metadata.os' => 'nullable|in:android,ios,other',
            'transaction_context' => 'prohibited', // Context is bound to the verified invitation, never redefined on submit.
        ];
    }
}
