<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function getAnalytics(Request $request)
    {
        try {
            $from = $request->get('from', Carbon::now()->startOfMonth()->toDateString());
            $to = $request->get('to', Carbon::now()->toDateString());
            $employee = $request->get('employee');

            return response()->json($this->getAnalyticsData($from, $to, $employee));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getLiveAnalytics(Request $request)
    {
        try {
            $from = $request->get('from', Carbon::now()->startOfMonth()->toDateString());
            $to = $request->get('to', Carbon::now()->toDateString());
            $employee = $request->get('employee');
            $live = $request->get('live', 'true') === 'true';

            // Add cache control headers for live mode
            if ($live) {
                return response()->json($this->getAnalyticsData($from, $to, $employee))
                    ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');
            }

            return response()->json($this->getAnalyticsData($from, $to, $employee));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDepartmentAnalytics(Request $request)
    {
        try {
            $departments = $this->getDepartmentStats();
            
            return response()->json([
                'departments' => $departments,
                'totalStats' => $this->calculateTotalStats($departments),
                'lastUpdated' => Carbon::now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDynamicDepartmentAnalytics(Request $request)
    {
        try {
            $departments = $this->getDepartmentStats();
            
            return response()->json([
                'departments' => $departments,
                'totalStats' => $this->calculateTotalStats($departments),
                'lastUpdated' => Carbon::now()->toISOString()
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getUsageAlerts(Request $request)
    {
        try {
            $alerts = [];
            
            // Check for low claim rates by department
            $lowPerformingDepts = DB::table('employees as e')
                ->join('coupons as c', 'e.id', '=', 'c.employee_id')
                ->select('e.department', 
                    DB::raw('COUNT(c.id) as total_coupons'),
                    DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as claimed_coupons'),
                    DB::raw('ROUND((SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) / COUNT(c.id) * 100), 1) as claim_rate')
                )
                ->groupBy('e.department')
                ->havingRaw('claim_rate < 70')
                ->get();

            foreach ($lowPerformingDepts as $dept) {
                $alerts[] = [
                    'title' => 'Low Department Performance',
                    'message' => "{$dept->department} has a claim rate of {$dept->claim_rate}%",
                    'priority' => 'medium',
                    'data' => ['department' => $dept->department, 'claim_rate' => $dept->claim_rate]
                ];
            }

            // Check for expiring coupons (using coupon_date column)
            $expiringSoon = DB::table('coupons')
                ->where('is_claimed', 0)
                ->where('coupon_date', '<=', Carbon::now()->addDay()->toDateString())
                ->where('coupon_date', '>', Carbon::now()->toDateString())
                ->count();

            if ($expiringSoon > 0) {
                $alerts[] = [
                    'title' => 'Coupons Expiring Soon',
                    'message' => "{$expiringSoon} coupons will expire within 24 hours",
                    'priority' => 'high',
                    'data' => ['count' => $expiringSoon]
                ];
            }

            return response()->json(['alerts' => $alerts]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

   private function getAnalyticsData($from, $to, $employee = null)
{
    $nowPH = Carbon::now('Asia/Manila');

    // Base query for coupons with employees
    $baseQuery = DB::table('coupons as c')
        ->join('employees as e', 'c.employee_id', '=', 'e.id')
        ->whereBetween('c.created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

    if ($employee && $employee !== 'all') {
        $baseQuery->where('c.employee_id', $employee);
    }

    // Get all coupons in range
    $allCoupons = $baseQuery->get();

    $totalCoupons = $allCoupons->count();
    $claimedCoupons = $allCoupons->where('is_claimed', 1)->count();

    // Count expired coupons using PHP timezone for comparison
    $expiredCoupons = $allCoupons->filter(function($coupon) use ($nowPH) {
        // Compare coupon_date in PH timezone as date strings
        $couponDatePH = Carbon::parse($coupon->coupon_date, 'UTC')->setTimezone('Asia/Manila')->toDateString();
        return $couponDatePH < $nowPH->toDateString() && $coupon->is_claimed == 0;
    })->count();

    $activeCoupons = $totalCoupons - $claimedCoupons - $expiredCoupons;

    // Get daily stats grouped by PH date using PHP, not MySQL
    $dailyStatsRaw = $allCoupons->groupBy(function($coupon) {
        return Carbon::parse($coupon->created_at, 'UTC')->setTimezone('Asia/Manila')->toDateString();
    });

    $formattedDailyStats = [];

    foreach ($dailyStatsRaw as $date => $coupons) {
        $generated = $coupons->count();
        $claimed = $coupons->where('is_claimed', 1)->count();
        $expired = $coupons->filter(function($coupon) use ($date) {
            $couponDatePH = Carbon::parse($coupon->coupon_date, 'UTC')->setTimezone('Asia/Manila')->toDateString();
            return $couponDatePH < $date && $coupon->is_claimed == 0;
        })->count();

        $formattedDailyStats[] = [
            'date' => $date,
            'generated' => $generated,
            'claimed' => $claimed,
            'expired' => $expired,
        ];
    }

    // Get employee stats without MySQL timezone conversion, convert in PHP
    $employeeStats = [];
    if (!$employee || $employee === 'all') {
        $employeeStatsRaw = DB::table('employees as e')
            ->leftJoin('coupons as c', function($join) use ($from, $to) {
                $join->on('e.id', '=', 'c.employee_id')
                     ->whereBetween('c.created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
            })
            ->select(
                'e.id',
                DB::raw('CONCAT(e.first_name, " ", e.last_name) as name'),
                'e.department',
                'e.email',
                DB::raw('COUNT(c.id) as totalCoupons'),
                DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as claimedCoupons'),
                DB::raw('MAX(CASE WHEN c.is_claimed = 1 THEN c.updated_at ELSE NULL END) as lastClaimedUTC')
            )
            ->groupBy('e.id', 'e.first_name', 'e.last_name', 'e.department', 'e.email')
            ->orderBy('claimedCoupons', 'desc')
            ->get();

        // Convert lastClaimedUTC to PH timezone and format
        $employeeStats = $employeeStatsRaw->map(function($emp) {
            return (object) [
                'id' => $emp->id,
                'name' => $emp->name,
                'department' => $emp->department,
                'email' => $emp->email,
                'totalCoupons' => (int) $emp->totalCoupons,
                'claimedCoupons' => (int) $emp->claimedCoupons,
                'claimRate' => $emp->totalCoupons > 0
                    ? round(($emp->claimedCoupons / $emp->totalCoupons) * 100, 1)
                    : 0,
                'lastClaimed' => $emp->lastClaimedUTC
                    ? Carbon::parse($emp->lastClaimedUTC, 'UTC')->setTimezone('Asia/Manila')->toDateTimeString()
                    : null,
            ];
        });
    }

    return [
        'overview' => [
            'totalCoupons' => $totalCoupons,
            'claimedCoupons' => $claimedCoupons,
            'expiredCoupons' => $expiredCoupons,
            'activeCoupons' => $activeCoupons,
            'claimRate' => $totalCoupons > 0 ? round(($claimedCoupons / $totalCoupons) * 100, 2) : 0,
            'expirationRate' => $totalCoupons > 0 ? round(($expiredCoupons / $totalCoupons) * 100, 2) : 0,
        ],
        'dailyStats' => $formattedDailyStats,
        'employeeStats' => $employeeStats,
        'lastUpdated' => $nowPH->toDateTimeString()
    ];
}



    private function getDepartmentStats()
    {
        $departments = DB::table('employees')
            ->select('department')
            ->distinct()
            ->whereNotNull('department')
            ->pluck('department');

        $departmentStats = [];

        foreach ($departments as $department) {
            // Get employees in this department
            $employees = DB::table('employees')->where('department', $department)->get();
            $totalEmployees = $employees->count();

            // Get all coupons for this department
            $coupons = DB::table('coupons as c')
                ->join('employees as e', 'c.employee_id', '=', 'e.id')
                ->where('e.department', $department)
                ->get();

            $totalCoupons = $coupons->count();
            $claimedCoupons = $coupons->where('is_claimed', 1)->count();
            
            // Count expired coupons
            $expiredCoupons = $coupons->filter(function($coupon) {
                return $coupon->coupon_date < Carbon::now()->toDateString() && $coupon->is_claimed == 0;
            })->count();
            
            $activeCoupons = $totalCoupons - $claimedCoupons - $expiredCoupons;
            $claimRate = $totalCoupons > 0 ? ($claimedCoupons / $totalCoupons) * 100 : 0;

            // Get top performer in this department
            $topPerformer = DB::table('employees as e')
                ->join('coupons as c', 'e.id', '=', 'c.employee_id')
                ->select('e.first_name', 'e.last_name', 'e.id',
                    DB::raw('COUNT(c.id) as total_coupons'),
                    DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as claimed_coupons'),
                    DB::raw('CASE WHEN COUNT(c.id) > 0 THEN ROUND((SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) / COUNT(c.id) * 100), 1) ELSE 0 END as claim_rate')
                )
                ->where('e.department', $department)
                ->groupBy('e.id', 'e.first_name', 'e.last_name')
                ->orderBy('claim_rate', 'desc')
                ->first();

            // Calculate trend (comparing last 30 days vs previous 30 days)
            $currentPeriod = DB::table('coupons as c')
                ->join('employees as e', 'c.employee_id', '=', 'e.id')
                ->where('e.department', $department)
                ->where('c.created_at', '>=', Carbon::now()->subDays(30))
                ->count();

            $previousPeriod = DB::table('coupons as c')
                ->join('employees as e', 'c.employee_id', '=', 'e.id')
                ->where('e.department', $department)
                ->whereBetween('c.created_at', [Carbon::now()->subDays(60), Carbon::now()->subDays(30)])
                ->count();

            $trendPercentage = 0;
            $trend = 'stable';
            
            if ($previousPeriod > 0) {
                $trendPercentage = (($currentPeriod - $previousPeriod) / $previousPeriod) * 100;
                if ($trendPercentage > 5) {
                    $trend = 'up';
                } elseif ($trendPercentage < -5) {
                    $trend = 'down';
                }
            }

            // Monthly data for the last 3 months
            $monthlyData = [];
            for ($i = 2; $i >= 0; $i--) {
                $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
                $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();
                
                $monthCoupons = DB::table('coupons as c')
                    ->join('employees as e', 'c.employee_id', '=', 'e.id')
                    ->where('e.department', $department)
                    ->whereBetween('c.created_at', [$monthStart, $monthEnd])
                    ->get();

                $monthExpired = $monthCoupons->filter(function($coupon) {
                    return $coupon->coupon_date < Carbon::now()->toDateString() && $coupon->is_claimed == 0;
                })->count();

                $monthlyData[] = [
                    'month' => $monthStart->format('M'),
                    'generated' => $monthCoupons->count(),
                    'claimed' => $monthCoupons->where('is_claimed', 1)->count(),
                    'expired' => $monthExpired
                ];
            }

            $departmentStats[] = [
                'department' => $department,
                'totalEmployees' => $totalEmployees,
                'totalCoupons' => $totalCoupons,
                'claimedCoupons' => $claimedCoupons,
                'expiredCoupons' => $expiredCoupons,
                'activeCoupons' => $activeCoupons,
                'claimRate' => round($claimRate, 2),
                'avgCouponsPerEmployee' => $totalEmployees > 0 ? round($totalCoupons / $totalEmployees, 1) : 0,
                'trend' => $trend,
                'trendPercentage' => round(abs($trendPercentage), 1),
                'topPerformer' => [
                    'name' => $topPerformer ? "{$topPerformer->first_name} {$topPerformer->last_name}" : 'N/A',
                    'claimRate' => $topPerformer ? $topPerformer->claim_rate : 0,
                    'employee_id' => $topPerformer ? $topPerformer->id : null
                ],
                'monthlyData' => $monthlyData,
                'lastUpdated' => Carbon::now()->toISOString()
            ];
        }

        return $departmentStats;
    }

    private function calculateTotalStats($departments)
    {
        $totalDepartments = count($departments);
        $totalEmployees = array_sum(array_column($departments, 'totalEmployees'));
        $totalCoupons = array_sum(array_column($departments, 'totalCoupons'));
        $averageClaimRate = $totalDepartments > 0 ? array_sum(array_column($departments, 'claimRate')) / $totalDepartments : 0;
        
        $bestPerforming = '';
        $worstPerforming = '';
        $highestRate = 0;
        $lowestRate = 100;

        foreach ($departments as $dept) {
            if ($dept['claimRate'] > $highestRate) {
                $highestRate = $dept['claimRate'];
                $bestPerforming = $dept['department'];
            }
            if ($dept['claimRate'] < $lowestRate) {
                $lowestRate = $dept['claimRate'];
                $worstPerforming = $dept['department'];
            }
        }

        return [
            'totalDepartments' => $totalDepartments,
            'bestPerforming' => $bestPerforming,
            'worstPerforming' => $worstPerforming,
            'averageClaimRate' => round($averageClaimRate, 1),
            'totalEmployees' => $totalEmployees,
            'totalCoupons' => $totalCoupons
        ];
    }
}
