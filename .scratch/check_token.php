<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$roles = DB::table('roles')->get();
foreach ($roles as $r) {
    $count = DB::table('permission_role')->where('role_id', $r->id)->count();
    echo "{$r->id} - {$r->name} ({$r->display_name}): {$count} perms\n";
}

$tokens = DB::table('personal_access_tokens')->orderBy('id', 'desc')->limit(5)->get();
echo "\nRecent tokens:\n";
foreach ($tokens as $t) {
    echo "ID: {$t->id} | User ID: {$t->tokenable_id} | Name: {$t->name} | Created: {$t->created_at} | Expires: {$t->expires_at} | Last used: {$t->last_used_at}\n";
}
