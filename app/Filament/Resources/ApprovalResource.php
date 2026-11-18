<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Application\Changes;
use App\Application\PopupPolicy;
use App\Filament\Support\ReadOnlyResource;
use App\Models\ChangeRequest;
use App\Models\RuleVersion;
use App\Models\SurveyVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalResource extends ReadOnlyResource
{
    protected static ?string $model = ChangeRequest::class;

    protected static string $permission = 'changes.read';

    protected static ?string $navigationLabel = 'approvals';

    protected static ?int $navigationSort = 6;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    public static function table(Table $table): Table
    {
        $actions = [Action::make('review')->label(__('feedback.preview'))->modalContent(function ($record) {
            Access::check(auth()->user(), 'changes.read');
            if ($record->entity_type === 'survey') {
                return view('filament.partials.survey-preview', ['version' => SurveyVersion::with('survey', 'questions.options')->findOrFail($record->entity_id)]);
            }

            $rules = RuleVersion::findOrFail($record->entity_id)->rules;

            return view('filament.partials.popup-rules', ['rules' => PopupPolicy::normalizeRules($rules), 'legacy' => ! isset($rules['transaction_rules'])]);
        })->modalSubmitAction(false)];
        foreach (['approve', 'reject', 'publish'] as $action) {
            $actions[] = Action::make($action)->label(__('feedback.'.$action))->visible(fn ($record) => $record->status === ($action === 'publish' ? 'approved' : 'submitted') && auth()->user()->can($action === 'publish' ? 'changes.publish' : 'changes.approve'))->requiresConfirmation()->schema($action === 'reject' ? [Textarea::make('reason')->required()->maxLength(2000)] : [])->action(fn ($record, array $data) => app(Changes::class)->transition(auth()->user(), $record->entity_type, $record->entity_id, $record->revision, $action, $data['reason'] ?? null));
        }

        return $table->columns([TextColumn::make('entity_type'), TextColumn::make('entity_id')->searchable(), TextColumn::make('revision'), TextColumn::make('status')->badge(), TextColumn::make('requested_by'), TextColumn::make('reason')->wrap(), TextColumn::make('created_at')->dateTime()])->defaultSort('created_at', 'desc')->recordActions($actions);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListApprovals::route('/')];
    }
}
