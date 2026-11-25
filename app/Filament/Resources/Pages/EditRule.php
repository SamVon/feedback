<?php

namespace App\Filament\Resources\Pages;

use App\Application\Access;
use App\Filament\Resources\RuleResource;
use Filament\Resources\Pages\Page;

class EditRule extends Page
{
    protected static string $resource = RuleResource::class;

    public function mount(?string $record = null): void
    {
        Access::check(auth()->user(), 'rules.read');
        Access::check(auth()->user(), 'rules.edit');

        if ($record === null) {
            $this->redirect(RuleResource::getUrl('index', ['tableAction' => 'draft']));

            return;
        }

        $version = RuleResource::getEloquentQuery()->findOrFail($record);
        $this->redirect(RuleResource::getUrl('index', [
            'tableAction' => $version->status === 'draft' ? 'editDraft' : 'newVersion',
            'tableActionRecord' => $version->id,
        ]));
    }
}
