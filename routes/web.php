<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\RoleTemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\ClientController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\Crm\ContractController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\OpportunityController;
use App\Http\Controllers\Crm\QuoteController;
use App\Http\Controllers\Crm\ServiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectItemController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\NumberingController;
use App\Http\Controllers\Settings\ServiceCategoryController;
use App\Http\Controllers\Settings\StorageController;
use App\Http\Controllers\Tasks\ChecklistController;
use App\Http\Controllers\Tasks\CommentController;
use App\Http\Controllers\Tasks\CustomFieldController;
use App\Http\Controllers\Tasks\DependencyController;
use App\Http\Controllers\Tasks\WorkloadController;
use App\Http\Controllers\Tasks\AutomationController;
use App\Http\Controllers\Tasks\IntakeFormController;
use App\Http\Controllers\Tasks\AccessController;
use App\Http\Controllers\Tasks\ProofController;
use App\Http\Controllers\Tasks\TemplateController;
use App\Http\Controllers\Tasks\TimesheetController;
use App\Http\Controllers\Tasks\TaskTypeController;
use App\Http\Controllers\Tasks\SavedViewController;
use App\Http\Controllers\Tasks\MyWorkController;
use App\Http\Controllers\Tasks\ReminderController;
use App\Http\Controllers\Tasks\TimeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\TaskListController;
use App\Http\Controllers\Tasks\TaskSettingsController;
use App\Http\Controllers\Finance\InvoiceController;
use Illuminate\Support\Facades\Route;

