<?php

use Illuminate\Support\Facades\Route;
use Modules\Contacts\Http\Controllers\ContactsWebController;

Route::middleware('auth')->prefix('contacts')->group(function () {
    Route::get('/', [ContactsWebController::class, 'index'])->name('contacts.index');
    Route::post('request', [ContactsWebController::class, 'sendRequest'])->name('contacts.request.send');
    Route::get('requests', [ContactsWebController::class, 'requests'])->name('contacts.requests');
    Route::post('request/{contactRequest}/accept', [ContactsWebController::class, 'accept'])->name('contacts.request.accept');
    Route::post('request/{contactRequest}/reject', [ContactsWebController::class, 'reject'])->name('contacts.request.reject');
    Route::delete('request/{contactRequest}', [ContactsWebController::class, 'cancel'])->name('contacts.request.cancel');
    Route::get('blocked', [ContactsWebController::class, 'blocked'])->name('contacts.blocked');
    Route::delete('{contactId}', [ContactsWebController::class, 'destroy'])->name('contacts.destroy');
    Route::post('{contactId}/favorite', [ContactsWebController::class, 'favorite'])->name('contacts.favorite');
    Route::delete('{contactId}/favorite', [ContactsWebController::class, 'unfavorite'])->name('contacts.unfavorite');
});

Route::middleware('auth')->group(function () {
    Route::post('users/{userId}/block', [ContactsWebController::class, 'block'])->name('contacts.block');
    Route::delete('users/{userId}/block', [ContactsWebController::class, 'unblock'])->name('contacts.unblock');
});
