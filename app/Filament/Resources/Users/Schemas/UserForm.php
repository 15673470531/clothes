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
                // 衣架（余额制）：开会员/套餐就在这两个数字上加，加几个用户就能多挂几样东西
                // （一件衣物 = 一个衣架，一套搭配 = 一个衣架，共用一个架子）
                TextInput::make('item_quota')
                    ->label('衣架余额（总）')
                    ->helperText('还剩几个衣架。加 N 就是多给 N 个（挂衣物或搭配都行）。')
                    ->numeric()
                    ->default(fn () => (int) config('quota.item_quota'))
                    ->required(),
                TextInput::make('daily_quota')
                    ->label('衣架余额（今天）')
                    ->helperText('今天还能挂几个；每天 00:05 自动重置回默认值。')
                    ->numeric()
                    ->default(fn () => (int) config('quota.daily_quota'))
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
