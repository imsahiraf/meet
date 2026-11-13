<?php

use Illuminate\Support\Facades\Route;
use Modules\Mailbox\Http\Controllers\InsightsMailApiController as A;

// Meet Insights Mail Hub: the person's Meet address (signed bearer token, see the controller)
Route::prefix('api/insights/mail')->middleware('throttle:120,1')->group(function () {
    Route::get('account', [A::class, 'show']);
    Route::get('accounts', [A::class, 'accounts']); // the Meet address + accounts added in Meet (Insights shows them too)
    Route::post('address', [A::class, 'address'])->middleware('throttle:10,1');
    Route::get('messages', [A::class, 'messages']);
    Route::post('send', [A::class, 'send'])->middleware('throttle:30,1');
});
