<?php

namespace App\Application;

use App\Models\Question;
use App\Models\SurveyVersion;
use Illuminate\Support\Collection;

final class SurveyPresentation
{
    public static function questions(SurveyVersion $version, string $source): Collection
    {
        return $version->questions->filter(fn (Question $question): bool => $question->enabled && ($source !== 'auto_prompt' || $question->type === 'rating'))->values();
    }

    public static function question(Question $question): array
    {
        $data = $question->toArray();
        $data['channels'] = $question->type === 'rating' ? ['auto_prompt', 'always_on'] : ['always_on'];
        if ($question->type === 'rating') {
            $data['comment'] = [
                'channels' => ['auto_prompt'], 'field' => 'text_value', 'required' => false,
                'max_length' => $question->max_length,
                'label' => ['en' => 'Additional comments', 'lo' => 'ຄຳແນະນຳເພີ່ມເຕີມ'],
            ];
        }
        $data['rating_reasons'] = [];
        if ($question->type === 'rating' && $question->rating_reasons_enabled) {
            foreach (range(5, 1) as $rating) {
                $data['rating_reasons'][$rating] = $question->options->where('rating_value', $rating)->values()->toArray();
            }
        }

        return $data;
    }
}
