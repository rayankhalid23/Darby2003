<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * يُشغَّل دورياً (مثلاً كل 15 دقيقة عبر Task Scheduler أو Cron)
 * ليجلب السائقين الذين انتهت مدة إيقافهم ويعيدهم للبحث تلقائياً.
 *
 * الاستخدام:
 *   php artisan drivers:restore-suspended
 */
class RestoreSuspendedDrivers extends Command
{
    protected $signature   = 'drivers:restore-suspended';
    protected $description  = 'يستعيد السائقين الذين انتهت مدة إيقافهم وفق سلم العقوبات';

    public function handle(): int
    {
        // السائقون الذين لديهم suspended_until في الماضي (ليس null ولا دائم)
        $drivers = Driver::query()
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->get();

        $restored = 0;

        foreach ($drivers as $driver) {
            $driver->update(['suspended_until' => null]);
            $restored++;

            Log::info('RestoreSuspendedDrivers: driver restored to search', [
                'driver_id'       => $driver->id,
                'suspension_count' => $driver->suspension_count,
            ]);

            $this->line("✅ استُعيد السائق #{$driver->id} للبحث.");
        }

        $this->info("اكتمل: تم استعادة {$restored} سائق.");

        return self::SUCCESS;
    }
}
