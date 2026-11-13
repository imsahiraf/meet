<?php

use Illuminate\Support\Facades\Route;
use Modules\Mailbox\Http\Controllers\MailController as M;
use Modules\Mailbox\Http\Controllers\MailSettingsController as S;

Route::middleware('auth')->prefix('mail')->name('mail.')->group(function () {
    Route::get('/', [M::class, 'index'])->name('index');
    Route::get('m/{id}', [M::class, 'show'])->name('show')->whereNumber('id');
    Route::post('m/{id}/do', [M::class, 'act'])->name('act')->whereNumber('id');
    Route::post('m/{id}/rsvp', [M::class, 'rsvp'])->name('rsvp')->whereNumber('id')->middleware('throttle:20,1');
    Route::post('bulk', [M::class, 'bulk'])->name('bulk');
    Route::post('trash/empty', [M::class, 'emptyTrash'])->name('trash.empty');
    Route::get('attachment/{id}', [M::class, 'attachment'])->name('attachment')->whereNumber('id');
    Route::get('compose', [M::class, 'compose'])->name('compose');
    Route::post('send', [M::class, 'send'])->name('send')->middleware('throttle:30,1');
    Route::post('write-help', [M::class, 'writeHelp'])->name('write-help')->middleware('throttle:20,1');
    Route::get('contacts', [M::class, 'contacts'])->name('contacts');

    Route::get('settings', [S::class, 'index'])->name('settings');
    Route::post('settings', [S::class, 'save'])->name('settings.save');
    Route::post('settings/app-passwords', function (\Illuminate\Http\Request $r) {
        $d = $r->validate(['name' => 'required|string|max:80']);
        [, $plain] = \Modules\Mailbox\Models\MailAppPassword::make((int) auth()->id(), $d['name']);

        return redirect(route('mail.settings') . '#phones')->with('app_password', $plain);
    })->middleware('throttle:10,1')->name('settings.app_passwords.add');
    Route::post('settings/app-passwords/{id}/remove', function (int $id) {
        \Modules\Mailbox\Models\MailAppPassword::where('user_id', auth()->id())->whereKey($id)->delete();

        return redirect(route('mail.settings') . '#phones');
    })->whereNumber('id')->name('settings.app_passwords.remove');
    Route::post('settings/server', [S::class, 'serverSetup'])->name('settings.server')->middleware('throttle:6,1');
    Route::post('settings/address', [S::class, 'createAddress'])->name('settings.address');
    Route::post('settings/accounts', [S::class, 'addAccount'])->name('settings.accounts.add')->middleware('throttle:10,1');
    Route::post('settings/accounts/{id}/sync', [S::class, 'syncAccount'])->name('settings.accounts.sync')->whereNumber('id')->middleware('throttle:10,1');
    Route::post('settings/insights-mailboxes', [S::class, 'insightsConnect'])->name('settings.insights')->middleware('throttle:10,1');
    Route::post('settings/accounts/{id}/default', [S::class, 'defaultAccount'])->name('settings.accounts.default')->whereNumber('id');
    Route::delete('settings/accounts/{id}', [S::class, 'removeAccount'])->name('settings.accounts.remove')->whereNumber('id');
    Route::post('settings/filters', [S::class, 'addFilter'])->name('settings.filters.add');
    Route::delete('settings/filters/{id}', [S::class, 'removeFilter'])->name('settings.filters.remove')->whereNumber('id');
    Route::post('settings/delegates', [S::class, 'addDelegate'])->name('settings.delegates.add')->middleware('throttle:10,1');
    Route::delete('settings/delegates/{id}', [S::class, 'removeDelegate'])->name('settings.delegates.remove')->whereNumber('id');
    Route::post('settings/groups', [S::class, 'addGroup'])->name('settings.groups.add');
    Route::delete('settings/groups/{id}', [S::class, 'removeGroup'])->name('settings.groups.remove')->whereNumber('id');
    Route::post('settings/labels', [S::class, 'addLabel'])->name('settings.labels.add');
    Route::delete('settings/labels/{id}', [S::class, 'removeLabel'])->name('settings.labels.remove')->whereNumber('id');

    Route::get('google/connect', [S::class, 'googleConnect'])->name('google.connect');
    Route::get('google/callback', [S::class, 'googleCallback'])->name('google.callback');
    Route::post('google/sync', [S::class, 'googleSync'])->name('google.sync')->middleware('throttle:6,1');
    Route::post('google/disconnect', [S::class, 'googleDisconnect'])->name('google.disconnect');
});
