<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service account page — every authenticated user can edit their own
 * name/email and change their own password (current password verified).
 * Registered on the admin panel (auto-discovered) and the ops panel
 * (explicitly, in OpsPanelProvider). Removes the need for a tinker/CLI reset.
 */
class Account extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'My Account';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.account';

    public function getTitle(): string
    {
        return 'My Account';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit_profile')
                ->label('Edit profile')
                ->icon('heroicon-o-pencil-square')
                ->fillForm(fn (): array => [
                    'name'  => auth()->user()?->name,
                    'email' => auth()->user()?->email,
                ])
                ->form([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->rule(fn () => Rule::unique('users', 'email')->ignore(auth()->id())),
                ])
                ->action(function (array $data): void {
                    auth()->user()->update([
                        'name'  => $data['name'],
                        'email' => $data['email'],
                    ]);
                    Notification::make()->title('Profile updated')->success()->send();
                }),

            Action::make('change_password')
                ->label('Change password')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->form([
                    TextInput::make('current_password')
                        ->label('Current password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule('current_password')
                        ->validationMessages([
                            'current_password' => 'That is not your current password.',
                        ]),
                    TextInput::make('password')
                        ->label('New password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::defaults())
                        ->rule('confirmed'),
                    TextInput::make('password_confirmation')
                        ->label('Confirm new password')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    auth()->user()->update([
                        'password' => Hash::make($data['password']),
                    ]);
                    Notification::make()
                        ->title('Password changed')
                        ->body('Use your new password next time you sign in.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
