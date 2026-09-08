<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Parent\Address;
use App\Models\Parent\School;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

$parent = User::create([
    'full_name' => 'API Parent',
    'email' => 'apiparent'.time().'@example.com',
    'phone_number' => '091234567'.rand(1, 9),
    'password' => bcrypt('password'),
    'role_id' => 3, 
]);

$address = Address::create([
    'user_id' => $parent->id,
    'label' => 'Home',
    'is_default' => true,
    'lat' => 32.887,
    'lng' => 13.19,
]);

$school = School::create([
    'name' => 'API School',
    'lat' => 32.889,
    'lng' => 13.20,
]);

// Create a Sanctum token for the parent
$token = $parent->createToken('test-token')->plainTextToken;

$payload = [
    'school_id' => $school->id,
    'full_name' => 'Api Test Child',
    'birth_date' => Carbon::now()->subYears(8)->format('Y-m-d'),
    'gender' => 'female',
    'grade' => 2,
    'preferred_time_slot' => 'both',
];

$request = \Illuminate\Http\Request::create('/api/parent/children', 'POST', $payload);
$request->headers->set('Authorization', 'Bearer ' . $token);
$request->headers->set('Accept', 'application/json');

$response = app()->handle($request);

echo "Status Code: " . $response->getStatusCode() . "\n";
echo "Response Body:\n";
echo $response->getContent() . "\n";

$school->forceDelete();
$address->forceDelete();
$parent->forceDelete();