// Intake forms: public ones open without signing in; internal ones send guests to the login page.
Route::get('forms/{token}', [IntakeFormController::class, 'show'])->name('forms.show');
Route::post('forms/{token}', [IntakeFormController::class, 'submit'])->middleware('throttle:10,1')->name('forms.submit');

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

    // Attachments on any record; access follows the record's own permissions (checked in the controller).
    Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:60,1')->name('attachments.store');
    Route::get('attachments/{attachment}/open', [AttachmentController::class, 'open'])->name('attachments.open');
    Route::get('attachments/{attachment}/inline', [AttachmentController::class, 'inline'])->name('attachments.inline');
    Route::post('attachments/{attachment}/retry', [AttachmentController::class, 'retry'])->name('attachments.retry');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/unread', [InboxController::class, 'unread'])->name('notifications.unread');
    Route::delete('notifications', [InboxController::class, 'destroy'])->name('notifications.destroy');
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');

    // My Work, reminders and time tracking.
    Route::middleware('can:tasks.view')->group(function () {
        Route::get('my-work', [MyWorkController::class, 'index'])->name('my-work');
        Route::get('my-work/calendar', [MyWorkController::class, 'calendar'])->name('my-work.calendar');
        Route::post('reminders', [ReminderController::class, 'store'])->name('reminders.store');
        Route::patch('reminders/{reminder}', [ReminderController::class, 'update'])->name('reminders.update');
        Route::delete('reminders/{reminder}', [ReminderController::class, 'destroy'])->name('reminders.destroy');
    });
    Route::middleware('can:tasks.track_time')->group(function () {
        Route::get('my-work/time', [MyWorkController::class, 'time'])->name('my-work.time');
        Route::post('timer/start', [TimeController::class, 'start'])->name('timer.start');
        Route::post('timer/stop', [TimeController::class, 'stop'])->name('timer.stop');
        Route::post('time-entries', [TimeController::class, 'store'])->name('time-entries.store');
        Route::patch('time-entries/{entry}', [TimeController::class, 'update'])->name('time-entries.update');
        Route::delete('time-entries/{entry}', [TimeController::class, 'destroy'])->name('time-entries.destroy');
        Route::post('timesheets/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
    });
    Route::middleware('can:tasks.approve_time')->group(function () {
        Route::get('tasks/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
        Route::post('timesheets/{timesheet}/decide', [TimesheetController::class, 'decide'])->name('timesheets.decide');
    });

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
            Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
            Route::get('contracts/{contract}', [ContractController::class, 'show'])->whereNumber('contract')->name('contracts.show');
            Route::get('contracts/{contract}/pdf', [ContractController::class, 'pdf'])->name('contracts.pdf');
        });
        Route::middleware('can:crm.create')->group(function () {
            Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::post('opportunities', [OpportunityController::class, 'store'])->name('opportunities.store');
            Route::get('quotes/create', [QuoteController::class, 'create'])->name('quotes.create');
            Route::post('quotes', [QuoteController::class, 'store'])->name('quotes.store');
            Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->name('quotes.duplicate');
            Route::get('contracts/create', [ContractController::class, 'create'])->name('contracts.create');
            Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
            Route::post('contracts/{contract}/duplicate', [ContractController::class, 'duplicate'])->name('contracts.duplicate');
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
            Route::get('contracts/{contract}/edit', [ContractController::class, 'edit'])->name('contracts.edit');
            Route::put('contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
            Route::post('contracts/{contract}/issue', [ContractController::class, 'issue'])->name('contracts.issue');
            Route::post('contracts/{contract}/sign', [ContractController::class, 'sign'])->name('contracts.sign');
            Route::post('contracts/{contract}/complete', [ContractController::class, 'complete'])->name('contracts.complete');
            Route::post('contracts/{contract}/terminate', [ContractController::class, 'terminate'])->name('contracts.terminate');
            Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
            Route::post('contracts/{contract}/renew', [ContractController::class, 'renew'])->name('contracts.renew');
            Route::post('contracts/{contract}/schedule/{row}', [ContractController::class, 'milestone'])->name('contracts.milestone');
        });
        Route::middleware('can:crm.delete')->group(function () {
            Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
            Route::delete('opportunities/{opportunity}', [OpportunityController::class, 'destroy'])->name('opportunities.destroy');
            Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
            Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
        });
        Route::middleware('can:settings.manage')->group(function () {
            Route::post('services', [ServiceController::class, 'store'])->name('services.store');
            Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
        });
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('can:crm.convert')->name('leads.convert');
    });

    // Projects. Record-level checks (own / assigned / all) happen in the controllers.
    Route::prefix('projects')->group(function () {
        Route::middleware('can:projects.view')->group(function () {
            Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
        });
        Route::middleware('can:projects.create')->group(function () {
            Route::get('create', [ProjectController::class, 'create'])->name('projects.create');
            Route::post('/', [ProjectController::class, 'store'])->name('projects.store');
        });
        Route::middleware('can:projects.edit')->group(function () {
            Route::get('{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
            Route::put('{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::post('{project}/status', [ProjectController::class, 'status'])->name('projects.status');

            Route::post('{project}/milestones', [ProjectItemController::class, 'storeMilestone'])->name('projects.milestones.store');
            Route::put('{project}/milestones/{milestone}', [ProjectItemController::class, 'updateMilestone'])->name('projects.milestones.update');
            Route::post('{project}/milestones/{milestone}/toggle', [ProjectItemController::class, 'toggleMilestone'])->name('projects.milestones.toggle');
            Route::post('{project}/milestones/{milestone}/move', [ProjectItemController::class, 'moveMilestone'])->name('projects.milestones.move');
            Route::delete('{project}/milestones/{milestone}', [ProjectItemController::class, 'destroyMilestone'])->name('projects.milestones.destroy');

            Route::post('{project}/risks', [ProjectItemController::class, 'storeRisk'])->name('projects.risks.store');
            Route::put('{project}/risks/{risk}', [ProjectItemController::class, 'updateRisk'])->name('projects.risks.update');
            Route::delete('{project}/risks/{risk}', [ProjectItemController::class, 'destroyRisk'])->name('projects.risks.destroy');

            Route::post('{project}/issues', [ProjectItemController::class, 'storeIssue'])->name('projects.issues.store');
            Route::put('{project}/issues/{issue}', [ProjectItemController::class, 'updateIssue'])->name('projects.issues.update');
            Route::delete('{project}/issues/{issue}', [ProjectItemController::class, 'destroyIssue'])->name('projects.issues.destroy');

            Route::post('{project}/changes', [ProjectItemController::class, 'storeChange'])->name('projects.changes.store');
            Route::delete('{project}/changes/{change}', [ProjectItemController::class, 'destroyChange'])->name('projects.changes.destroy');
        });
        Route::middleware('can:projects.approve_changes')->group(function () {
            Route::post('{project}/changes/{change}/approve', [ProjectItemController::class, 'approveChange'])->name('projects.changes.approve');
            Route::post('{project}/changes/{change}/reject', [ProjectItemController::class, 'rejectChange'])->name('projects.changes.reject');
        });
        Route::delete('{project}', [ProjectController::class, 'destroy'])->middleware('can:projects.delete')->name('projects.destroy');
    });

    // Finance (phase 4). Record-level checks (own / all) happen in the controllers.
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::middleware('can:finance.view')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
            Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
            Route::get('billable-time/{project}', [InvoiceController::class, 'billableTime'])->name('billable-time');
            Route::get('pending-schedules/{client}', [InvoiceController::class, 'pendingSchedules'])->name('pending-schedules');
        });
        Route::middleware('can:finance.create')->group(function () {
            Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::post('invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoices.duplicate');
        });
        Route::middleware('can:finance.edit')->group(function () {
            Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
        });
        Route::middleware('can:finance.delete')->group(function () {
            Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
        });
    });

    // Tasks. Record-level checks (own / assigned / all) happen in the controllers.
    Route::prefix('tasks')->name('tasks.')->group(function () {
        Route::middleware('can:tasks.view')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::get('export', [TaskController::class, 'export'])->name('export');
            Route::get('export/gantt', [TaskController::class, 'exportGantt'])->name('export.gantt');
            Route::get('lookup', [DependencyController::class, 'lookup'])->name('lookup');
            Route::get('workload', [WorkloadController::class, 'index'])->name('workload');
            Route::get('automations', [AutomationController::class, 'index'])->name('automations');
            Route::get('forms', [IntakeFormController::class, 'index'])->name('forms');
            Route::get('forms/fields', [IntakeFormController::class, 'fields'])->name('forms.fields');
            // Sharing (checked per place in the controller: who manages the client / project / list, or users.manage).
            Route::get('access', [AccessController::class, 'index'])->name('access.index');
            Route::post('access', [AccessController::class, 'store'])->name('access.store');
            Route::post('access/invite', [AccessController::class, 'invite'])->middleware('throttle:20,1')->name('access.invite');
            Route::post('access/link/{user}', [AccessController::class, 'link'])->name('access.link');
            Route::put('access/{grant}', [AccessController::class, 'update'])->name('access.update');
            Route::delete('access/{grant}', [AccessController::class, 'destroy'])->name('access.destroy');
            Route::get('{task}/proof/{attachment}', [ProofController::class, 'show'])->whereNumber('task')->name('proof');
            Route::post('proof/{attachment}/comments', [ProofController::class, 'comment'])->middleware('throttle:60,1')->name('proof.comment');
            Route::patch('proof-comments/{comment}', [ProofController::class, 'update'])->name('proof.update');
            Route::delete('proof-comments/{comment}', [ProofController::class, 'destroy'])->name('proof.destroy');
            Route::post('proof/{attachment}/version', [ProofController::class, 'version'])->name('proof.version');
            Route::put('workload/capacity/{person}', [WorkloadController::class, 'capacity'])->name('workload.capacity');
            Route::post('time-off', [WorkloadController::class, 'storeTimeOff'])->name('time-off.store');
            Route::delete('time-off/{timeOff}', [WorkloadController::class, 'destroyTimeOff'])->name('time-off.destroy');
            Route::post('views', [SavedViewController::class, 'store'])->name('views.store');
            Route::put('views/{view}', [SavedViewController::class, 'update'])->name('views.update');
            Route::delete('views/{view}', [SavedViewController::class, 'destroy'])->name('views.destroy');
            Route::post('bulk', [TaskController::class, 'bulk'])->name('bulk');
            Route::get('{task}', [TaskController::class, 'show'])->whereNumber('task')->name('show');
            Route::post('{task}/watch', [TaskController::class, 'watch'])->name('watch');
            Route::post('{task}/comments', [CommentController::class, 'store'])->middleware('throttle:60,1')->name('comments.store');
            Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
            Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
            Route::post('comments/{comment}/resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
            Route::post('comments/{comment}/assign', [CommentController::class, 'assign'])->name('comments.assign');
        });
        Route::post('/', [TaskController::class, 'store'])->middleware('can:tasks.create')->name('store');
        Route::post('templates/{template}/apply', [TemplateController::class, 'apply'])->middleware('can:tasks.create')->name('templates.apply');
        Route::middleware('can:tasks.edit')->group(function () {
            Route::patch('{task}', [TaskController::class, 'update'])->name('update');
            Route::post('{task}/move', [TaskController::class, 'move'])->name('move');
            Route::post('{task}/lists', [TaskController::class, 'link'])->name('link');
            Route::delete('{task}/lists/{list}', [TaskController::class, 'unlink'])->name('unlink');
            Route::post('{task}/parent', [TaskController::class, 'parent'])->name('parent');
            Route::post('{task}/reorder', [TaskController::class, 'reorder'])->name('reorder');
            Route::post('{task}/checklists', [ChecklistController::class, 'store'])->name('checklists.store');
            Route::put('checklists/{checklist}', [ChecklistController::class, 'update'])->name('checklists.update');
            Route::delete('checklists/{checklist}', [ChecklistController::class, 'destroy'])->name('checklists.destroy');
            Route::post('checklists/{checklist}/items', [ChecklistController::class, 'storeItem'])->name('checklists.items.store');
            Route::patch('checklist-items/{item}', [ChecklistController::class, 'updateItem'])->name('checklists.items.update');
            Route::delete('checklist-items/{item}', [ChecklistController::class, 'destroyItem'])->name('checklists.items.destroy');
            Route::post('lists', [TaskListController::class, 'store'])->name('lists.store');
            Route::put('lists/{list}', [TaskListController::class, 'update'])->name('lists.update');
            Route::delete('lists/{list}', [TaskListController::class, 'destroy'])->name('lists.destroy');
            Route::put('lists/{list}/statuses', [TaskListController::class, 'statuses'])->name('lists.statuses');
            Route::post('tags', [TaskSettingsController::class, 'storeTag'])->name('tags.store');
            Route::post('fields', [CustomFieldController::class, 'store'])->name('fields.store');
            Route::put('fields/{field}', [CustomFieldController::class, 'update'])->name('fields.update');
            Route::delete('fields/{field}', [CustomFieldController::class, 'destroy'])->name('fields.destroy');
            Route::put('{task}/fields/{field}', [CustomFieldController::class, 'setValue'])->name('fields.value');
            Route::post('{task}/dependencies', [DependencyController::class, 'store'])->name('dependencies.store');
            Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
            Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
            Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
            Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
            Route::post('forms', [IntakeFormController::class, 'store'])->name('forms.store');
            Route::put('forms/{form}', [IntakeFormController::class, 'update'])->name('forms.update');
            Route::delete('forms/{form}', [IntakeFormController::class, 'destroy'])->name('forms.destroy');
            Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
            Route::delete('dependencies/{dependency}', [DependencyController::class, 'destroy'])->name('dependencies.destroy');
        });
        Route::middleware('can:tasks.delete')->group(function () {
            Route::delete('{task}', [TaskController::class, 'destroy'])->name('destroy');
            Route::post('{id}/restore', [TaskController::class, 'restore'])->whereNumber('id')->name('restore');
        });
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
            Route::put('contract-defaults', [CompanyController::class, 'updateContracts'])->name('contract-defaults.update');
            Route::put('invoice-defaults', [CompanyController::class, 'updateInvoices'])->name('invoice-defaults.update');
            Route::get('service-categories', [ServiceCategoryController::class, 'index'])->name('service-categories');
            Route::post('service-categories', [ServiceCategoryController::class, 'store'])->name('service-categories.store');
            Route::put('service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update'])->name('service-categories.update');
            Route::get('numbering', [NumberingController::class, 'index'])->name('numbering');
            Route::put('numbering/{numberSequence}', [NumberingController::class, 'update'])->name('numbering.update');
            Route::put('currencies/{currency}', [CurrencyController::class, 'update'])->name('currencies.update');
            Route::get('tasks', [TaskSettingsController::class, 'index'])->name('tasks');
            Route::put('tags/{tag}', [TaskSettingsController::class, 'updateTag'])->name('tags.update');
            Route::delete('tags/{tag}', [TaskSettingsController::class, 'destroyTag'])->name('tags.destroy');
            Route::put('status-templates/{scheme}', [TaskSettingsController::class, 'updateTemplate'])->name('status-templates.update');
            Route::put('internal-prefix', [TaskSettingsController::class, 'updatePrefix'])->name('internal-prefix');
            Route::post('task-types', [TaskTypeController::class, 'store'])->name('task-types.store');
            Route::put('task-types/{type}', [TaskTypeController::class, 'update'])->name('task-types.update');
            Route::delete('task-types/{type}', [TaskTypeController::class, 'destroy'])->name('task-types.destroy');
        });
        Route::middleware('can:settings.exchange_rates')->group(function () {
            Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies');
            Route::post('currencies/rates', [CurrencyController::class, 'storeRates'])->name('currencies.rates');
        });
        Route::middleware('can:settings.storage')->group(function () {
            Route::get('storage', [StorageController::class, 'show'])->name('storage');
            Route::put('storage', [StorageController::class, 'update'])->name('storage.update');
            Route::get('storage/connect/{provider}', [StorageController::class, 'connect'])->name('storage.connect');
            Route::get('storage/callback/{provider}', [StorageController::class, 'callback'])->name('storage.callback');
            Route::post('storage/webdav', [StorageController::class, 'webdav'])->name('storage.webdav');
            Route::post('storage/test', [StorageController::class, 'test'])->name('storage.test');
            Route::post('storage/disconnect', [StorageController::class, 'disconnect'])->name('storage.disconnect');
            Route::post('storage/retry', [StorageController::class, 'retry'])->name('storage.retry');
        });
    });
});
