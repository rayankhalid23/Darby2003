<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\Shared\Trip;
use App\Models\Shared\TripTracking;
use Illuminate\Console\Command;

/**
 * يحرّك كل الرحلات النشطة (status=in_progress) التي أنشأها trip:seed-radar-demo على
 * مسارها الحقيقي المحفوظ (storage/app/drive-simulations/radar/trip_{id}.json) بشكل
 * مستمر وطبيعي: يكتب نقاط trip_tracking مباشرة (بدون الحاجة لخادم مُشغَّل أو تسجيل
 * دخول) ويحدّث موقع السائق الحي (drivers.current_lat/lng)، فيظهر التحرّك فوراً في
 * رادار الأدمن GET /api/admin/dashboard/active-trips.
 *
 * الاستخدام:
 *   php artisan trip:radar-simulate
 *   php artisan trip:radar-simulate --interval=2 --step=1
 *
 * يعمل بشكل متواصل (ذهاب ثم عودة على نفس المسار) حتى إيقافه يدوياً بـ Ctrl+C.
 */
class RadarLiveSimulator extends Command
{
    protected $signature = 'trip:radar-simulate
        {--interval=2 : ثواني الانتظار بين كل نبضة تحديث}
        {--step=1 : عدد نقاط المسار التي يتقدمها السائق في كل نبضة}
        {--only= : تشغيل رحلات محددة فقط، مفصولة بفاصلة (مثال: 101,102)}';

    protected $description = 'يحرّك السائقين على رحلاتهم النشطة الحالية بشكل مستمر وطبيعي لاختبار رادار الأدمن الحي';

    public function handle(): int
    {
        $routeDir = storage_path('app/drive-simulations/radar');
        $files = glob($routeDir . '/trip_*.json') ?: [];

        if (empty($files)) {
            $this->error('لا توجد ملفات مسارات محفوظة. شغّل أولاً: php artisan trip:seed-radar-demo');
            return self::FAILURE;
        }

        $onlyFilter = null;
        if ($this->option('only')) {
            $onlyFilter = array_map('intval', explode(',', $this->option('only')));
        }

        $interval = max(1, (int) $this->option('interval'));
        $step = max(1, (int) $this->option('step'));

        $fleet = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data || empty($data['trip_id']) || empty($data['points']) || count($data['points']) < 2) {
                continue;
            }

            $tripId = (int) $data['trip_id'];
            if ($onlyFilter && !in_array($tripId, $onlyFilter, true)) {
                continue;
            }

            $trip = Trip::where('id', $tripId)->where('status', 'in_progress')->first();
            if (!$trip) {
                $this->warn("⏭  تخطي trip_id={$tripId}: لم تعد الرحلة نشطة (in_progress) في قاعدة البيانات.");
                continue;
            }

            $driver = Driver::with('user')->find($data['driver_id']);
            if (!$driver) {
                continue;
            }

            $fleet[] = [
                'trip_id'      => $tripId,
                'driver_id'    => $driver->id,
                'driver_name'  => $driver->user?->full_name ?? "سائق #{$driver->id}",
                'points'       => $data['points'],
                'index'        => 0,
                'direction'    => 1, // 1 = للأمام نحو المدرسة، -1 = عائد نحو نقطة الانطلاق
                'prev_point'   => $data['points'][0],
            ];
        }

        if (empty($fleet)) {
            $this->error('لا توجد رحلات نشطة صالحة للتحريك حالياً.');
            return self::FAILURE;
        }

        $this->info('🚌 بدء محاكاة ' . count($fleet) . ' رحلة حية — نبضة كل ' . $interval . ' ثانية. اضغط Ctrl+C للإيقاف.');
        $this->newLine();

        while (true) {
            foreach ($fleet as &$unit) {
                $points = $unit['points'];
                $count = count($points);

                $unit['index'] += $unit['direction'] * $step;

                if ($unit['index'] >= $count - 1) {
                    $unit['index'] = $count - 1;
                    $unit['direction'] = -1;
                    $this->line("   🏁 trip_id={$unit['trip_id']} ({$unit['driver_name']}) وصل للمحطة الأخيرة — يعكس الاتجاه.");
                } elseif ($unit['index'] <= 0) {
                    $unit['index'] = 0;
                    $unit['direction'] = 1;
                    $this->line("   🔁 trip_id={$unit['trip_id']} ({$unit['driver_name']}) عاد لنقطة الانطلاق — يواصل الجولة.");
                }

                $cur = $points[$unit['index']];
                $prev = $unit['prev_point'];

                $distanceKm = $this->haversineKm($prev[0], $prev[1], $cur[0], $cur[1]);
                $speedKmh = $interval > 0 ? round(($distanceKm / $interval) * 3600, 1) : 0;
                $heading = $this->bearing($prev[0], $prev[1], $cur[0], $cur[1]);

                TripTracking::create([
                    'trip_id'     => $unit['trip_id'],
                    'latitude'    => $cur[0],
                    'longitude'   => $cur[1],
                    'speed'       => min($speedKmh, 80),
                    'recorded_at' => now(),
                ]);

                Driver::where('id', $unit['driver_id'])->update([
                    'current_lat'  => $cur[0],
                    'current_lng'  => $cur[1],
                    'last_ping_at' => now(),
                ]);

                $this->line(sprintf(
                    '   [%s] trip_id=%-4d %-28s نقطة %d/%d  (%.6f, %.6f)  %d كم/س  اتجاه %d°',
                    now()->format('H:i:s'),
                    $unit['trip_id'],
                    $unit['driver_name'],
                    $unit['index'] + 1,
                    $count,
                    $cur[0],
                    $cur[1],
                    min($speedKmh, 80),
                    (int) $heading
                ));

                $unit['prev_point'] = $cur;
            }
            unset($unit);

            sleep($interval);
        }
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1r = deg2rad($lat1);
        $lat2r = deg2rad($lat2);
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos($lat2r);
        $x = cos($lat1r) * sin($lat2r) - sin($lat1r) * cos($lat2r) * cos($dLng);
        return fmod((rad2deg(atan2($y, $x)) + 360), 360);
    }
}
