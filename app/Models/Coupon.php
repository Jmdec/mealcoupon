<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Services\BarcodeService;
use App\Services\HolidayService;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'coupon_date',
        'barcode',
        'barcode_image_path',
        'barcode_svg_path',
        'barcode_base64',
        'workday_code',
        'is_claimed',
        'claimed_at',
    ];

    protected $casts = [
        'coupon_date' => 'date',
        'is_claimed' => 'boolean',
        'claimed_at' => 'datetime',
    ];

    protected $appends = ['barcode_image_url', 'barcode_svg_url'];

    /**
     * Get the employee that owns the coupon.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    

    /**
     * Get the full URL for the barcode image
     */
    public function getBarcodeImageUrlAttribute()
    {
        if ($this->barcode_image_path) {
            return asset($this->barcode_image_path);
        }
        return null;
    }

    /**
     * Get the full URL for the barcode SVG
     */
    public function getBarcodeSvgUrlAttribute()
    {
        if ($this->barcode_svg_path) {
            return asset($this->barcode_svg_path);
        }
        return null;
    }

    /**
     * Scope to get coupons for a specific month and year
     */
    public function scopeForMonth($query, $month, $year)
    {
        return $query->whereMonth('coupon_date', $month)
                    ->whereYear('coupon_date', $year);
    }

    /**
     * Scope to get claimed coupons
     */
    public function scopeClaimed($query)
    {
        return $query->where('is_claimed', true);
    }

    /**
     * Scope to get unclaimed coupons
     */
    public function scopeUnclaimed($query)
    {
        return $query->where('is_claimed', false);
    }

    /**
     * Scope to get expired coupons (past date and unclaimed)
     */
    public function scopeExpired($query)
    {
        return $query->where('is_claimed', false)
                    ->where('coupon_date', '<', Carbon::today());
    }

    /**
     * Scope to get available coupons (future/today date and unclaimed)
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_claimed', false)
                    ->where('coupon_date', '>=', Carbon::today());
    }

    /**
     * Check if coupon is expired
     */
    public function isExpired()
    {
        return !$this->is_claimed && $this->coupon_date->isPast();
    }

    /**
     * Check if coupon can be claimed
     */
    public function canBeClaimed()
    {
        return !$this->is_claimed && $this->coupon_date >= Carbon::today();
    }

    /**
     * Generate unique barcode
     */
    public static function generateBarcode()
    {
        do {
            $barcode = 'MC' . str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
        } while (self::where('barcode', $barcode)->exists());

        return $barcode;
    }

    /**
     * Generate workday code
     */
    public static function generateWorkdayCode($employeeId, $date)
    {
        $dateStr = Carbon::parse($date)->format('Ymd');
        return 'WD' . $employeeId . $dateStr . mt_rand(100, 999);
    }

    /**
     * Generate coupons for working days only (excluding weekends and holidays)
     */
    public static function generateCouponsForMonth($employeeId, $month, $year)
    {
        $startDate = Carbon::create($year, $month, 1);
        $endDate = $startDate->copy()->endOfMonth();
        
        $workingDays = HolidayService::getWorkingDaysInRange($startDate, $endDate);
        $coupons = [];
        
        foreach ($workingDays as $workingDay) {
            // Check if coupon already exists for this employee and date
            $existingCoupon = self::where('employee_id', $employeeId)
                                 ->where('coupon_date', $workingDay->format('Y-m-d'))
                                 ->first();
            
            if (!$existingCoupon) {
                $coupon = self::create([
                    'employee_id' => $employeeId,
                    'coupon_date' => $workingDay->format('Y-m-d'),
                    'barcode' => self::generateBarcode(),
                    'workday_code' => self::generateWorkdayCode($employeeId, $workingDay),
                    'is_claimed' => false,
                ]);
                
                // Generate barcode images
                $coupon->generateBarcodeImages();
                $coupons[] = $coupon;
            }
        }
        
        return $coupons;
    }

    /**
     * Generate coupons for all employees for a specific month
     */
    public static function generateCouponsForAllEmployees($month, $year)
    {
        $employees = \App\Models\Employee::all();
        $allCoupons = [];
        
        foreach ($employees as $employee) {
            $coupons = self::generateCouponsForMonth($employee->id, $month, $year);
            $allCoupons = array_merge($allCoupons, $coupons);
        }
        
        return $allCoupons;
    }

    /**
     * Get next valid coupon date (excluding weekends and holidays)
     */
    public static function getNextValidCouponDate($fromDate = null)
    {
        $date = $fromDate ? Carbon::parse($fromDate) : Carbon::today();
        
        return HolidayService::getNextWorkingDay($date);
    }

    /**
     * Generate barcode images for this coupon
     */
    public function generateBarcodeImages()
    {
        $barcodeService = new BarcodeService();
        $barcodeFormats = $barcodeService->generateBarcodeFormats($this->barcode);

        $this->update([
            'barcode_image_path' => $barcodeFormats['png_path'],
            'barcode_svg_path' => $barcodeFormats['svg_path'],
            'barcode_base64' => $barcodeFormats['base64']
        ]);

        return $barcodeFormats;
    }

    /**
     * Delete barcode files when coupon is deleted
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($coupon) {
            $barcodeService = new BarcodeService();
            $barcodeService->deleteBarcodeFiles([
                $coupon->barcode_image_path,
                $coupon->barcode_svg_path
            ]);
        });
    }
    
}
