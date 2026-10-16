<?php

declare(strict_types=1);

use App\Payroll\Domain\PayrollIdValidator;
use App\Payroll\Http\Controllers\ApplyAdjustmentController;
use App\Payroll\Http\Controllers\CreateController;
use App\Payroll\Http\Controllers\GetAdjustmentHistoryController;
use App\Payroll\Http\Controllers\GetAmountController;
use App\Payroll\Http\Controllers\ListController;
use App\Payroll\Http\Controllers\RecalculateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/payroll-lines')->name('api.v1.payroll-lines.')
    ->where(['id' => PayrollIdValidator::PATTERN])->group(function (): void {
        Route::post('/', CreateController::class)->name('store');
        Route::get('/', ListController::class)->name('index');
        Route::get('/{id}', GetAmountController::class)->name('show');
        Route::post('/{id}/recalculations', RecalculateController::class)->name('recalculate');
        Route::post('/{id}/adjustments', ApplyAdjustmentController::class)->name('adjust');
        Route::get('/{id}/adjustments', GetAdjustmentHistoryController::class)->name('history');
    });
