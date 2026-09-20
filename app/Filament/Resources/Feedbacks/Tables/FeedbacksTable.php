<?php

namespace App\Filament\Resources\Feedbacks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeedbacksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('用户')
                    ->searchable()
                    ->placeholder('未登录提交'),
                TextColumn::make('content')
                    ->label('反馈内容')
                    ->wrap()
                    ->limit(80)
                    ->searchable(),
                TextColumn::make('contact')
                    ->label('联系方式')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),   // 小程序端已不收联系方式，默认隐藏
                TextColumn::make('created_at')
                    ->label('提交时间')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
