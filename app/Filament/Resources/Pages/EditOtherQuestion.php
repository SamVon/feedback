<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Application\OtherQuestions;
use App\Filament\Resources\OtherQuestionResource;
use App\Filament\Support\OtherQuestionFields;
use App\Models\SurveyVersion;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Unexposed;

class EditOtherQuestion extends Page
{
    protected static string $resource = OtherQuestionResource::class;

    protected string $view = 'filament.resources.pages.edit-other-question';

    #[Locked]
    public ?string $questionId = null;

    public ?array $data = [];

    public function mount(?string $record = null): void
    {
        Access::check(auth()->user(), 'survey.read');
        Access::check(auth()->user(), 'survey.edit');
        if ($record !== null) {
            $question = OtherQuestionResource::getEloquentQuery()->findOrFail($record);
            $this->questionId = $question->id;
            $this->form->fill(OtherQuestionFields::data($question) + ['survey_version_id' => $question->survey_version_id]);
        } else {
            $version = SurveyVersion::orderByDesc('created_at')->orderByDesc('id')->first();
            $this->form->fill();
            $this->data['survey_version_id'] = $version?->id;
            $this->data['revision'] = $version?->revision;
        }
    }

    public function getTitle(): string
    {
        return __($this->questionId ? 'feedback.edit_other_question_title' : 'feedback.create_question');
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public function selectedSurveyLabel(): ?string
    {
        Access::check(auth()->user(), 'survey.read');
        $version = SurveyVersion::with('survey')->find($this->data['survey_version_id'] ?? null);

        return $version ? $version->survey->name.' · v'.$version->version.' · '.OtherQuestionFields::versionStatusLabel($version->status) : null;
    }

    #[Unexposed]
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision')->required(),
            ...OtherQuestionFields::make($this->questionId === null),
            Section::make(__('feedback.other_question_settings'))->description(__('feedback.other_question_settings_hint'))
                ->collapsible()->collapsed(fn (): bool => filled($this->data['survey_version_id'] ?? null))->compact()->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('survey_version_id')->label(__('feedback.base_survey_version'))
                        ->options(fn () => SurveyVersion::with('survey')->orderByDesc('created_at')->get()->mapWithKeys(fn (SurveyVersion $version) => [
                            $version->id => $version->survey->name.' · v'.$version->version.' · '.OtherQuestionFields::versionStatusLabel($version->status),
                        ]))
                        ->required()->disabled(fn () => $this->questionId !== null)->dehydrated()->live()
                        ->afterStateUpdated(fn ($state, Set $set) => $set('revision', SurveyVersion::find($state)?->revision)),
                    ...OtherQuestionFields::settings(),
                ])->extraAttributes(['class' => 'eg-other-question-settings'])->columnSpanFull(),
        ])->statePath('data');
    }

    public function save(): void
    {
        Access::check(auth()->user(), 'survey.read');
        Access::check(auth()->user(), 'survey.edit');
        $data = $this->form->getState();
        $version = app(OtherQuestions::class)->save(auth()->user(), $data['survey_version_id'], (int) $data['revision'], $this->questionId, $data['question']);
        Notification::make()->success()->title(__('feedback.question_draft_saved', ['version' => $version->version]))->body(__('feedback.question_publish_hint'))->send();
        $this->redirect(OtherQuestionResource::getUrl('index'));
    }
}
