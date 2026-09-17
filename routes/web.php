<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HearController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\RuangController;
use App\Http\Controllers\CallCenterController;
use App\Http\Controllers\LetterLogController;
use App\Http\Controllers\HcoMappingController;
use App\Http\Controllers\NastariDashboardController;

// Route::get('/', function () {
//     return view('auth.login');
// });

Route::get('/sso/callback', [SsoController::class, 'handleCallback'])->name('sso.callback');

Route::middleware(['auth'])->prefix('admin')->group(function () {
    // Dashboard monitoring Nastari. Read-only, dan menggantikan dua halaman
    // sebelumnya: dashboard lama dan halaman "Analitik" tersendiri.
    Route::get('/dashboard', [NastariDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/dashboard/transcript/{id}', [NastariDashboardController::class, 'transcript'])
        ->whereNumber('id')->name('admin.dashboard.transcript');

    // Halaman Analitik sudah dilipat ke dashboard. Nama route-nya tetap
    // didaftarkan sebagai pengalihan supaya tautan yang sudah dibookmark dan
    // tab yang masih terbuka tidak berakhir 404 — bukan karena masih dipakai
    // di menu.
    Route::get('/analytics', fn () => redirect()->route('admin.dashboard'))
        ->name('admin.analytics.index');
    Route::get('/analytics/transcript/{id}', fn (int $id) => redirect()->route('admin.dashboard.transcript', $id))
        ->whereNumber('id')->name('admin.analytics.transcript');

    Route::get('/hc-system-desk', [CallCenterController::class, 'index'])->name('admin.callcenter.index');
    Route::get('/call-center/download/{id}', [CallCenterController::class, 'download'])->name('admin.callcenter.download');
    Route::get('/call-center/export', [CallCenterController::class, 'export'])->name('admin.callcenter.export');

    Route::get('/ruang', [RuangController::class, 'index'])->name('admin.ruang');

    Route::get('/users', [EmployeeController::class, 'index'])->name('admin.users');
    
    Route::get('/admin/letter-logs', [LetterLogController::class, 'index'])->name('admin.letter_logs.index');
    Route::get('/admin/letter-logs/view/{id}', [LetterLogController::class, 'download'])->name('admin.letter_logs.view');

    Route::get('/admin/hco-mapping', [HcoMappingController::class, 'index'])->name('admin.hco_mapping.index');
    Route::post('/admin/hco-mapping', [HcoMappingController::class, 'store'])->name('admin.hco_mapping.store');
    Route::delete('/admin/hco-mapping/{id}', [HcoMappingController::class, 'destroy'])->name('admin.hco_mapping.destroy');
    Route::prefix('settings/roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('admin.roles.index');
        Route::get('/create', [RoleController::class, 'create'])->name('admin.roles.create');
        Route::post('/store', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::get('/get-users', [RoleController::class, 'getFilterUsers'])->name('admin.roles.get_users');
        
        Route::get('/{id}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit');
        Route::put('/{id}', [RoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
    });
});

Route::middleware(['auth'])->prefix('hear')->group(function () {
    Route::get('/', [HearController::class, 'index'])->name('hear.dashboard');
    Route::get('/history', [HearController::class, 'history'])->name('hear.history');
    
    Route::get('/knowledge-base', [HearController::class, 'knowledgeBase'])->name('hear.kb');
    Route::post('/knowledge-base/upload', [HearController::class, 'uploadKb'])->name('hear.kb.upload');
    Route::delete('/knowledge-base/delete', [HearController::class, 'deleteKb'])->name('hear.kb.delete');
    
    Route::get('/kb-files', [HearController::class, 'getKbFiles'])->name('hear.kb.files');
    Route::get('/kb/statuses', [HearController::class, 'getFileStatuses'])->name('hear.kb.statuses');
    Route::post('/kb-sync-single', [HearController::class, 'syncSingleKb'])->name('hear.kb.sync');
    
    Route::post('/kb/add', [HearController::class, 'addToKb'])->name('hear.kb.add');
    Route::post('/hear/kb/add', [App\Http\Controllers\HearController::class, 'addToKb'])->name('hear.kb.add');
    Route::get('/ticket/{id}', [HearController::class, 'showTicket'])->name('hear.ticket.detail');
    Route::post('/ticket/{id}/solve', [HearController::class, 'solveTicket'])->name('hear.ticket.solve');
});

Route::get('/magic-link/{token}', [TicketController::class, 'show'])->name('hear.ticket.show');
Route::post('/magic-link/{token}', [TicketController::class, 'update'])->name('hear.ticket.update');

Route::post('/chat/store', [ChatController::class, 'storeChat']);

// HTTP fallback for the daily employee sync, for deployments where cron does
// not run `schedule:run`. Token-gated, rate limited to one call per hour, and
// returns 404 when TASK_RUNNER_TOKEN is unset. See TaskRunnerController.
Route::get('/tasks/employee-sync', [App\Http\Controllers\TaskRunnerController::class, 'employeeSync'])
    ->name('tasks.employee_sync');

Route::get('/fix-route', function() {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('view:clear');
    return 'Done. Cache Cleared.';
});