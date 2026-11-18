<?php

namespace App\Application;

use App\Filament\Support\SurveyFields;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OtherQuestions
{
    public function save(User $user, string $versionId, int $revision, ?string $questionId, array $question = [], bool $remove = false): SurveyVersion
    {
        Access::check($user, 'survey.edit');

        return DB::transaction(function () use ($user, $versionId, $revision, $questionId, $question, $remove): SurveyVersion {
            Idempotency::lockActor('__configuration__');
            $version = SurveyVersion::with('survey', 'questions.options')->lockForUpdate()->findOrFail($versionId);
            abort_unless($version->revision === $revision, 409, 'Revision changed; reload.');
            $data = SurveyFields::data($version);
            if ($questionId !== null) {
                $existing = $version->questions->firstWhere('id', $questionId);
                abort_unless($existing && in_array($existing->type, ['choice', 'text'], true), 422, 'Select an other question in this version.');
                $position = $version->questions->search(fn ($item) => $item->id === $questionId);
                if ($remove) {
                    unset($data['questions'][$position]);
                } else {
                    abort_unless(in_array($question['type'] ?? null, ['choice', 'text'], true), 422, 'Other questions must be choice or text.');
                    $question['code'] = $existing->code;
                    $data['questions'][$position] = $question;
                }
            } else {
                abort_if($remove, 422, 'Select a question to remove.');
                abort_unless(in_array($question['type'] ?? null, ['choice', 'text'], true), 422, 'Other questions must be choice or text.');
                $data['questions'][] = $question;
            }
            $data['questions'] = array_values($data['questions']);

            return app(Changes::class)->surveyDraft($user, $data, $version->status === 'draft' ? $version->id : null, $revision);
        }, 5);
    }
}
