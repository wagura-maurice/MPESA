<?php

use Illuminate\Support\Facades\Route;
use Montanabay39\Mpesa\Http\Controllers\LNMO_Controller;
use Montanabay39\Mpesa\Http\Controllers\C2B_Controller;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// all callback route's initialize from safaricom servers and should be secured with the right ssl to work.

Route::group(['middleware' => 'api', 'prefix' => 'api/vendor/mpesa/'], function () {
    // MPESA LNMO ROUTES
    Route::post('lmno', [LNMO_Controller::class, 'transaction'])->name('mpesa.lnmo');
    Route::post('lmno/callback', [LNMO_Controller::class, 'callback'])->name('mpesa.lnmo.callback');
    Route::post('lmno/query', [LNMO_Controller::class, 'query'])->name('mpesa.lnmo.query');

    // MPESA C2B ROUTES
    Route::post('C2B', [C2B_Controller::class, 'transaction'])->name('mpesa.c2b.transaction');
    Route::post('C2B/validation/callback', [C2B_Controller::class, 'validation'])->name('mpesa.c2b.validation.callback');
    Route::post('C2B/confirmation/callback', [C2B_Controller::class, 'confirmation'])->name('mpesa.c2b.confirmation.callback');
    Route::post('C2B/status', [C2B_Controller::class, 'status'])->name('mpesa.c2b.status');
    Route::post('C2B/status/callback', [C2B_Controller::class, 'statusCallback'])->name('mpesa.c2b.status.callback');
    Route::post('C2B/reverse', [C2B_Controller::class, 'reverseTransaction'])->name('mpesa.c2b.reverse.transaction');
    Route::post('C2B/reverse/callback', [C2B_Controller::class, 'reverseTransactionCallback'])->name('mpesa.c2b.reverse.transaction.callback');
    Route::get('C2B/balance', [C2B_Controller::class, 'balance'])->name('mpesa.c2b.balance');
    Route::post('C2B/balance/callback', [C2B_Controller::class, 'balanceCallback'])->name('mpesa.c2b.balance.callback');
    // use/hit only once.
    Route::get('C2B/register', [C2B_Controller::class, 'register'])->name('mpesa.c2b.register');

    // MPESA B2C ROUTES
    /* Route::post('B2C/transaction', [B2C_Controller::class, 'transaction'])->name('b2c.transaction');
    Route::post('B2C/callback', [B2C_Controller::class, 'callback'])->name('b2c.callback');
    Route::get('B2C/balance', [B2C_Controller::class, 'balance'])->name('b2c.balance');
    Route::post('B2C/balance/callback', [B2C_Controller::class, 'balanceCallback'])->name('b2c.balance.callback');
    Route::post('B2C/status', [B2C_Controller::class, 'status'])->name('b2c.status');
    Route::post('B2C/status/callback', [B2C_Controller::class, 'statusCallback'])->name('b2c.status.callback');
    Route::post('B2C/reverse/transaction', [B2C_Controller::class, 'reverseTransaction'])->name('b2c.reverse.transaction');
    Route::post('B2C/reverse/transaction/callback', [B2C_Controller::class, 'reverseTransactionCallback'])->name('b2c.reverse.transaction.callback'); */

    /*-------------------
     * 
     * Api resources sits here.
     */
    Route::apiResources([
        // 'lmno' => LNMO_Controller::class
    ]);
});
