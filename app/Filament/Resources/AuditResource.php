<?php

namespace App\Filament\Resources;

use App\Application\Access;
use App\Filament\Support\ReadOnlyResource;
use App\Models\AuditEvent;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditResource extends ReadOnlyResource
{
    protected static ?string $model = AuditEvent::class;

    protected static string $permission = 'audit.read';

    protected static ?string $navigationLabel = 'audit';

    protected static ?int $navigationSort = 8;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(), TextColumn::make('actor')->searchable(), TextColumn::make('operation')->searchable()->badge(), TextColumn::make('entity_id')->searchable(),
        ])->defaultSort('created_at', 'desc')->recordActions([Action::make('details')->label(__('feedback.details'))->modalContent(function ($record) {
            Access::check(auth()->user(), 'audit.read');

            return view('filament.partials.json', ['data' => $record->details]);
        })->modalSubmitAction(false)]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAudit::route('/')];
    }
}
