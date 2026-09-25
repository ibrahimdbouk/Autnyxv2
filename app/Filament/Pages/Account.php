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
                    // WP2.4 (audit L4): a hijacked session must not be able to swap the
                    // email (then reset the password) — re-authenticate for it.
                    TextInput::make('current_password')
                        ->label('Current password (required to change your email)')
                        ->password()
                        ->revealable()
                        ->rules(fn ($get) => $get('email') !== auth()->user()?->email ? ['required', 'current_password'] : [])
                        ->dehydrated(false)
                        ->validationMessages(['current_password' => 'That is not your current password.']),
                ])
                ->action(function (array $data): void {
                    auth()->user()->update([
                        'name'  => $data['name'],
                        'email' => $data['email'],
                    ]);
                    Notification::make()->title('Profile updated')->success()->send();
                }),

            // WP5.4: each person chooses whether they get the daily anomaly digest.
            Action::make('digest')
                ->label(fn () => auth()->user()?->digest_opt_in ? 'Stop the daily digest' : 'Get the daily digest')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => auth()->user()?->digest_opt_in
                    ? 'You will no longer receive the anomaly digest email.'
                    : 'Each morning, after the overnight analysis, you get one email with the new anomalies at the severities your organisation alerts on.')
                ->action(function (): void {
                    $user = auth()->user();
                    $user->update(['digest_opt_in' => ! $user->digest_opt_in]);
                    Notification::make()->title($user->digest_opt_in ? 'Daily digest on' : 'Daily digest off')->success()->send();
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
