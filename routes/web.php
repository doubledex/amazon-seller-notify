<?php

use App\Http\Controllers\NotificationSubscriptions;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SqsMessagesController;
use App\Http\Controllers\AsinController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\AdsReportController;

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::middleware('auth')->group(function () {
    Route::get('/notifications', [NotificationSubscriptions::class, 'index'])->name('notifications.index');
    Route::post('/notifications', [NotificationSubscriptions::class, 'store'])->name('notifications.store');
    Route::post('/notifications/delete-destination', [NotificationSubscriptions::class, 'deleteDestination'])->name('notifications.deleteDestination');
    Route::get('/notifications/destinations', [NotificationSubscriptions::class, 'destinations'])->name('notifications.destinations');
    Route::post('/notifications/destinations', [NotificationSubscriptions::class, 'storeDestination'])->name('notifications.destinations-store');
    Route::delete('/notifications/destinations/{destinationId}', [NotificationSubscriptions::class, 'destroyDestination'])->name('notifications.destinations-destroy');
    Route::post('/notifications/delete-subscription', [NotificationSubscriptions::class, 'deleteSubscription'])->name('notifications.deleteSubscription');
    Route::post('/notifications/create-subscription', [NotificationSubscriptions::class, 'createSubscription'])->name('notifications.createSubscription');
    


    Route::get('/sqs-messages', [SqsMessagesController::class, 'index'])->name('sqs_messages.index');
    Route::get('/sqs-messages/{id}', [SqsMessagesController::class, 'show'])->name('sqs_messages.show');
    Route::post('/sqs-messages/{id}/flag', [SqsMessagesController::class, 'flag'])->name('sqs_messages.flag');
    Route::post('/sqs-messages/fetch-latest', [SqsMessagesController::class, 'fetchLatest'])->name('sqs_messages.fetch');
    Route::delete('/sqs-messages/{id}', [SqsMessagesController::class, 'destroy'])->name('sqs_messages.destroy');

    Route::get('/orders/map-data', [OrderController::class, 'mapData'])->name('orders.mapData');
    Route::post('/orders/sync-now', [OrderController::class, 'syncNow'])->name('orders.syncNow');
    Route::post('/orders/sync-older', [OrderController::class, 'syncOlder'])->name('orders.syncOlder');
    Route::get('/orders/{order_id}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/marketplaces', [OrderController::class, 'marketplaces'])->name('marketplaces');
    Route::get('/asins/europe', [AsinController::class, 'index'])->name('asins.europe');
    Route::get('/metrics/daily', [MetricsController::class, 'index'])->name('metrics.index');
    Route::get('/ads/reports', [AdsReportController::class, 'index'])->name('ads.reports');
    Route::post('/ads/reports/poll-now', [AdsReportController::class, 'pollNow'])->name('ads.reports.poll');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
