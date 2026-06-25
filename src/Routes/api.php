<?php

use Illuminate\Support\Facades\Route;
use VEximweb\Plugin\RSpamd\Core\Http\Controllers\RspamdCheckController;
use VEximweb\Plugin\RSpamd\Core\Http\Controllers\RspamdUserSettingsController;
use VEximweb\Plugin\RSpamd\Core\Http\Controllers\RspamdMetadataController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        
        Route::prefix('rspamd')->group(function () {
            // Check endpoint
            Route::post('/check', [RspamdCheckController::class, 'check'])
                ->middleware('throttle:100,1');
            
            // Get all user settings
            Route::get('/settings', [RspamdUserSettingsController::class, 'getAllSettings'])
                ->name('rspamd.settings.all');

            // Get single user settings
            Route::get('/settings/{email}', [RspamdUserSettingsController::class, 'getUserSettings'])
                ->name('rspamd.settings.user');

            // Admin endpoint to clear cache
            Route::post('/cache/clear', [RspamdUserSettingsController::class, 'clearCache'])
                ->name('rspamd.cache.clear');
            
            // Metadata import
            Route::post('/metadata', [RspamdMetadataController::class, 'import'])
                ->name('rspamd.metadata.import');
        });
    });