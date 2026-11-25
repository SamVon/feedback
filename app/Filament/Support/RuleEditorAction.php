<?php

namespace App\Filament\Support;

use App\Application\Access;
use App\Application\Changes;
use App\Application\ChannelPolicy;
use App\Application\Idempotency;
use App\Application\PopupPolicy;
use App\Models\RuleVersion;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RuleEditorAction
{
    public static function make(string $name, bool $creating = false): Action
    {
        $action = Action::make($name)
            ->authorize(fn (): bool => auth()->user()?->can(['rules.read', 'rules.edit']) ?? false)
            ->modalHeading(__($creating ? 'feedback.new_rules' : 'feedback.edit_rules'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel(__('feedback.rating_save_draft'))
            ->modalCancelAction(false)
            ->closeModalByClickingAway(false)
            ->schema($creating
                ? RuleFields::make(dateOnly: true)
                : [Hidden::make('revision')->required(), Hidden::make('version_status')->required(), ...RuleFields::make(dateOnly: false)])
            ->successNotificationTitle(__('feedback.rules_draft_saved'))
            ->action(function (Action $action, array $data, ?RuleVersion $record) use ($creating): void {
                $user = auth()->user();
                Access::check($user, 'rules.read');
                Access::check($user, 'rules.edit');
                abort_if(! $creating && ! $record, 404);

                try {
                    DB::transaction(function () use ($creating, $data, $record, $user): void {
                        Idempotency::lockActor('__configuration__');
                        $base = $creating ? null : RuleVersion::lockForUpdate()->findOrFail($record->id);
                        if ($base) {
                            abort_unless($base->revision === (int) $data['revision'] && $base->status === $data['version_status'], 409);
                        }

                        $storedRules = $base?->rules ?? RuleVersion::where('status', 'published')->latest('version')->first()?->rules ?? [];
                        $rules = self::rulePayload($data, $storedRules, $creating);
                        app(Changes::class)->ruleDraft($user, $rules, $base?->status === 'draft' ? $base->id : null, $base?->revision);
                    }, 5);
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }

                    $action->failureNotificationTitle(__('feedback.rules_stale'))->sendFailureNotification();
                    $action->halt();
                }

                $action->success();
            });

        if (! $creating) {
            $action->fillForm(function (?RuleVersion $record): array {
                abort_unless($record, 404);
                $rules = PopupPolicy::normalizeRules($record->rules);
                $rules['enabled_transaction_types'] = collect($rules['transaction_rules'])
                    ->where('enabled', true)->pluck('transaction_type')
                    ->intersect(PopupPolicy::CUSTOMER_TRANSACTION_TYPES)->values()->all();

                return $rules + ['revision' => $record->revision, 'version_status' => $record->status];
            });
        }

        return $action;
    }

    private static function rulePayload(array $data, array $storedRules, bool $dateOnly): array
    {
        $enabledTypes = $data['enabled_transaction_types'] ?? [];
        unset($data['enabled_transaction_types'], $data['revision'], $data['version_status']);

        $legacyTypes = collect(PopupPolicy::normalizeRules($storedRules)['transaction_rules'])
            ->whereNotIn('transaction_type', PopupPolicy::CUSTOMER_TRANSACTION_TYPES)->values()->all();
        $mobileTypes = array_map(fn (string $type): array => [
            'transaction_type' => $type,
            'enabled' => in_array($type, $enabledTypes, true),
        ], PopupPolicy::CUSTOMER_TRANSACTION_TYPES);

        $data['transaction_rules'] = [...$mobileTypes, ...$legacyTypes];
        $data['customer_service'] = ChannelPolicy::customerService($storedRules);
        $data['channels']['always_on'] = (bool) ($storedRules['channels']['always_on'] ?? true);

        if ($dateOnly) {
            foreach (ChannelPolicy::schedule($data) as $boundary => $date) {
                $data['popup_schedule'][$boundary] = filled($date)
                    ? CarbonImmutable::parse($date, 'Asia/Vientiane')->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z')
                    : null;
            }
        }

        return PopupPolicy::normalizeRules($data);
    }
}
