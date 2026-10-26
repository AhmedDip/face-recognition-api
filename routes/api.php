<?php

use App\Http\Controllers\Api\FaceApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/recognize',                     [FaceApiController::class, 'recognize'])->name('api.recognize');
    Route::post('/register',                      [FaceApiController::class, 'enroll'])->name('api.people.enroll');
    Route::get('/people',                         [FaceApiController::class, 'index'])->name('api.people.index');
    Route::delete('/people/{staffId}/descriptor', [FaceApiController::class, 'clearDescriptor'])->name('api.people.clear');
});
