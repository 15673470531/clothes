<?php

namespace App\Filament\Resources\Feedbacks;

use App\Filament\Resources\Feedbacks\Pages\EditFeedback;
use App\Filament\Resources\Feedbacks\Pages\ListFeedbacks;
use App\Filament\Resources\Feedbacks\Schemas\FeedbackForm;
use App\Filament\Resources\Feedbacks\Tables\FeedbacksTable;
use App\Models\Feedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * 意见反馈（小程序「我的 → 意见反馈」提交的内容）
 * 只做查看/编辑/删除，不给后台新增（反馈必须来自用户）
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?string $navigationLabel = '意见反馈';

    protected static ?string $modelLabel = '反馈';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return FeedbackForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeedbacksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedbacks::route('/'),
            'edit' => EditFeedback::route('/{record}/edit'),
        ];
    }
}
