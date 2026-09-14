<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class DriverRecommendationController extends Controller
{
    public function getRankedDrivers(Request $request)
    {
        // 1. استلام بيانات السائقين المتاحين (سواء من الداتابيز أو من الـ Request)
        $availableDrivers = $request->input('drivers');

        if (empty($availableDrivers)) {
            return response()->json(['status' => 'error', 'message' => 'لا يوجد سائقين متاحين للترتيب'], 400);
        }

        // 2. تحديد مسار ملف البايثون داخل المشروع
        $scriptPath = base_path('python/predict.py');

        // 3. تحويل بيانات السائقين إلى JSON لإرسالها לבايثون
        $jsonData = json_encode($availableDrivers);

        // 4. استدعاء سكريبت البايثون وتمرير البيانات إليه
        $result = Process::input($jsonData)->run("python3 {$scriptPath}");

        // 5. التحقق من نجاح العملية
        if ($result->successful()) {
            $rankedDrivers = json_decode($result->output(), true);

            // التحقق من أن النتيجة لا تحتوي على خطأ من داخل بايثون
            if (isset($rankedDrivers['error'])) {
                Log::error('Python AI Error: ' . $rankedDrivers['error']);
                return response()->json(['status' => 'error', 'message' => 'حدث خطأ داخلي أثناء ترتيب السائقين'], 500);
            }

            // إرجاع القائمة المرتبة بنجاح لتطبيق Flutter
            return response()->json([
                'status' => 'success',
                'data' => $rankedDrivers
            ]);
        }

        // في حال فشل تشغيل السكريبت من السيرفر
        Log::error('Python Script Failed: ' . $result->errorOutput());
        return response()->json([
            'status' => 'error',
            'message' => 'فشل في تشغيل نظام الذكاء الاصطناعي'
        ], 500);
    }
}
