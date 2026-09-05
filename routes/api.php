<?php

use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');

Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');

Route::post('offers/{offer}/reservations', [ReservationController::class, 'store'])
    ->name('offers.reservations.store');
