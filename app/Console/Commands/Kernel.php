<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\GenerateNotifications::class,
    ];

    // protected function schedule(Schedule $schedule)
    // {
    //     // Generate notifications every 5 minutes
    //     $schedule->command('notifications:generate')
    //              ->everyFiveMinutes()
    //              ->withoutOverlapping();
    // }
protected function schedule(Schedule $schedule)
{
    $schedule->command('notifications:generate')->everyMinute();
}


    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}