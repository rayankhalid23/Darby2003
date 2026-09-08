<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // نسخ الشروط والأحكام: لكل جمهور (ولي أمر/سائق/كلاهما) نسخة سارية واحدة فقط
        // في أي لحظة (status = published)، والباقي إما مسودة قيد التحرير أو مؤرشفة.
        Schema::create('terms_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_number', 20);
            $table->string('title');
            $table->enum('audience', ['parent', 'driver', 'both'])->default('both');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audience', 'status']);
        });

        // مواد كل نسخة، مرقّمة ومرتّبة لعرضها ككيان قانوني واحد متسلسل
        Schema::create('terms_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terms_version_id')->constrained('terms_versions')->cascadeOnDelete();
            $table->unsignedInteger('article_number');
            $table->string('title');
            $table->longText('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['terms_version_id', 'article_number']);
        });

        // سجل موافقة كل مستخدم على كل نسخة وافق عليها فعلياً — هو الدليل القانوني
        // على الموافقة (IP + user agent + توقيت)، ويُستعلَم عنه مباشرة عند أي نزاع
        // بدل الاعتماد على افتراض أن "التسجيل يعني الموافقة".
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('terms_version_id')->constrained('terms_versions')->cascadeOnDelete();
            $table->string('role', 10); // parent | driver
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // مستخدم واحد لا يمكن أن يوافق مرتين على نفس النسخة بالضبط
            $table->unique(['user_id', 'terms_version_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
        Schema::dropIfExists('terms_articles');
        Schema::dropIfExists('terms_versions');
    }
};
