<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Application\Changes;
use App\Application\Idempotency;
use App\Filament\Resources\RatingQuestionResource;
use App\Filament\Support\SurveyFields;
use App\Models\Survey;
use App\Models\SurveyVersion;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EditRatingQuestion extends Page
{
    protected static string $resource = RatingQuestionResource::class;

    protected string $view = 'filament.resources.pages.edit-rating-question';

    #[Locked]
    public ?string $versionId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public ?string $versionStatus = null;

    #[Locked]
    public string $questionCode = 'overall';

    #[Locked]
    public ?string $surveyCode = null;

    #[Locked]
    public array $questionLinks = [];

    #[Locked]
    public ?int $editingScore = null;

    public ?array $data = [];

    public array $reasons = [];

    public ?array $reasonData = [];

    private function authorizeEditor(): void
    {
        Access::check(auth()->user(), 'survey.read');
        Access::check(auth()->user(), 'survey.edit');
    }

    public function mount(?string $record = null): void
    {
        $this->authorizeEditor();
        $this->reasons = array_fill(1, 5, []);
        $initial = ['name' => '', 'label' => ['en' => '', 'lo' => ''], 'enabled' => true, 'required' => true, 'max_length' => 2000, 'max_choices' => 3, 'rating_reasons_required' => false];
        if ($record !== null) {
            $version = RatingQuestionResource::getEloquentQuery()->findOrFail($record);
            $ratingQuestions = $version->questions->where('type', 'rating');
            $question = request()->query('question') ? $ratingQuestions->firstWhere('code', request()->query('question')) : $ratingQuestions->first();
            abort_unless($question, 404);
            $this->versionId = $version->id;
            $this->revision = $version->revision;
            $this->versionStatus = $version->status;
            $this->surveyCode = $version->survey->code;
            $this->questionCode = $question->code;
            $questionData = collect(SurveyFields::data($version)['questions'])->firstWhere('code', $question->code);
            $initial = array_replace($initial, $questionData, ['name' => $version->survey->name]);
            foreach (range(1, 5) as $score) {
                $this->reasons[$score] = $questionData['rating_reasons'][$score] ?? [];
            }
            $this->questionLinks = $ratingQuestions->map(fn ($item) => ['label' => $item->label[app()->getLocale()] ?? $item->label['en'], 'url' => RatingQuestionResource::getUrl('edit', ['record' => $version, 'question' => $item->code])])->values()->all();
        } else {
            $this->surveyCode = 'rating_'.Str::lower((string) Str::ulid());
        }
        $this->questionForm->fill($initial);
    }

    public function getTitle(): string
    {
        return $this->editingScore === null ? __('feedback.rating_setup') : __('feedback.reasons_for_rating', ['count' => $this->editingScore]);
    }

    public function questionForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('feedback.name'))->required()->maxLength(200)
                ->helperText(__('feedback.rating_survey_code_auto'))->visible(fn () => $this->versionId === null)->dehydratedWhenHidden(),
            TextInput::make('label.en')->label(__('feedback.question').' · English')->required()->maxLength(500),
            TextInput::make('label.lo')->label(__('feedback.question').' · ລາວ')->required()->maxLength(500),
            Section::make(__('feedback.rating_options_settings'))->collapsed()->columnSpanFull()->schema([
                Toggle::make('enabled')->label(__('feedback.question_enabled'))->default(true),
                Toggle::make('required')->label(__('feedback.answer_required'))->default(true),
                Toggle::make('rating_reasons_required')->label(__('feedback.reasons_required'))->default(false),
                TextInput::make('max_choices')->label(__('feedback.max_selections'))->numeric()->integer()->minValue(1)->maxValue(50)->required()->helperText(__('feedback.selection_policy_hint')),
                TextInput::make('max_length')->label(__('feedback.rating_comment_limit'))->numeric()->integer()->minValue(1)->maxValue(10000)->required(),
            ])->columns(2),
        ])->statePath('data')->columns(2);
    }

    public function reasonForm(Schema $schema): Schema
    {
        return $schema->components([
            Repeater::make('items')->label(__('feedback.rating_answer_options'))->defaultItems(0)->maxItems(50)->reorderableWithButtons()->addActionLabel(__('feedback.add_reason'))->schema([
                Hidden::make('code')->default(fn (): string => Str::random(12)),
                TextInput::make('label.en')->label('English')->required()->maxLength(100),
                TextInput::make('label.lo')->label('ລາວ')->required()->maxLength(100),
            ])->columns(2),
        ])->statePath('reasonData');
    }

    public function editReasons(int $score): void
    {
        $this->authorizeEditor();
        abort_unless(in_array($score, range(1, 5), true), 404);
        $this->questionForm->getState();
        $this->editingScore = $score;
        $this->reasonForm->fill(['items' => $this->reasons[$score] ?? []]);
        $this->dispatch('rating-step-changed');
    }

    public function applyReasons(): void
    {
        $this->authorizeEditor();
        abort_unless($this->editingScore !== null, 409);
        $data = $this->reasonForm->getState();
        $this->reasons[$this->editingScore] = array_values($data['items'] ?? []);
        $this->editingScore = null;
        $this->resetValidation();
        $this->dispatch('rating-step-changed');
    }

    public function cancelReasons(): void
    {
        $this->editingScore = null;
        $this->resetValidation();
        $this->dispatch('rating-step-changed');
    }

    public function save(): void
    {
        $this->authorizeEditor();
        abort_unless($this->editingScore === null, 409);
        $data = $this->questionForm->getState();
        $reasonRules = ['reasons' => 'required|array:1,2,3,4,5', 'reasons.*' => 'present|array|max:50', 'reasons.*.*.code' => 'required|alpha_dash|max:50', 'reasons.*.*.label.en' => 'required|string|max:100', 'reasons.*.*.label.lo' => 'required|string|max:100'];
        foreach (range(1, 5) as $score) {
            $reasonRules['reasons.'.$score] = 'present|array|max:50|min:'.($data['rating_reasons_required'] ? 1 : 0);
        }
        Validator::make(['reasons' => $this->reasons], $reasonRules)->validate();
        try {
            $version = DB::transaction(function () use ($data): SurveyVersion {
                Idempotency::lockActor('__configuration__');
                $base = $this->versionId ? SurveyVersion::with('questions.options', 'survey')->lockForUpdate()->findOrFail($this->versionId) : null;
                if ($base) {
                    abort_unless($base->revision === $this->revision && $base->status === $this->versionStatus, 409, __('feedback.rating_stale'));
                    $draft = SurveyFields::data($base);
                    $index = collect($draft['questions'])->search(fn ($item) => $item['code'] === $this->questionCode && $item['type'] === 'rating');
                    abort_if($index === false, 409, __('feedback.rating_stale'));
                } else {
                    abort_unless(filled($this->surveyCode), 409, __('feedback.rating_stale'));
                    abort_if(Survey::where('code', $this->surveyCode)->exists(), 409, __('feedback.rating_code_exists'));
                    $draft = ['code' => $this->surveyCode, 'name' => $data['name'], 'channels' => ['auto_prompt', 'always_on'], 'questions' => []];
                    $index = 0;
                }
                $draft['questions'][$index] = collect($data)->only(['label', 'enabled', 'required', 'max_choices', 'max_length', 'rating_reasons_required'])->all() + [
                    'code' => $this->questionCode, 'type' => 'rating', 'options' => [], 'rating_reasons_enabled' => true, 'rating_reasons' => $this->reasons,
                ];

                return app(Changes::class)->surveyDraft(auth()->user(), $draft, $base?->status === 'draft' ? $base->id : null, $this->revision);
            }, 5);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->addError('data', $exception->getMessage());

            return;
        }
        $this->versionId = $version->id;
        $this->revision = $version->revision;
        $this->versionStatus = $version->status;
        Notification::make()->success()->title(__('feedback.question_draft_saved', ['version' => $version->version]))->body(__('feedback.question_publish_hint'))->send();
        $this->redirect(RatingQuestionResource::getUrl('edit', ['record' => $version, 'question' => $this->questionCode]));
    }
}
