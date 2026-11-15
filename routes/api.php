<?php

use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'ability:chat', 'throttle:chat'])->group(function () {
    Route::post('/chat-ai', ChatController::class);
});
