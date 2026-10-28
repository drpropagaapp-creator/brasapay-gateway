<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1) – API PIX (Gateway) / pagamentos / saques
|--------------------------------------------------------------------------
|
| Autenticação: X-Public-Key + X-Secret-Key (recomendado) ou legado Bearer/X-API-Key.
| Middleware resolve ApiApplication e anexa ao request.
|
*/

Route::middleware(['api.application', 'api.request-id', 'throttle:api', 'api.request-log'])->prefix('v1')->group(function () {
    Route::get('payments', [\App\Http\Controllers\Api\V1\PaymentStatusController::class, 'index'])
        ->middleware('api.scope:payments:read')
        ->name('api.v1.payments.index');

    Route::post('payments/pix', [\App\Http\Controllers\Api\V1\PaymentsController::class, 'createPix'])
        ->middleware('api.scope:payments:write')
        ->name('api.v1.payments.pix');
    Route::post('payments/card', [\App\Http\Controllers\Api\V1\PaymentsController::class, 'createCard'])
        ->middleware('api.scope:payments:write')
        ->name('api.v1.payments.card');
    Route::post('payments/boleto', [\App\Http\Controllers\Api\V1\PaymentsController::class, 'createBoleto'])
        ->middleware('api.scope:payments:write')
        ->name('api.v1.payments.boleto');

    Route::get('payments/{order}', [\App\Http\Controllers\Api\V1\PaymentStatusController::class, 'show'])
        ->middleware('api.scope:payments:read')
        ->name('api.v1.payments.show');

    Route::post('pix/{order}/cancel', [\App\Http\Controllers\Api\V1\PixController::class, 'cancel'])
        ->middleware('api.scope:payments:refund')
        ->name('api.v1.pix.cancel');
    Route::post('pix/{order}/refund', [\App\Http\Controllers\Api\V1\PixController::class, 'refund'])
        ->middleware('api.scope:payments:refund')
        ->name('api.v1.pix.refund');

    Route::get('balance', [\App\Http\Controllers\Api\V1\BalanceController::class, 'show'])
        ->middleware('api.scope:withdrawals:read')
        ->name('api.v1.balance.show');

    Route::get('withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalsController::class, 'index'])
        ->middleware('api.scope:withdrawals:read')
        ->name('api.v1.withdrawals.index');
    Route::post('withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalsController::class, 'store'])
        ->middleware(['api.scope:withdrawals:write', 'throttle:api-withdrawals'])
        ->name('api.v1.withdrawals.store');
    Route::get('withdrawals/{withdrawal}', [\App\Http\Controllers\Api\V1\WithdrawalsController::class, 'show'])
        ->middleware('api.scope:withdrawals:read')
        ->name('api.v1.withdrawals.show');

    Route::put('payout-destination', [\App\Http\Controllers\Api\V1\PayoutDestinationController::class, 'update'])
        ->middleware('api.scope:withdrawals:write')
        ->name('api.v1.payout-destination.update');

    Route::get('webhook', [\App\Http\Controllers\Api\V1\WebhookConfigController::class, 'show'])
        ->middleware('api.scope:webhooks:read')
        ->name('api.v1.webhook.show');
    Route::put('webhook', [\App\Http\Controllers\Api\V1\WebhookConfigController::class, 'update'])
        ->middleware('api.scope:webhooks:write')
        ->name('api.v1.webhook.update');
    Route::post('webhook/rotate-secret', [\App\Http\Controllers\Api\V1\WebhookConfigController::class, 'rotateSecret'])
        ->middleware('api.scope:webhooks:write')
        ->name('api.v1.webhook.rotate-secret');

    Route::post('webhooks/deliveries/{deliveryId}/retry', [\App\Http\Controllers\Api\V1\WebhookDeliveriesController::class, 'retry'])
        ->middleware('api.scope:payments:read')
        ->name('api.v1.webhooks.deliveries.retry');
});

/*
| Sandbox (Brasa Developers) – só chaves de sandbox (gpk_test_/gsk_test_). Mesmos formatos de resposta
| e de webhook da API real, sem pedido, adquirente, carteira ou saque real. Webhooks com "livemode": false.
*/
Route::middleware(['api.application:sandbox', 'api.request-id', 'throttle:api', 'api.request-log'])->prefix('sandbox/v1')->group(function () {
    $c = \App\Http\Controllers\Api\Sandbox\SandboxController::class;
    Route::get('payments', [$c, 'index'])->middleware('api.scope:payments:read')->name('api.sandbox.payments.index');
    Route::post('payments/pix', [$c, 'createPix'])->middleware('api.scope:payments:write')->name('api.sandbox.payments.pix');
    Route::post('payments/card', [$c, 'createCard'])->middleware('api.scope:payments:write')->name('api.sandbox.payments.card');
    Route::post('payments/boleto', [$c, 'createBoleto'])->middleware('api.scope:payments:write')->name('api.sandbox.payments.boleto');
    Route::get('payments/{id}', [$c, 'show'])->middleware('api.scope:payments:read')->whereUuid('id')->name('api.sandbox.payments.show');
    Route::post('simulate/payments/{id}/{action}', [$c, 'simulatePayment'])->middleware('api.scope:payments:write')
        ->whereUuid('id')->whereIn('action', ['approve', 'expire', 'refund'])->name('api.sandbox.simulate.payment');
    Route::get('balance', [$c, 'balance'])->middleware('api.scope:withdrawals:read')->name('api.sandbox.balance');
    Route::post('withdrawals', [$c, 'createWithdrawal'])->middleware('api.scope:withdrawals:write')->name('api.sandbox.withdrawals.store');
    Route::get('withdrawals/{id}', [$c, 'showWithdrawal'])->middleware('api.scope:withdrawals:read')->whereUuid('id')->name('api.sandbox.withdrawals.show');
    Route::post('simulate/withdrawals/{id}/{action}', [$c, 'simulateWithdrawal'])->middleware('api.scope:withdrawals:write')
        ->whereUuid('id')->whereIn('action', ['process', 'pay', 'fail'])->name('api.sandbox.simulate.withdrawal');
    Route::post('webhooks/test', [$c, 'testWebhook'])->middleware('api.scope:webhooks:write')->name('api.sandbox.webhooks.test');
});
