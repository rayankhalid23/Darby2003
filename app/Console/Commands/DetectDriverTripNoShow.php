<?php

namespace App\Console\Commands;

use App\Services\Trip\TripLifecycleService;
use Illuminate\Console\Command;

class DetectDriverTripNoShow extends Command
{
    protected $signature = 'trips:detect-driver-no-show';
    protected $description = 'اكتشاف الرحلات التي لم يبدأها سائقها خلال 90 دقيقة من موعدها المحدد، وتسجيل غياب تلقائي عنها';

    public function handle(TripLifecycleService $service): int
    {
        $flaggedTripIds = $service->detectAndFlagNoShowDrivers();

        if (empty($flaggedTripIds)) {
            $this->info('لا توجد رحلات مطابقة لشروط الغياب التلقائي حالياً.');
            return Command::SUCCESS;
        }

        $this->info('تم تسجيل غياب تلقائي عن الرحلات: ' . implode(', ', $flaggedTripIds));

        return Command::SUCCESS;
    }
}
