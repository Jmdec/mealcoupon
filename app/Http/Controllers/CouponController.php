<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Employee;
use App\Services\BarcodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CouponController extends Controller
{
    protected $barcodeService;

    public function __construct(BarcodeService $barcodeService)
    {
        $this->barcodeService = $barcodeService;
    }

    /**
     * Add CORS headers to response
     */
    private function addCorsHeaders($response)
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
    }

    /**
     * Generate coupons for a specific employee
     */
    public function generateCoupons(Request $request): JsonResponse
    {
        try {
            Log::info('Coupon generation request received', [
                'data' => $request->all(),
                'method' => $request->method(),
                'url' => $request->fullUrl()
            ]);

            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:employees,id',
                'month' => 'required|integer|min:1|max:12',
                'year' => 'required|integer|min:2024|max:2030',
            ]);

            if ($validator->fails()) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422));
            }

            $employeeId = $request->employee_id;
            $month = $request->month;
            $year = $request->year;

            // Check if employee exists
            $employee = Employee::find($employeeId);
            if (!$employee) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Employee not found'
                ], 404));
            }

            // Check if coupons already exist for this month
            $existingCount = Coupon::where('employee_id', $employeeId)
                ->whereMonth('coupon_date', $month)
                ->whereYear('coupon_date', $year)
                ->count();

            if ($existingCount > 0) {
                return $this->addCorsHeaders(response()->json([
                    'message' => "Coupons already exist for {$employee->first_name} {$employee->last_name} for this month",
                    'existing_count' => $existingCount
                ], 409));
            }

            // Generate weekdays for the month
            $weekdays = $this->getWeekdaysForMonth($month, $year);

            DB::beginTransaction();
            try {
                $generatedCoupons = [];
                
                foreach ($weekdays as $date) {
                    // Generate barcode text
                    $barcodeText = Coupon::generateBarcode();
                    
                    // Generate barcode images
                    $barcodeFormats = $this->barcodeService->generateBarcodeFormats($barcodeText);
                    
                    // Create coupon with barcode data
                    $coupon = Coupon::create([
                        'employee_id' => $employeeId,
                        'coupon_date' => $date,
                        'barcode' => $barcodeText,
                        'barcode_image_path' => $barcodeFormats['png_path'],
                        'barcode_svg_path' => $barcodeFormats['svg_path'],
                        'barcode_base64' => $barcodeFormats['base64'],
                        'workday_code' => Coupon::generateWorkdayCode($employeeId, $date),
                        'is_claimed' => false,
                    ]);

                    $generatedCoupons[] = $coupon;
                }

                DB::commit();

                Log::info('Coupons generated successfully with barcodes', [
                    'employee_id' => $employeeId,
                    'count' => count($generatedCoupons),
                    'month' => $month,
                    'year' => $year
                ]);

                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupons generated successfully with barcodes',
                    'count' => count($generatedCoupons),
                    'employee' => $employee->first_name . ' ' . $employee->last_name,
                    'month' => $month,
                    'year' => $year,
                    'sample_coupon' => $generatedCoupons[0] ?? null // Include first coupon as sample
                ]));

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Error generating coupons', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to generate coupons',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Generate coupons for all employees
     */
    public function generateCouponsForAll(Request $request): JsonResponse
    {
        try {
            Log::info('Generate all coupons request received', $request->all());

            $validator = Validator::make($request->all(), [
                'month' => 'required|integer|min:1|max:12',
                'year' => 'required|integer|min:2024|max:2030',
            ]);

            if ($validator->fails()) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422));
            }

            $month = $request->month;
            $year = $request->year;

            $employees = Employee::all();
            
            if ($employees->isEmpty()) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'No employees found'
                ], 404));
            }

            $weekdays = $this->getWeekdaysForMonth($month, $year);
            $totalCoupons = 0;
            $processedEmployees = 0;
            $skippedEmployees = 0;

            DB::beginTransaction();
            try {
                foreach ($employees as $employee) {
                    // Check if coupons already exist for this employee and month
                    $existingCount = Coupon::where('employee_id', $employee->id)
                        ->whereMonth('coupon_date', $month)
                        ->whereYear('coupon_date', $year)
                        ->count();

                    if ($existingCount > 0) {
                        $skippedEmployees++;
                        continue;
                    }

                    foreach ($weekdays as $date) {
                        // Generate barcode text
                        $barcodeText = Coupon::generateBarcode();
                        
                        // Generate barcode images
                        $barcodeFormats = $this->barcodeService->generateBarcodeFormats($barcodeText);
                        
                        // Create coupon with barcode data
                        Coupon::create([
                            'employee_id' => $employee->id,
                            'coupon_date' => $date,
                            'barcode' => $barcodeText,
                            'barcode_image_path' => $barcodeFormats['png_path'],
                            'barcode_svg_path' => $barcodeFormats['svg_path'],
                            'barcode_base64' => $barcodeFormats['base64'],
                            'workday_code' => Coupon::generateWorkdayCode($employee->id, $date),
                            'is_claimed' => false,
                        ]);

                        $totalCoupons++;
                    }

                    $processedEmployees++;
                }

                DB::commit();

                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupons generated successfully for all employees with barcodes',
                    'total_coupons' => $totalCoupons,
                    'processed_employees' => $processedEmployees,
                    'skipped_employees' => $skippedEmployees,
                    'month' => $month,
                    'year' => $year
                ]));

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Error generating coupons for all employees', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to generate coupons',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Get barcode image
     */
    public function getBarcodeImage($id): JsonResponse
    {
        try {
            $coupon = Coupon::find($id);

            if (!$coupon) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupon not found'
                ], 404));
            }

            // If barcode images don't exist, generate them
            if (!$coupon->barcode_image_path) {
                $coupon->generateBarcodeImages();
                $coupon->refresh();
            }

            return $this->addCorsHeaders(response()->json([
                'barcode_text' => $coupon->barcode,
                'barcode_image_url' => $coupon->barcode_image_url,
                'barcode_svg_url' => $coupon->barcode_svg_url,
                'barcode_base64' => $coupon->barcode_base64
            ]));

        } catch (\Exception $e) {
            Log::error('Error getting barcode image', [
                'coupon_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to get barcode image',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Scan coupon by barcode
     */
    public function scanCoupon($barcode): JsonResponse
    {
        try {
            $coupon = Coupon::with('employee')->where('barcode', $barcode)->first();

            if (!$coupon) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupon not found'
                ], 404));
            }

            return $this->addCorsHeaders(response()->json($coupon));

        } catch (\Exception $e) {
            Log::error('Error scanning coupon', [
                'barcode' => $barcode,
                'error' => $e->getMessage()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to scan coupon',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Claim a coupon
     */
    public function claimCoupon($id): JsonResponse
    {
        try {
            $coupon = Coupon::with('employee')->find($id);

            if (!$coupon) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupon not found'
                ], 404));
            }

            // Validate claim rules
            if ($coupon->is_claimed) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupon has already been claimed'
                ], 409));
            }

            if ($coupon->coupon_date < Carbon::today()) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Coupon has expired and cannot be claimed'
                ], 409));
            }

            // Claim the coupon
            $coupon->update([
                'is_claimed' => true,
                'claimed_at' => now(),
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Coupon claimed successfully',
                'coupon' => $coupon->fresh()
            ]));

        } catch (\Exception $e) {
            Log::error('Error claiming coupon', [
                'coupon_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to claim coupon',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Get coupons with filters
     */
    public function getCoupons(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:employees,id',
                'month' => 'required|integer|min:1|max:12',
                'year' => 'required|integer|min:2024|max:2030',
            ]);

            if ($validator->fails()) {
                return $this->addCorsHeaders(response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422));
            }

            $employeeId = $request->employee_id;
            $month = $request->month;
            $year = $request->year;

            // Get coupons
            $coupons = Coupon::with('employee')
                ->where('employee_id', $employeeId)
                ->whereMonth('coupon_date', $month)
                ->whereYear('coupon_date', $year)
                ->orderBy('coupon_date')
                ->get();

            // Calculate statistics
            $today = Carbon::today();
            $stats = [
                'total' => $coupons->count(),
                'claimed' => $coupons->where('is_claimed', true)->count(),
                'expired' => $coupons->where('is_claimed', false)
                                   ->where('coupon_date', '<', $today)
                                   ->count(),
                'available' => $coupons->where('is_claimed', false)
                                     ->where('coupon_date', '>=', $today)
                                     ->count(),
            ];

            return $this->addCorsHeaders(response()->json([
                'coupons' => $coupons,
                'stats' => $stats
            ]));

        } catch (\Exception $e) {
            Log::error('Error getting coupons', [
                'request' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to get coupons',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Get coupon statistics for dashboard
     */
    public function getStatistics(): JsonResponse
    {
        try {
            $today = Carbon::today();
            $currentMonth = $today->month;
            $currentYear = $today->year;

            $stats = [
                'total_coupons' => Coupon::count(),
                'claimed_today' => Coupon::whereDate('claimed_at', $today)->count(),
                'claimed_this_month' => Coupon::where('is_claimed', true)
                    ->whereMonth('coupon_date', $currentMonth)
                    ->whereYear('coupon_date', $currentYear)
                    ->count(),
                'expired_coupons' => Coupon::where('is_claimed', false)
                    ->where('coupon_date', '<', $today)
                    ->count(),
                'available_coupons' => Coupon::where('is_claimed', false)
                    ->where('coupon_date', '>=', $today)
                    ->count(),
            ];

            // Recent claims
            $recentClaims = Coupon::with('employee')
                ->where('is_claimed', true)
                ->orderBy('claimed_at', 'desc')
                ->limit(10)
                ->get();

            return $this->addCorsHeaders(response()->json([
                'stats' => $stats,
                'recent_claims' => $recentClaims
            ]));

        } catch (\Exception $e) {
            Log::error('Error getting statistics', [
                'error' => $e->getMessage()
            ]);

            return $this->addCorsHeaders(response()->json([
                'message' => 'Failed to get statistics',
                'error' => $e->getMessage()
            ], 500));
        }
    }

    /**
     * Get weekdays for a specific month (excluding weekends)
     */
   private function getWeekdaysForMonth($month, $year)
{
    // Use Carbon to help parse and compare dates properly
    $date = Carbon::createFromDate($year, $month, 1);
    $end = $date->copy()->endOfMonth();
    
    // Philippine holidays (both fixed and sample movable)
    $holidays = [
        "$year-01-01", // New Year’s Day
        "$year-04-09", // Araw ng Kagitingan
        "$year-05-01", // Labor Day
        "$year-06-12", // Independence Day
        "$year-08-21", // Ninoy Aquino Day
        "$year-08-26", // National Heroes Day (last Monday of August - sample)
        "$year-11-01", // All Saints’ Day
        "$year-11-02", // All Souls’ Day
        "$year-11-30", // Bonifacio Day
        "$year-12-08", // Immaculate Conception
        "$year-12-25", // Christmas Day
        "$year-12-30", // Rizal Day
        "$year-12-31", // New Year’s Eve
        // Moveable holidays (for 2025 adjust accordingly)
        "$year-04-17", // Maundy Thursday (sample for 2025)
        "$year-04-18", // Good Friday (sample for 2025)
    ];

    $weekdays = [];

    while ($date->lte($end)) {
        $formatted = $date->format('Y-m-d');

        // Check if it's weekday AND not a holiday
        if ($date->isWeekday() && !in_array($formatted, $holidays)) {
            $weekdays[] = $formatted; // ← Only non-holiday weekdays allowed
        }

        $date->addDay();
    }

    return $weekdays;
}

   public function getEmployeeCouponCount()
{
    $totalEmployees = Employee::count();
    $totalCoupons = Coupon::count();
    $totalClaimedCoupons = Coupon::where('is_claimed', true)->count();  // Assuming 'claimed' boolean field

    return response()->json([
        'totalEmployees' => $totalEmployees,
        'totalCoupons' => $totalCoupons,
        'totalClaimedCoupons' => $totalClaimedCoupons,
    ]);
}

public function getTotalClaimedCoupons(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2024|max:2030',
        ]);

        if ($validator->fails()) {
            return $this->addCorsHeaders(response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422));
        }

        $employeeId = $request->employee_id;
        $month = $request->month;
        $year = $request->year;

        $query = Coupon::where('employee_id', $employeeId)
            ->where('is_claimed', true);

        if ($month) {
            $query->whereMonth('coupon_date', $month);
        }

        if ($year) {
            $query->whereYear('coupon_date', $year);
        }

        $totalClaimed = $query->count();

        return $this->addCorsHeaders(response()->json([
            'employee_id' => $employeeId,
            'total_claimed_coupons' => $totalClaimed,
            'month' => $month,
            'year' => $year
        ]));

    } catch (\Exception $e) {
        Log::error('Error getting total claimed coupons', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->addCorsHeaders(response()->json([
            'message' => 'Failed to get total claimed coupons',
            'error' => $e->getMessage()
        ], 500));
    }
}
public function getClaimedCouponsCount()
{
    $totalClaimedCoupons = Coupon::where('claimed', true)->count();

    return response()->json([
        'totalClaimedCoupons' => $totalClaimedCoupons,
    ]);
}
// public function getTopPerformingEmployees()
// {
//     $topEmployees = DB::table('coupons')
//     ->select(
//         'employees.id as employee_id',
//         'employees.first_name',
//         'employees.last_name',
//         'employees.department',
//         'employees.email',
//         DB::raw('COUNT(coupons.id) as total_coupons'),
//         DB::raw('SUM(CASE WHEN coupons.is_claimed = 1 THEN 1 ELSE 0 END) as total_claimed'),
//         DB::raw('SUM(CASE WHEN coupons.is_claimed = 0 THEN 1 ELSE 0 END) as total_unclaimed'),
//         DB::raw('MAX(coupons.claimed_at) as last_claimed')
//     )
//     ->join('employees', 'coupons.employee_id', '=', 'employees.id')
//     ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.department', 'employees.email')
//     ->orderByDesc('total_claimed')
//     ->limit(10)
//     ->get()
//     ->map(function ($item) {
//     $item->last_claimed = $item->last_claimed
//     ? \Carbon\Carbon::parse($item->last_claimed)
//         ->setTimezone('Asia/Manila')
//         ->toIso8601String() // e.g. 2025-06-05T13:32:00+08:00
//     : null;


