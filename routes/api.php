<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for('api-limit', function (Request $request) {
    return Limit::perMinute(5);
});

Route::get('/user-details', function (Request $request) {
    return response()->json([
        'message' => 'this is user details api',
    ]);
})->middleware('throttle:api-limit');
