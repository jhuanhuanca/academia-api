<?php

use App\Http\Controllers\PaymentConfirmationWebController;
use App\Http\Controllers\RegistrationApprovalWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/confirmar-pago/{payment}', PaymentConfirmationWebController::class)
    ->name('payments.confirm');

Route::get('/aprobar-registro/{user}', RegistrationApprovalWebController::class)
    ->name('registration.approve');
