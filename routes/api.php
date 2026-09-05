<?php

use App\Http\Controllers\Api\ImportController;
use Illuminate\Support\Facades\Route;

Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
