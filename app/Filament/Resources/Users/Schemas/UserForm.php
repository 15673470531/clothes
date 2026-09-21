<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('openid'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('nickname'),
                TextInput::make('avatar_url')
                    ->url(),
                // 额度（余额制）：开会员/套餐就在这两个数字上加，加多少用户就能再录多少件
                TextInput::make('item_quota')
                    ->label('可上传衣物数（总余额）')
                    ->numeric()
                    ->default(200)
                    ->required(),
                TextInput::make('daily_quota')
                    ->label('今天还能录几件')
                    ->numeric()
                    ->default(50)
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->revealable(),
            ]);
    }
}
