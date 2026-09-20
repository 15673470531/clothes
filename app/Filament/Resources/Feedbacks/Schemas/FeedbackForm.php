<?php

namespace App\Filament\Resources\Feedbacks\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FeedbackForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('content')
                    ->label('反馈内容')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('contact')
                    ->label('联系方式'),
            ]);
    }
}
