<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarouselResource\Pages;
use App\Models\Carousel;
use App\Services\Carousels\CarouselApproval;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Carousels: each one is a curation run for a niche, reviewed and approved
 * on its own page, which then writes the Canva AI prompt. Every user only sees their own.
 */
class CarouselResource extends Resource
{
    protected static ?string $model = Carousel::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 26;

    protected static ?string $recordTitleAttribute = 'niche';

    public const STATUS_COLORS = [
        Carousel::STATUS_DISCOVERING => 'info', Carousel::STATUS_REVIEW => 'warning',
        Carousel::STATUS_APPROVED => 'success', Carousel::STATUS_FAILED => 'danger',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->currentUser()
            ->withCount(['candidates', 'candidates as selection_count' => fn (Builder $query) => $query->whereNotNull('position')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->defaultSort('id', 'desc')
            ->columns([
                Stack::make([
                    Tables\Columns\ImageColumn::make('cover')
                        ->getStateUsing(fn (Carousel $record): ?string => $record->coverImage())
                        ->height(220)
                        ->extraImgAttributes(['class' => 'object-contain w-full rounded-md bg-white']),
                    Tables\Columns\TextColumn::make('niche')->weight(FontWeight::Bold)->searchable(),
                    Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray'),
                    Tables\Columns\TextColumn::make('selection_count')
                        ->formatStateUsing(fn (int $state, Carousel $record): string => "{$state} selected · {$record->candidates_count} candidates")
                        ->color('gray'),
                    Tables\Columns\TextColumn::make('created_at')->since()->sortable()->color('gray')->size('xs'),
                ])->space(2),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(array_combine(array_keys(self::STATUS_COLORS), array_keys(self::STATUS_COLORS))),
            ])
            ->actions([
                self::duplicateAction(Tables\Actions\Action::make('duplicate')),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (Carousel $record): bool => $record->isBusy())
                    ->modalDescription('The carousel and its candidates are deleted. Products already added to your catalog are kept.'),
            ])
            ->recordUrl(fn (Carousel $record): string => self::getUrl('view', ['record' => $record]));
    }

    /**
     * Shared by the list and the carousel page.
     *
     * @template T of \Filament\Actions\Action|Tables\Actions\Action
     *
     * @param  T  $action
     * @return T
     */
    public static function duplicateAction($action)
    {
        return $action
            ->label('Duplicate')
            ->icon('heroicon-m-document-duplicate')
            ->color('gray')
            ->hidden(fn (Carousel $record): bool => $record->status === Carousel::STATUS_DISCOVERING)
            ->requiresConfirmation()
            ->modalDescription('A new carousel in review with the same candidates and selection. This one keeps its approval.')
            ->action(function (Carousel $record) {
                try {
                    $copy = app(CarouselApproval::class)->duplicate($record);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return null;
                }

                return redirect(self::getUrl('view', ['record' => $copy]));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarousels::route('/'),
            'view' => Pages\ViewCarousel::route('/{record}'),
        ];
    }
}
