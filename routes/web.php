<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\RoleTemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
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
