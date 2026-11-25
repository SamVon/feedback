<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Application\Audit;
use App\Application\Idempotency;
use App\Filament\Resources\RuleResource;
use App\Filament\Support\RuleEditorAction;
use App\Models\DailyFeedbackLimit;
use App\Models\RuleVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;

class ListRules extends ListRecords
{
    protected static string $resource = RuleResource::class;

    protected string $view = 'filament.resources.pages.list-rules';

    public function getHeading(): ?string
    {
        return null;
    }

    #[Unexposed]
    public function getBreadcrumbs(): array
    {
        return [];
    }

    #[Unexposed]
    public function publishedRule(): ?RuleVersion
    {
        return RuleResource::getEloquentQuery()->where('status', 'published')->latest('version')->first();
    }

    #[Unexposed]
    public function showsDailyLimit(): bool
    {
        return $this->activeTab === 'daily-limit';
    }

    public function editDailyLimitAction(): Action
    {
        return Action::make('editDailyLimit')
            ->button()->label(__('feedback.edit_question'))->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->showsDailyLimit())
            ->authorize(fn (): bool => auth()->user()?->can(['rules.read', 'rules.edit', 'changes.publish']) ?? false)
            ->modalHeading(__('feedback.edit_daily_limit'))
            ->modalWidth(Width::Large)
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel(__('feedback.daily_limit_save'))
            ->fillForm(fn (): array => ['response_limit' => DailyFeedbackLimit::current()?->response_limit ?? DailyFeedbackLimit::DEFAULT_LIMIT])
            ->schema([
                TextInput::make('response_limit')->label(__('feedback.daily_limit'))->integer()->required()
                    ->minValue(1)->maxValue(1000)->helperText(__('feedback.daily_limit_hint')),
            ])
            ->successNotificationTitle(__('feedback.daily_limit_saved'))
            ->action(function (Action $action, array $data): void {
                $staff = auth()->user();
                foreach (['rules.read', 'rules.edit', 'changes.publish'] as $permission) {
                    Access::check($staff, $permission);
                }

                DB::transaction(function () use ($data, $staff): void {
                    Idempotency::lockActor('__configuration__');
                    $previous = DailyFeedbackLimit::current();
                    $limit = (int) $data['response_limit'];
                    if ($previous?->response_limit === $limit) {
                        return;
                    }

                    $change = DailyFeedbackLimit::create(['response_limit' => $limit, 'editor_id' => $staff->id]);
                    Audit::record((string) $staff->id, 'rules.daily_limit', 'daily_feedback_limit', (string) $change->id, [
                        'before' => $previous?->response_limit,
                        'after' => $limit,
                    ]);
                }, 5);

                $action->success();
            });
    }

    public function editPublishedAction(): Action
    {
        return RuleEditorAction::make('editPublished')
            ->button()->label(__('feedback.edit_question'))->icon('heroicon-o-pencil-square')
            ->record(fn (array $arguments): ?RuleVersion => is_string($arguments['rule'] ?? null)
                ? RuleVersion::where('status', 'published')->find($arguments['rule'])
                : null);
    }
}
