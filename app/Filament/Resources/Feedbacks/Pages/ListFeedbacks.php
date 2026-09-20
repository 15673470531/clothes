<?php

namespace App\Filament\Resources\Feedbacks\Pages;

use App\Filament\Resources\Feedbacks\FeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedbacks extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 不给后台新增：反馈只能来自用户提交
        ];
    }
}
