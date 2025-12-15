<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ForcePasswordChange extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.force-password-change';

    public ?array $data = [];

    public function mount(): void
    {
        if (! Auth::check()) {
            redirect()->route('filament.admin.auth.login');
        }

        if (! Auth::user()->must_change_password) {
            redirect()->route('filament.admin.pages.dashboard');
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('password')
                    ->label('New Password')
                    ->password()
                    ->required()
                    ->minLength(10)
                    ->confirmed(),

                Forms\Components\TextInput::make('password_confirmation')
                    ->label('Confirm Password')
                    ->password()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function submit()
    {
        $this->validate();

        Auth::user()->update([
            'password' => Hash::make($this->data['password']),
            'must_change_password' => false,
        ]);

        Notification::make()
            ->title('Updated Password')
            ->success()
            ->send();

        return redirect()->route('filament.admin.pages.dashboard');
    }
}
