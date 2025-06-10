<?php

namespace App\Http\Controllers;

use App\Models\Notification; // Import the Notification model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Fetch notifications from the database
            $notifications = Notification::all();

            return response()->json([
                'notifications' => $notifications,
                'unread_count' => Notification::where('read', false)->count()
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function stream(Request $request)
    {
        // Server-Sent Events implementation
        return response()->stream(function () {
            while (true) {
                // Check for new notifications
                $notifications = Notification::where('read', false)->get();
                
                if ($notifications->isNotEmpty()) {
                    echo "data: " . json_encode($notifications) . "\n\n";
                    ob_flush();
                    flush();
                }
                
                sleep(5); // Check every 5 seconds
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Cache-Control'
        ]);
    }

    public function markAsRead($id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $notification->read = true;
            $notification->save();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function markAllAsRead()
    {
        try {
            Notification::where('read', false)->update(['read' => true]);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $notification->delete();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
public function generate()
    {
        Artisan::call('notifications:generate');
        return response()->json(['message' => 'Notifications generated successfully']);
    }
    private function generateDynamicNotifications()
    {
        $notifications = [];

        // Check for expiring coupons
        $expiringSoon = DB::table('coupons')
            ->where('is_claimed', 0)
            ->where('coupon_date', '<=', Carbon::now()->addDay()->toDateString())
            ->where('coupon_date', '>', Carbon::now()->toDateString())
            ->count();

        if ($expiringSoon > 0) {
            $notification = Notification::create([
                'type' => 'coupon_expiry',
                'title' => 'Coupons Expiring Soon',
                'message' => "{$expiringSoon} coupons will expire within 24 hours",
                'timestamp' => Carbon::now()->toISOString(),
                'read' => false,
                'priority' => 'high',
                'data' => json_encode(['count' => $expiringSoon])
            ]);
            $notifications[] = $notification;
        }

        // Check for low performing departments
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
            $notification = Notification::create([
                'type' => 'department_alert',
                'title' => 'Department Performance Alert',
                'message' => "{$dept->department} has a low claim rate of {$dept->claim_rate}%",
                'timestamp' => Carbon::now()->toISOString(),
                'read' => false,
                'priority' => 'medium',
                'data' => json_encode(['claim_rate' => $dept->claim_rate])
            ]);
            $notifications[] = $notification;
        }

        // Check for high performers (achievements)
        $highPerformers = DB::table('employees as e')
            ->join('coupons as c', 'e.id', '=', 'c.employee_id')
            ->select('e.first_name', 'e.last_name', 'e.department', 'e.id',
                DB::raw('COUNT(c.id) as total_coupons'),
                DB::raw('SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) as claimed_coupons'),
                DB::raw('ROUND((SUM(CASE WHEN c.is_claimed = 1 THEN 1 ELSE 0 END) / COUNT(c.id) * 100), 1) as claim_rate')
            )
            ->groupBy('e.id', 'e.first_name', 'e.last_name', 'e.department')
            ->havingRaw('claim_rate = 100')
            ->havingRaw('total_coupons >= 5')
            ->limit(3) // Only show top 3 to avoid too many notifications
            ->get();

        foreach ($highPerformers as $performer) {
            $notification = Notification::create([
                'type' => 'achievement',
                'title' => 'Perfect Performance!',
                'message' => "{$performer->first_name} {$performer->last_name} from {$performer->department} has 100% claim rate!",
                'timestamp' => Carbon::now()->toISOString(),
                'read' => false,
                'priority' => 'low',
                'data' => json_encode(['claim_rate' => 100, 'total_coupons' => $performer->total_coupons])
            ]);
            $notifications[] = $notification;
        }

        return $notifications;
    }
}
