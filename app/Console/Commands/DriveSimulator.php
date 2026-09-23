<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * أداة اختبار يدوية: تبث إحداثيات سائق حقيقية (من ملف JSON) دورياً إلى
 * POST /driver/trips/{tripId}/location كأن السائق يتحرك فعلياً على الخريطة،
 * وتتوقف تلقائياً عند كل محطة لتعطيك فرصة تنفيذ تأكيد الصعود/النزول اليدوي
 * (أو أي إجراء آخر) من جهازك قبل أن تكمل بقية المسار.
 *
 * الاستخدام (بتسجيل دخول حقيقي بالإيميل/الباسورد — لا مشاكل PowerShell مع الرمز |):
 *   php artisan trip:drive-simulator storage/app/drive-simulations/drive_route_215.json --email=driver@x.ly --password=xxxx
 *
 * أو بتوكن جاهز:
 *   php artisan trip:drive-simulator storage/app/drive-simulations/drive_route_215.json --token=1|xxxx
 */
class DriveSimulator extends Command
{
    protected $signature = 'trip:drive-simulator
        {file : مسار ملف JSON الذي يحتوي على trip_id والمسارات (legs)}
        {--token= : Sanctum token الخاص بالسائق (بديل عن email/password)}
        {--email= : إيميل السائق لتسجيل دخول حقيقي عبر /auth/login}
        {--password= : كلمة مرور السائق}
        {--base-url=http://127.0.0.1:8000 : عنوان السيرفر المحلي}
        {--interval=3 : ثواني الانتظار بين كل نقطة والتالية}';

    protected $description = 'يبث إحداثيات سائق من ملف بشكل دوري لمحاكاة رحلة حقيقية، ويتوقف عند كل محطة لتأكيد الصعود/النزول يدوياً';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (!file_exists($path)) {
            $this->error("الملف غير موجود: {$path}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!$data || empty($data['trip_id']) || empty($data['legs'])) {
            $this->error('صيغة ملف غير صالحة: يجب أن يحتوي على trip_id و legs.');
            return self::FAILURE;
        }

        $baseUrl = rtrim($this->option('base-url'), '/');

        $token = $this->option('token');
        if (!$token && $this->option('email') && $this->option('password')) {
            $this->line('🔐 تسجيل الدخول كسائق حقيقي عبر /auth/login ...');
            try {
                $loginResp = Http::acceptJson()->timeout(5)->post($baseUrl . '/api/auth/login', [
                    'email'       => $this->option('email'),
                    'password'    => $this->option('password'),
                    'device_name' => 'drive-simulator',
                    'platform'    => 'android',
                ]);
                if ($loginResp->successful() && $loginResp->json('access_token')) {
                    $token = $loginResp->json('access_token');
                    $this->line('   نجح تسجيل الدخول: ' . $loginResp->json('user.full_name', ''));
                } else {
                    $this->error('   فشل تسجيل الدخول: ' . $loginResp->body());
                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error('   تعذر الاتصال بالسيرفر لتسجيل الدخول: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        if (!$token) {
            $this->error('مطلوب --token=... أو (--email=... --password=...).');
            return self::FAILURE;
        }

        $tripId = (int) $data['trip_id'];
        $interval = (int) $this->option('interval');
        $subId = $data['active_subscription_id'] ?? null;
        $qrToken = $data['child_qr_code_token'] ?? null;

        $this->info("🚌 بدء محاكاة الرحلة رقم {$tripId} — سيتم إرسال الموقع كل {$interval} ثانية.");
        $this->line("   السيرفر: {$baseUrl}");
        $this->newLine();

        // بدء الرحلة فعلياً (POST /start) بأول إحداثية في أول leg
        $firstPoint = $data['legs'][0]['points'][0] ?? null;
        if ($firstPoint) {
            $this->callApi('POST', "/api/v1/driver/trips/{$tripId}/start", $token, $baseUrl, [
                'latitude' => $firstPoint[0],
                'longitude' => $firstPoint[1],
            ], announce: '🟢 بدء الرحلة (start)');
        }

        $prevPoint = null;

        foreach ($data['legs'] as $legIndex => $leg) {
            $this->newLine();
            $this->line("<fg=cyan>━━ المرحلة " . ($legIndex + 1) . ": {$leg['label']} ━━</>");

            foreach ($leg['points'] as $i => $point) {
                [$lat, $lng] = $point;
                $heading = $prevPoint ? $this->bearing($prevPoint[0], $prevPoint[1], $lat, $lng) : 0;
                $speed = $prevPoint ? 30 : 0;

                $resp = $this->callApi('POST', "/api/v1/driver/trips/{$tripId}/location", $token, $baseUrl, [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'speed' => $speed,
                    'heading' => $heading,
                ]);

                $ok = $resp && $resp->successful();
                $this->line(sprintf(
                    '  [%s] نقطة %d/%d  (%.6f, %.6f)  %s',
                    $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>',
                    $i + 1,
                    count($leg['points']),
                    $lat,
                    $lng,
                    $ok ? '' : ('- ' . ($resp?->body() ?? 'no response'))
                ));

                $prevPoint = [$lat, $lng];

                if ($i < count($leg['points']) - 1) {
                    sleep($interval);
                }
            }

            // توقف عند نهاية المرحلة لإعطائك فرصة تنفيذ الإجراء يدوياً (تأكيد صعود/نزول مثلاً)
            $action = $leg['pause_action'] ?? null;
            if ($action) {
                $this->newLine();
                $this->warn("⏸  وصل السائق. الإجراء المطلوب الآن: {$action}");
                $this->printCurlHint($action, $tripId, $token, $baseUrl, $subId, $qrToken, $prevPoint);
                $this->comment('اضغط Enter بعد تنفيذ الإجراء (يدوياً من تطبيقك أو بنسخ الأمر أعلاه) للمتابعة إلى المرحلة التالية...');
                fgets(STDIN);
            }
        }

        $this->newLine();
        $this->info('✅ انتهت كل المراحل. لإنهاء الرحلة الآن:');
        $this->line("curl -s -X POST {$baseUrl}/api/v1/driver/trips/{$tripId}/complete \\");
        $this->line("  -H \"Authorization: Bearer {$token}\" -H \"Accept: application/json\"");

        return self::SUCCESS;
    }

    private function callApi(string $method, string $uri, string $token, string $baseUrl, array $body, ?string $announce = null)
    {
        if ($announce) {
            $this->line($announce);
        }
        try {
            $resp = Http::withToken($token)->acceptJson()->timeout(5)->{strtolower($method)}($baseUrl . $uri, $body);
            if ($announce) {
                $this->line('   ' . ($resp->successful() ? '<fg=green>نجح</>' : '<fg=red>فشل: ' . $resp->body() . '</>'));
            }
            return $resp;
        } catch (\Throwable $e) {
            $this->error("   تعذر الاتصال بالسيرفر: {$e->getMessage()}");
            return null;
        }
    }

    private function printCurlHint(string $action, int $tripId, string $token, string $baseUrl, ?int $subId, ?string $qrToken, ?array $point): void
    {
        $lat = $point[0] ?? 0;
        $lng = $point[1] ?? 0;
        $this->line("curl -s -X POST {$baseUrl}/api/v1/driver/trips/{$tripId}/{$action} \\");
        $this->line("  -H \"Authorization: Bearer {$token}\" -H \"Accept: application/json\" \\");
        $this->line("  -d \"trip_child_id={$subId}\" \\");
        $this->line("  -d \"latitude={$lat}\" -d \"longitude={$lng}\"");
    }

    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);
        return fmod((rad2deg(atan2($y, $x)) + 360), 360);
    }
}
