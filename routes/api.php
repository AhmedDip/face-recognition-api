<?php

use App\Http\Controllers\Api\FaceApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/recognize',         [FaceApiController::class, 'recognize'])->name('api.recognize');
    Route::post('/people',            [FaceApiController::class, 'store'])->name('api.people.store');
    Route::get('/people',             [FaceApiController::class, 'index'])->name('api.people.index');
    Route::delete('/people/{person}', [FaceApiController::class, 'destroy'])->name('api.people.destroy');
});
