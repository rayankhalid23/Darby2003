<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// The first parent (role_id=7) is ID=7
// Let's check children for each parent and find trips

echo "=== Parents with children ===\n";
$parentsWithChildren = DB::select('
    SELECT u.id, u.full_name, COUNT(c.id) as children_count 
    FROM users u 
    LEFT JOIN children c ON c.parent_id = u.id 
    WHERE u.role_id = 7 
    GROUP BY u.id, u.full_name 
    HAVING children_count > 0 
    ORDER BY u.id 
    LIMIT 10
');
foreach ($parentsWithChildren as $p) {
    echo "Parent ID: $p->id | Name: $p->full_name | Children: $p->children_count\n";
}

// The FIRST parent with role_id=7 is ID=7
echo "\n=== Children of Parent ID=7 (first parent) ===\n";
$children = DB::select('SELECT id, full_name, parent_id FROM children WHERE parent_id = 7');
foreach ($children as $c) echo "Child ID: $c->id | Name: $c->full_name\n";
$childIds = array_column($children, 'id');

if ($childIds) {
    $inList = implode(',', $childIds);
    
    echo "\n=== Active Subscriptions for these children ===\n";
    $subs = DB::select("
        SELECT sub.id, sub.route_id, sub.status, rc.child_id
        FROM active_subscriptions sub
        JOIN request_children rc ON rc.id = sub.request_child_id
        WHERE rc.child_id IN ($inList)
    ");
    foreach ($subs as $s) echo "Sub ID: $s->id | Route ID: $s->route_id | Status: $s->status | Child: $s->child_id\n";
    
    $routeIds = array_unique(array_column($subs, 'route_id'));
    
    if ($routeIds) {
        $routeList = implode(',', $routeIds);
        echo "\n=== Trips for routes: $routeList ===\n";
        $trips = DB::select("
            SELECT id, route_id, status, trip_type, trip_date, shift_slot 
            FROM trips 
            WHERE route_id IN ($routeList) 
            ORDER BY trip_date DESC 
            LIMIT 20
        ");
        foreach ($trips as $t) {
            echo "Trip ID: $t->id | Status: $t->status | Route: $t->route_id | Date: $t->trip_date | Type: $t->trip_type\n";
        }
    }
}
