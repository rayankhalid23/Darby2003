<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🖼️ تقديم ملفات storage/app/public (وثائق وصور السائقين/المركبات) عبر لارافيل
 * بدل الرابط الثابت المباشر /storage/...
 *
 * السبب (مؤكَّد تجريبياً بـ curl على /storage/... مع Origin مُرسَل):
 * الملفات تحت /storage يقدّمها خادم الويب (أو php artisan serve) مباشرة كملف
 * ثابت، دون أن يمر الطلب على كيرنل لارافيل إطلاقاً — فلا يُطبَّق أي ميدلوير،
 * بما فيه HandleCors، حتى لو كانت storage/* مُدرجة في config/cors.php.
 * هذا المسار يمر عبر لارافيل فعلياً (العنوان لا يقابل ملفاً حقيقياً على القرص)
 * فتُطبَّق عليه إعدادات CORS الخاصة بـ api/* تلقائياً، بالإضافة إلى ترويسة
 * احتياطية صريحة كخط دفاع ثانٍ. نفس الأسلوب المستخدم مسبقاً في AdminAvatarController.
 */
class MediaController extends Controller
{
    /** المجلدات المسموح تقديم الملفات منها فقط (allowlist أمني) */
    private const ALLOWED_DIRECTORIES = [
        'drivers/avatars',
        'drivers/documents',
        'drivers/vehicles',
        'uploads/admins/avatars',
        'children/photos',
        'children_photos',
        'avatars',
    ];

    // pdf مطلوب: وثائق السائق الرسمية (تأمين، فحص فني...) تقبل الآن PDF أيضاً
    // (راجع CompleteProfileRequest/UpdateLegalDocumentsRequest)، وكان غيابها هنا
    // يعني أن أي وثيقة تُرفع بصيغة PDF تُرفض عند عرضها بـ 404 رغم قبولها عند الرفع.
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'svg', 'pdf'];

    public function show(string $path): Response
    {
        if (str_contains($path, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
            abort(404);
        }

        $segments = explode('/', $path);
        $filename = array_pop($segments);
        $directory = implode('/', $segments);

        if (!in_array($directory, self::ALLOWED_DIRECTORIES, true)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            abort(404);
        }

        $disk = Storage::disk('public');
        $storedPath = $directory . '/' . $filename;

        if (!$disk->exists($storedPath)) {
            abort(404);
        }

        $headers = [
            'Content-Type'                 => $disk->mimeType($storedPath) ?: 'application/octet-stream',
            'Cache-Control'                => 'public, max-age=604800, immutable',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => '*',
            // inline لا attachment: نريد أن تُعرض الوثيقة داخل التطبيق/المتصفح مباشرة
            // (مهم خصوصاً لملفات PDF) بدل إجبار المستخدم على تنزيلها لفتحها.
            'Content-Disposition'          => 'inline; filename="' . addslashes($filename) . '"',
        ];

        return response($disk->get($storedPath), 200, $headers);
    }

    /**
     * هل يجب تضمين ملف الوسائط كـ Data URI (Base64) لتجاوز صفحة حماية LocalTunnel أو عند طلبه صراحة؟
     */
    public static function shouldEmbedAsDataUrl(): bool
    {
        // 1. هل تم تفعيل التضمين الصريح عبر .env؟
        if (config('filesystems.media_embed_base64') || env('MEDIA_EMBED_BASE64') === true) {
            return true;
        }

        // 2. فحص الطلب الحالي إن وُجد
        $request = request();
        if (!$request) {
            return false;
        }

        // طلب التضمين عبر ترويسة أو بارامتر
        if ($request->boolean('embed_media') || $request->boolean('data_url') || $request->header('X-Embed-Media') === 'true') {
            return true;
        }

        // كشف استخدام LocalTunnel تلقائياً: خدمة loca.lt تفرض صفحة وسيطة تكسر وسم <img>
        $host = $request->getHost();
        if (str_contains($host, 'loca.lt')) {
            return true;
        }

        return false;
    }

    /**
     * تحويل الملف المخزن محلياً إلى Data URI (Base64) ليعرض في وسم <img> مباشرة دون أي طلب شبكي إضافي.
     */
    public static function dataUrlFor(?string $storedPath): ?string
    {
        if (empty($storedPath)) {
            return null;
        }

        // إذا كان الرابط يبدأ بـ data: فهو جاهز بالفعل
        if (str_starts_with($storedPath, 'data:')) {
            return $storedPath;
        }

        // الروابط الخارجية الكاملة
        if (str_starts_with($storedPath, 'http://') || str_starts_with($storedPath, 'https://')) {
            $parsedPath = parse_url($storedPath, PHP_URL_PATH);
            if ($parsedPath && str_contains($parsedPath, 'storage/')) {
                $storedPath = preg_replace('#^.*?storage/#', '', $parsedPath);
            } elseif ($parsedPath && str_contains($parsedPath, 'api/media/')) {
                $storedPath = preg_replace('#^.*?api/media/#', '', $parsedPath);
            } else {
                return null;
            }
        }

        $relative = ltrim(preg_replace('#^storage/#', '', trim($storedPath)), '/');
        $disk = Storage::disk('public');

        if (!$disk->exists($relative)) {
            return null;
        }

        // تجنب تحميل ملفات ضخمة جداً في الذاكرة (الحد الأقصى 10 ميغابايت)
        $size = $disk->size($relative);
        if ($size > 10 * 1024 * 1024) {
            return null;
        }

        $mime = $disk->mimeType($relative) ?: 'application/octet-stream';
        $content = $disk->get($relative);
        $base64 = base64_encode($content);

        return "data:{$mime};base64,{$base64}";
    }

    /**
     * تحويل قيمة مسار مخزَّن في قاعدة البيانات إلى رابط يمر عبر لارافيل ويحصل على ترويسات CORS.
     * في حال كان الاتصال عبر LocalTunnel أو طُلب التضمين صراحة، يتم إرجاع Data URI تلقائياً لتجاوز حجب الصور.
     */
    public static function urlFor(?string $storedPath): ?string
    {
        if (empty($storedPath)) {
            return null;
        }

        // إذا طُلب تضمين الصور كـ Data URI أو تم اكتشاف LocalTunnel
        if (self::shouldEmbedAsDataUrl()) {
            $dataUrl = self::dataUrlFor($storedPath);
            if ($dataUrl !== null) {
                return $dataUrl;
            }
        }

        // إذا كان الرابط كاملاً، استخراج المسار النسبي إن كان يشير إلى storage على نفس الدومين
        if (str_starts_with($storedPath, 'http://') || str_starts_with($storedPath, 'https://')) {
            $parsedPath = parse_url($storedPath, PHP_URL_PATH);
            if ($parsedPath && str_contains($parsedPath, 'storage/')) {
                $storedPath = preg_replace('#^.*?storage/#', '', $parsedPath);
            } elseif ($parsedPath && str_contains($parsedPath, 'api/media/')) {
                return $storedPath;
            } else {
                return $storedPath;
            }
        }

        $relative = ltrim(preg_replace('#^storage/#', '', trim($storedPath)), '/');

        $segments = explode('/', $relative);
        $filename = array_pop($segments);
        $directory = implode('/', $segments);

        if (!in_array($directory, self::ALLOWED_DIRECTORIES, true)) {
            // مسار غير متوقع خارج القائمة البيضاء — نرجع رابط asset
            return asset('storage/' . $relative);
        }

        return url('api/media/' . $relative);
    }
}
