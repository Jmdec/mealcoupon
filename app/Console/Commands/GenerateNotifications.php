<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GenerateNotifications extends Command
{
    protected $signature = 'notifications:generate';
    protected $description = 'Generate real-time notifications based on coupon data';

    public function handle()
    {
        $this->info('Generating notifications...');

        // Check for expiring coupons
        $this->checkExpiringCoupons();
        
        // Check for low performing departments
        $this->checkLowPerformingDepartments();
        
        // Check for achievements
        $this->checkAchievements();

        $this->info('Notifications generated successfully!');
    }

    private function checkExpiringCoupons()
    {
        $expiringSoon = DB::table('coupons')
            ->where('is_claimed', false)
            ->where('coupon_date', '<=', Carbon::now()->addDay())
            ->where('coupon_date', '>', Carbon::now())
            ->count();

        if ($expiringSoon > 0) {
            // Check if notification already exists for today
            $existingNotification = DB::table('notifications')
                ->where('type', 'coupon_expiry')
                ->where('created_at', '>=', Carbon::today())
                ->first();

            if (!$existingNotification) {
                DB::table('notifications')->insert([
                    'type' => 'coupon_expiry',
                    'title' => 'Coupons Expiring Soon',
                    'message' => "{$expiringSoon} coupons will expire within 24 hours",
                    'priority' => 'high',
                    'data' => json_encode(['count' => $expiringSoon]),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }
        }
    }

    private function checkLowPerformingDepartments()
    {
        $lowPerformingDepts = DB::table('employees as e')
            ->join('coupons as c', 'e.id', '=', 'c.employee_id')
            ->select('e.department', 
                DB::raw('COUNT(c.id) as total_coupons'),
                DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as claimed_coupons'),
                DB::raw('(SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) / COUNT(c.id) * 100) as claim_rate')
            )
            ->groupBy('e.department')
            ->havingRaw('claim_rate < 70')
            ->get();

        foreach ($lowPerformingDepts as $dept) {
            // Check if notification already exists for this department today
            $existingNotification = DB::table('notifications')
                ->where('type', 'department_alert')
                ->where('department', $dept->department)
                ->where('created_at', '>=', Carbon::today())
                ->first();

            if (!$existingNotification) {
                DB::table('notifications')->insert([
                    'type' => 'department_alert',
                    'title' => 'Department Performance Alert',
                    'message' => "{$dept->department} has a low claim rate of " . round($dept->claim_rate, 1) . "%",
                    'priority' => 'medium',
                    'department' => $dept->department,
                    'data' => json_encode(['claim_rate' => round($dept->claim_rate, 1)]),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }
        }
    }

    private function checkAchievements()
    {
        // Check for employees with 100% claim rate
        $perfectEmployees = DB::table('employees as e')
            ->join('coupons as c', 'e.id', '=', 'c.employee_id')
            ->select('e.id', 'e.first_name', 'e.last_name', 'e.department',
                DB::raw('COUNT(c.id) as total_coupons'),
                DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as claimed_coupons'),
                DB::raw('(SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) / COUNT(c.id) * 100) as claim_rate')
            )
            ->groupBy('e.id', 'e.first_name', 'e.last_name', 'e.department')
            ->havingRaw('claim_rate = 100')
            ->havingRaw('total_coupons >= 5') // At least 5 coupons
            ->get();

        foreach ($perfectEmployees as $employee) {
            // Check if achievement notification already exists for this employee
            $existingNotification = DB::table('notifications')
                ->where('type', 'achievement')
                ->where('employee_id', $employee->id)
                ->where('created_at', '>=', Carbon::today())
                ->first();

            if (!$existingNotification) {
                DB::table('notifications')->insert([
                    'type' => 'achievement',
                    'title' => 'Perfect Claim Rate Achievement',
                    'message' => "{$employee->first_name} {$employee->last_name} from {$employee->department} has achieved 100% claim rate!",
                    'priority' => 'low',
                    'employee_id' => $employee->id,
                    'department' => $employee->department,
                    'data' => json_encode(['claim_rate' => 100, 'total_coupons' => $employee->total_coupons]),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }
        }
    }
}