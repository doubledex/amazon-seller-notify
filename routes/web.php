<?php

use App\Http\Controllers\NotificationSubscriptions;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SqsMessagesController;
use App\Http\Controllers\AsinController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\AdsReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UsFcInventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportJobsController;
use App\Http\Controllers\ProductPricingController;

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');


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
    Route::get('/sqs-messages/{id}/report-download', [SqsMessagesController::class, 'downloadReportDocument'])->name('sqs_messages.report_download');
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
    Route::get('/reports/jobs', [ReportJobsController::class, 'index'])->name('reports.jobs');
    Route::post('/reports/jobs/poll-now', [ReportJobsController::class, 'pollNow'])->name('reports.jobs.poll');
    Route::get('/reports/jobs/{id}/download-csv', [ReportJobsController::class, 'downloadCsv'])->name('reports.jobs.download_csv');
    Route::get('/inventory/fc', [UsFcInventoryController::class, 'index'])->name('inventory.fc');
    Route::get('/inventory/fc/locations.csv', [UsFcInventoryController::class, 'downloadLocationsCsv'])->name('inventory.fc.locations.csv');
    Route::post('/inventory/fc/locations.csv', [UsFcInventoryController::class, 'uploadLocationsCsv'])->name('inventory.fc.locations.upload');
    Route::get('/inventory/us-fc', [UsFcInventoryController::class, 'index'])->name('inventory.us_fc');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/identifiers', [ProductController::class, 'storeIdentifier'])->name('products.identifiers.store');
    Route::patch('/products/identifiers/{identifier}', [ProductController::class, 'updateIdentifier'])->name('products.identifiers.update');
    Route::delete('/products/identifiers/{identifier}', [ProductController::class, 'destroyIdentifier'])->name('products.identifiers.destroy');


    Route::post('/products/{product}/identifiers/{identifier}/cost-layers', [ProductPricingController::class, 'storeCostLayer'])->name('products.identifiers.cost_layers.store');
    Route::patch('/products/{product}/identifiers/{identifier}/cost-layers/{costLayer}', [ProductPricingController::class, 'updateCostLayer'])->name('products.identifiers.cost_layers.update');
    Route::delete('/products/{product}/identifiers/{identifier}/cost-layers/{costLayer}', [ProductPricingController::class, 'destroyCostLayer'])->name('products.identifiers.cost_layers.destroy');
    Route::post('/products/{product}/identifiers/{identifier}/cost-layers/{costLayer}/components', [ProductPricingController::class, 'storeCostComponent'])->name('products.identifiers.cost_components.store');
    Route::patch('/products/{product}/identifiers/{identifier}/cost-layers/{costLayer}/components/{component}', [ProductPricingController::class, 'updateCostComponent'])->name('products.identifiers.cost_components.update');
    Route::delete('/products/{product}/identifiers/{identifier}/cost-layers/{costLayer}/components/{component}', [ProductPricingController::class, 'destroyCostComponent'])->name('products.identifiers.cost_components.destroy');
    Route::post('/products/{product}/identifiers/{identifier}/sale-prices', [ProductPricingController::class, 'storeSalePrice'])->name('products.identifiers.sale_prices.store');
    Route::patch('/products/{product}/identifiers/{identifier}/sale-prices/{salePrice}', [ProductPricingController::class, 'updateSalePrice'])->name('products.identifiers.sale_prices.update');
    Route::delete('/products/{product}/identifiers/{identifier}/sale-prices/{salePrice}', [ProductPricingController::class, 'destroySalePrice'])->name('products.identifiers.sale_prices.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
