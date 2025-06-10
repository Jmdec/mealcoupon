<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\NotificationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Remove the global OPTIONS handler since middleware handles it
// Route::options('{any}', function () {...})->where('any', '.*');

// Analytics routes
Route::get('/analytics', [AnalyticsController::class, 'getAnalytics']);
Route::get('/analytics/export', [AnalyticsController::class, 'exportAnalytics']);
Route::get('/analytics/live', [AnalyticsController::class, 'getLiveAnalytics']);
Route::get('/analytics/departments', [AnalyticsController::class, 'getDepartmentAnalytics']);
Route::get('/analytics/departments-dynamic', [AnalyticsController::class, 'getDynamicDepartmentAnalytics']);
Route::get('/analytics/usage-alerts', [AnalyticsController::class, 'getUsageAlerts']);

// Employee routes
Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::get('/employees/{id}', [EmployeeController::class, 'show']);
Route::put('/employees/{id}', [EmployeeController::class, 'update']);
Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);

// Employee count
Route::get('/employee-count', function () {
    $count = \App\Models\Employee::count();
    return response()->json(['totalEmployees' => $count]);
});

// Top performing employees
Route::get('/top-performing-employees', [CouponController::class, 'getTopPerformingEmployees']);

// Coupon routes
Route::get('/coupons', [CouponController::class, 'getCoupons']);
Route::get('/coupons/stats', [CouponController::class, 'getEmployeeCouponCount']);
Route::get('/coupons/claimed-stats', [CouponController::class, 'getClaimedCouponsCount']);
Route::get('/coupons/statistics', [CouponController::class, 'getStatistics']);
Route::get('/coupons/expiring-soon', [CouponController::class, 'getExpiringSoon']);
Route::post('/coupons/generate', [CouponController::class, 'generateCoupons']);
Route::post('/coupons/generate-all', [CouponController::class, 'generateCouponsForAll']);
Route::get('/coupons/scan/{barcode}', [CouponController::class, 'scanCoupon']);
Route::post('/coupons/{id}/claim', [CouponController::class, 'claimCoupon']);

// Notification routes
Route::get('/notifications', [NotificationController::class, 'index']);
Route::get('/notifications/stream', [NotificationController::class, 'stream']);
Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
Route::post('/notifications/generate', [NotificationController::class, 'generate']);

// Test route
Route::get('/test', function () {
    return response()->json([
        'message' => 'API is working!',
        'timestamp' => now(),
        'cors' => 'enabled'
    ]);
});