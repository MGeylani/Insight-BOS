<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\RoleTemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\ClientController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\OpportunityController;
use App\Http\Controllers\Crm\QuoteController;
use App\Http\Controllers\Crm\ServiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\NumberingController;
use App\Http\Controllers\Settings\ServiceCategoryController;
use App\Http\Controllers\Settings\StorageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:20,1');
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::redirect('/', '/dashboard');
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');

    // CRM. Record-level checks (own / assigned / all) happen in the controllers.
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::middleware('can:crm.view')->group(function () {
            Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
            Route::get('leads/duplicates', [LeadController::class, 'duplicates'])->name('leads.duplicates');
            Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::get('opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
            Route::get('opportunities/{opportunity}', [OpportunityController::class, 'show'])->name('opportunities.show');
            Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');
            Route::post('activities', [ActivityController::class, 'store'])->name('activities.store');
            Route::put('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
            Route::post('activities/{activity}/complete', [ActivityController::class, 'complete'])->name('activities.complete');
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('quotes', [QuoteController::class, 'index'])->name('quotes.index');
            Route::get('quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote')->name('quotes.show');
            Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
        });
        Route::middleware('can:crm.create')->group(function () {
            Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::post('opportunities', [OpportunityController::class, 'store'])->name('opportunities.store');
            Route::get('quotes/create', [QuoteController::class, 'create'])->name('quotes.create');
            Route::post('quotes', [QuoteController::class, 'store'])->name('quotes.store');
            Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->name('quotes.duplicate');
        });
        Route::middleware('can:crm.edit')->group(function () {
            Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
            Route::post('leads/{lead}/move', [LeadController::class, 'move'])->name('leads.move');
            Route::post('leads/{lead}/merge', [LeadController::class, 'merge'])->name('leads.merge');
            Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            Route::post('clients/{client}/contacts', [ContactController::class, 'store'])->name('contacts.store');
            Route::put('clients/{client}/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
            Route::delete('clients/{client}/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
            Route::put('opportunities/{opportunity}', [OpportunityController::class, 'update'])->name('opportunities.update');
            Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'stage'])->name('opportunities.stage');
            Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
            Route::get('quotes/{quote}/edit', [QuoteController::class, 'edit'])->name('quotes.edit');
            Route::put('quotes/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
            Route::post('quotes/{quote}/issue', [QuoteController::class, 'issue'])->name('quotes.issue');
            Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
            Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('quotes.reject');
            Route::post('quotes/{quote}/cancel', [QuoteController::class, 'cancel'])->name('quotes.cancel');
        });
        Route::middleware('can:crm.delete')->group(function () {
            Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
            Route::delete('opportunities/{opportunity}', [OpportunityController::class, 'destroy'])->name('opportunities.destroy');
            Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
        });
        Route::middleware('can:settings.manage')->group(function () {
            Route::post('services', [ServiceController::class, 'store'])->name('services.store');
            Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
        });
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('can:crm.convert')->name('leads.convert');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::middleware('can:users.manage')->group(function () {
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');

            Route::get('roles', [RoleTemplateController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleTemplateController::class, 'store'])->name('roles.store');
            Route::put('roles/{roleTemplate}', [RoleTemplateController::class, 'update'])->name('roles.update');
            Route::delete('roles/{roleTemplate}', [RoleTemplateController::class, 'destroy'])->name('roles.destroy');
        });
        Route::get('activity', [ActivityLogController::class, 'index'])->middleware('can:audit.view')->name('activity.index');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::middleware('can:settings.manage')->group(function () {
            Route::get('company', [CompanyController::class, 'edit'])->name('company');
            Route::put('company', [CompanyController::class, 'update'])->name('company.update');
            Route::put('quote-defaults', [CompanyController::class, 'updateQuotes'])->name('quote-defaults.update');
            Route::get('service-categories', [ServiceCategoryController::class, 'index'])->name('service-categories');
            Route::post('service-categories', [ServiceCategoryController::class, 'store'])->name('service-categories.store');
            Route::put('service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update'])->name('service-categories.update');
            Route::get('numbering', [NumberingController::class, 'index'])->name('numbering');
            Route::put('numbering/{numberSequence}', [NumberingController::class, 'update'])->name('numbering.update');
            Route::put('currencies/{currency}', [CurrencyController::class, 'update'])->name('currencies.update');
        });
        Route::middleware('can:settings.exchange_rates')->group(function () {
            Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies');
            Route::post('currencies/rates', [CurrencyController::class, 'storeRates'])->name('currencies.rates');
        });
        Route::middleware('can:settings.storage')->group(function () {
            Route::get('storage', [StorageController::class, 'show'])->name('storage');
            Route::put('storage', [StorageController::class, 'update'])->name('storage.update');
        });
    });
});
