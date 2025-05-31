<?php

use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReelController;

Route::get('/', function () {
    return Inertia::render('CreateReelForm');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

Route::post('/api/reels', [ReelController::class, 'store']);

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
