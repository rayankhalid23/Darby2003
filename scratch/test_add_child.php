<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Parent\Address;
use App\Models\Parent\School;
use App\Models\Parent\Child;
use App\Services\Parent\ChildService;
use Carbon\Carbon;

// 1. Create a parent
$parent = User::create([
    'full_name' => 'Test Parent',
    'email' => 'testparent'.time().'@example.com',
    'phone_number' => '091234567'.rand(1, 9),
    'password' => bcrypt('password'),
    'role_id' => 3, // Parent role
]);

echo "Parent created with ID: {$parent->id}\n";

// 2. Create an address
$address = Address::create([
    'user_id' => $parent->id,
    'label' => 'Home',
    'is_default' => true,
    'lat' => 32.887,
    'lng' => 13.19,
]);

echo "Address created with ID: {$address->id}\n";

// 3. Create a school
$school = School::create([
    'name' => 'Test School',
    'lat' => 32.889,
    'lng' => 13.20,
]);

echo "School created with ID: {$school->id}\n";

// 4. Use ChildService to create a child and test grade storage
$childService = app(ChildService::class);

$childData = [
    'parent_id' => $parent->id,
    'school_id' => $school->id,
    'address_id' => $address->id,
    'full_name' => 'Test Child Name',
    'birth_date' => Carbon::now()->subYears(10)->format('Y-m-d'),
    'gender' => 'male',
    'grade' => 4, // 4 = Primary
    'medical_notes' => 'None',
    'notification_radius' => 500,
    'preferred_time_slot' => 'both',
    'pickup_time' => '07:00',
    'dropoff_time' => '14:00',
];

try {
    $child = $childService->createChild($childData);
    echo "Child created with ID: {$child->id}\n";
    echo "Child Grade stored: {$child->grade}\n";
    echo "Child Educational Stage: {$child->school_stage}\n";
    echo "Child Stage Label: {$child->school_stage_label}\n";
    echo "Success!\n";
} catch (\Exception $e) {
    echo "Failed to create child: " . $e->getMessage() . "\n";
}

// Clean up
$child?->forceDelete();
$school->forceDelete();
$address->forceDelete();
$parent->forceDelete();
