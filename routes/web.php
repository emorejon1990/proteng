<?php

use Livewire\Volt\Volt;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Middleware\RedirectByRole;
use App\Filament\Pages\Auth\CustomLogin;

// Route::get('/', function () {
//     return view('welcome');
// })->name('home');

// Route::view('dashboard', 'dashboard')
//     ->middleware(['auth', 'verified'])
//     ->name('dashboard');

// Route::middleware(['auth'])->group(function () {
//     Route::redirect('settings', 'settings/profile');

//     Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
//     Volt::route('settings/password', 'settings.password')->name('settings.password');
//     Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
// });

require __DIR__.'/auth.php';

Route::get('/login', CustomLogin::class)->middleware('guest')->name('login');

// Redirección después de login según rol
Route::get('/', fn() => redirect('/'))->middleware(RedirectByRole::class);

Route::put('/product/{product}', [\App\Http\Controllers\ProductController::class, 'update'])->name('product.update');

Route::get('/offline', function () {
    return view('vendor/laravelpwa/offline');
});

Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect']);
Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback']);

Route::middleware(['quickbooks'])->group(function () {

    Route::get('/quickbooks/customers', [QuickBooksController::class, 'customers']);
    Route::get('/quickbooks/invoices', [QuickBooksController::class, 'invoices']);

});