//     return $item;
// });


// return response()->json($topEmployees);

// }
public function getTopPerformingEmployees(Request $request)
    {
        try {
            $live = $request->get('live', 'false') === 'true';
            
            $employees = DB::table('employees as e')
                ->leftJoin('coupons as c', 'e.id', '=', 'c.employee_id')
                ->select(
                    'e.id as employee_id',
                    'e.first_name',
                    'e.last_name',
                    'e.department',
                    'e.email',
                    DB::raw('COUNT(c.id) as total_coupons'),
                    DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as total_claimed'),
                    DB::raw('SUM(CASE WHEN c.is_claimed = 0 THEN 1 ELSE 0 END) as total_unclaimed'),
                    DB::raw('MAX(CASE WHEN c.is_claimed = 1 THEN c.updated_at ELSE NULL END) as last_claimed')
                )
                ->groupBy('e.id', 'e.first_name', 'e.last_name', 'e.department', 'e.email')
                ->orderBy('total_claimed', 'desc')
                ->get();

            $response = response()->json($employees);
            
            if ($live) {
                $response->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                        ->header('Pragma', 'no-cache')
                        ->header('Expires', '0');
            }

            return $response;
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
public function getExpiringSoon(Request $request)
    {
        try {
            $expiringSoon = DB::table('coupons')
                ->where('is_claimed', 0)
                ->where('coupon_date', '<=', Carbon::now()->addDay()->toDateString())
                ->where('coupon_date', '>', Carbon::now()->toDateString())
                ->get();

            return response()->json([
                'expiring_count' => $expiringSoon->count(),
                'coupons' => $expiringSoon
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    

}
