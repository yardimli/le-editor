<?php

use App\Http\Controllers\BookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('books.index') : view('welcome'));
Route::post('/locale', function (Request $request) {
    $data = $request->validate(['locale' => 'required|in:tr,en']);
    session($data);
    $request->user()?->update($data);
    return back();
})->name('locale');

Route::middleware('auth')->group(function () {
    Route::get('/queue/state', [\App\Http\Controllers\QueueController::class, 'state']);
    Route::post('/queue/step', [\App\Http\Controllers\QueueController::class, 'step']);
    Route::post('/queue/cancel', [\App\Http\Controllers\QueueController::class, 'cancel']);
    Route::post('/queue/resume', [\App\Http\Controllers\QueueController::class, 'resume']);
    Route::get('/dashboard', fn () => redirect()->route('books.index'))->name('dashboard');
    Route::get('/books', [BookController::class, 'index'])->name('books.index');
    Route::post('/books', [BookController::class, 'store'])->middleware('throttle:10,1')->name('books.store');
    Route::get('/books/{book}', [BookController::class, 'show'])->name('books.show');
    Route::get('/books/{book}/api-history', [BookController::class, 'apiHistory'])->name('books.api-history');
    Route::get('/books/{book}/history', [BookController::class, 'activity'])->name('books.activity');
    Route::get('/books/{book}/state', [BookController::class, 'state'])->name('books.state');
    Route::get('/books/{book}/runs/{run}', [BookController::class, 'history'])->name('books.history');
    Route::patch('/books/{book}/details', [BookController::class, 'details'])->name('books.details');
    Route::patch('/books/{book}', [BookController::class, 'update'])->name('books.update');
    Route::put('/books/{book}/chunks/{chunk}/text', [BookController::class, 'saveChunk']);
    Route::post('/books/{book}/learning/dismiss', [BookController::class, 'dismissLearning']);
    Route::put('/books/{book}/text', [BookController::class, 'save'])->name('books.text');
    Route::post('/books/{book}/run', [BookController::class, 'run'])->middleware('throttle:10,1')->name('books.run');
    Route::post('/books/{book}/cancel', [BookController::class, 'cancel'])->name('books.cancel');
    Route::post('/books/{book}/retry', [BookController::class, 'retry'])->name('books.retry');
    Route::post('/books/{book}/learning/retry', [BookController::class, 'retryLearning'])->name('books.learning');
    Route::post('/books/{book}/restore', [BookController::class, 'restore'])->name('books.restore');
    Route::patch('/books/{book}/chunks/{chunk}', [BookController::class, 'decision'])->name('books.decision');
    Route::get('/books/{book}/export', [BookController::class, 'export'])->name('books.export');
    Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/models', [SettingsController::class, 'models'])->name('models');
    Route::post('/settings/test', [SettingsController::class, 'test'])->middleware('throttle:5,1')->name('settings.test');
    Route::get('/prompts', [SettingsController::class, 'prompts'])->name('prompts');
    Route::post('/prompts', [SettingsController::class, 'savePrompt'])->name('prompts.save');
    Route::delete('/prompts/{preset}', [SettingsController::class, 'deletePrompt'])->name('prompts.delete');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
