<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Contract;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Trip;
use App\Enums\driver\DriverShift;

class SubscriptionRoutingTestingSeeder extends Seeder
{
    public function run(): void
    {
        // طھظ†ط¸ظٹظپ ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ط³ط§ط¨ظ‚ط© ظ„ظ„ط§ط®طھط¨ط§ط± ط¥ظ† ظˆط¬ط¯طھ ظ„ط¶ظ…ط§ظ† ط¥ظ…ظƒط§ظ†ظٹط© طھط´ط؛ظٹظ„ ط§ظ„ظ€ Seeder ط£ظƒط«ط± ظ…ظ† ظ…ط±ط© ط¨ط¯ظˆظ† ظ…ط´ط§ظƒظ„
        $testEmails = [
            'driver.experienced@darby.test',
            'driver.newbie@darby.test',
            'parent.testing@darby.test',
        ];

        $oldUserIds = DB::table('users')->whereIn('email', $testEmails)->pluck('id');
        if ($oldUserIds->isNotEmpty()) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $driverIds = DB::table('drivers')->whereIn('user_id', $oldUserIds)->pluck('id');
            $parentIds = DB::table('parents')->whereIn('user_id', $oldUserIds)->pluck('id');

            DB::table('driver_seat_slots')->whereIn('driver_id', $driverIds)->delete();
            DB::table('active_subscriptions')->whereIn('driver_id', $driverIds)->orWhereIn('parent_id', $parentIds)->delete();
            DB::table('trips')->whereIn('driver_id', $driverIds)->delete();
            DB::table('routes')->whereIn('driver_id', $driverIds)->delete();
            DB::table('vehicles')->whereIn('driver_id', $driverIds)->delete();
            DB::table('contracts')->whereIn('driver_id', $oldUserIds)->orWhereIn('parent_id', $oldUserIds)->delete();

            $reqIds = DB::table('requests')->whereIn('driver_id', $driverIds)->orWhereIn('parent_id', $parentIds)->pluck('id');
            DB::table('request_children')->whereIn('request_id', $reqIds)->delete();
            DB::table('requests')->whereIn('id', $reqIds)->delete();

            $childIds = DB::table('children')->whereIn('parent_id', $parentIds)->pluck('id');
            DB::table('children')->whereIn('id', $childIds)->delete();

            DB::table('drivers')->whereIn('id', $driverIds)->delete();
            DB::table('parents')->whereIn('id', $parentIds)->delete();
            DB::table('users')->whereIn('id', $oldUserIds)->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // 0. ط§ظ„طھط£ظƒط¯ ظ…ظ† ظˆط¬ظˆط¯ ط§ظ„ط£ط¯ظˆط§ط± (Roles)
        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'Admin',  'display_name' => 'ظ…ط¯ظٹط± ط§ظ„ظ†ط¸ط§ظ…'],
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'ط³ط§ط¦ظ‚'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ظˆظ„ظٹ ط£ظ…ط±'],
        ]);

        // 1. ط§ظ„ظ…ط¯ط±ط³ط© ط§ظ„ط§ظپطھط±ط§ط¶ظٹط©
        $schoolId = DB::table('schools')->insertGetId([
            'name'       => 'ظ…ط¯ط±ط³ط© ط·ط±ط§ط¨ظ„ط³ ط§ظ„ظ†ط¸ظٹظپط© ط§ظ„ظ†ظ…ظˆط°ط¬ظٹط©',
            'address'    => 'ط´ط§ط±ط¹ ط¹ظ…ط± ط§ظ„ظ…ط®طھط§ط±طŒ ط·ط±ط§ط¨ظ„ط³',
            'lat'        => 32.8870,
            'lng'        => 13.1910,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // =========================================================================
        // 2. ط¥ظ†ط´ط§ط، ط§ظ„ط³ط§ط¦ظ‚ ط§ظ„ط£ظˆظ„: ط®ط¨ظٹط± (ظ„ط¯ظٹظ‡ ظ…ط³ط§ط±ط§طھ ظˆط±ط­ظ„ط§طھ ط³ط§ط¨ظ‚ط© ظˆط­ط¬ظˆط²ط§طھ ظ…ظ‚ط§ط¹ط¯)
        // =========================================================================
        $userDriver1 = User::create([
            'full_name'     => 'ط§ظ„ظƒط§ط¨طھظ† ط¹ط¨ط¯ ط§ظ„ط³ظ„ط§ظ… ط§ظ„ط®ط¨ظٹط±',
            'email'         => 'driver.experienced@darby.test',
            'phone_number'  => '0912222222',
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $driver1 = Driver::create([
            'user_id'           => $userDriver1->id,
            'national_id'       => 'NAT-111111111',
            'license_number'    => 'LIC-111111111',
            'license_expiry'    => now()->addYears(3)->format('Y-m-d'),
            'status'            => 'Approved',
            'subscription_type' => 'both',
            'accepted_gender'   => 'both',
            'gender'            => 'male',
            'shift'             => DriverShift::BOTH,
            'morning_go'        => true,
            'morning_return'    => true,
            'afternoon_go'      => true,
            'afternoon_return'  => true,
            'current_lat'       => 32.8800,
            'current_lng'       => 13.1800,
        ]);

        // ط³ظٹط§ط±ط© ط§ظ„ط³ط§ط¦ظ‚ 1
        $vehicle1Id = DB::table('vehicles')->insertGetId([
            'driver_id'       => $driver1->id,
            'plate_number'    => '10-54321',
            'brand'           => 'Toyota',
            'model'           => 'Coaster',
            'year'            => '2023',
            'color'           => 'White',
            'type'            => 'Bus',
            'capacity_manual' => 4, // ط³ط¹ط© ظƒط±طھ 4 ظ…ظ‚ط§ط¹ط¯ ظ„ظ„ط§ط®طھط¨ط§ط± ط§ظ„ط¯ظ‚ظٹظ‚
            'is_verified'     => 1,
            'has_ac'          => 1,
            'status'          => 'Active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // ظ…ظ‚ط§ط¹ط¯ ط§ظ„ط³ط§ط¦ظ‚ 1 (ط­ط¬ط² ظ…ظ‚ط¹ط¯ 1 ظ…ظ† ط£طµظ„ 4)
        DriverSeatSlot::create([
            'driver_id'      => $driver1->id,
            'slot'           => DriverSeatSlot::MORNING_GO,
            'total_seats'    => 4,
            'reserved_seats' => 1,
        ]);
        DriverSeatSlot::create([
            'driver_id'      => $driver1->id,
            'slot'           => DriverSeatSlot::MORNING_RETURN,
            'total_seats'    => 4,
            'reserved_seats' => 1,
        ]);

        // =========================================================================
        // 3. ط¥ظ†ط´ط§ط، ط§ظ„ط³ط§ط¦ظ‚ ط§ظ„ط«ط§ظ†ظٹ: ط¬ط¯ظٹط¯ (ظ„ظٹط³ ظ„ط¯ظٹظ‡ ط£ظٹ ظ…ط³ط§ط±ط§طھ ط£ظˆ ط±ط­ظ„ط§طھ ط³ط§ط¨ظ‚ط§ظ‹ - ظپطھط±ط© طµط¨ط§ط­ظٹط© ط°ظ‡ط§ط¨ ظپظ‚ط·)
        // =========================================================================
        $userDriver2 = User::create([
            'full_name'     => 'ط§ظ„ظƒط§ط¨طھظ† ظ…ط­ظ…ط¯ ط§ظ„ط¬ط¯ظٹط¯',
            'email'         => 'driver.newbie@darby.test',
            'phone_number'  => '0913333333',
            'password_hash' => Hash::make('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $driver2 = Driver::create([
            'user_id'           => $userDriver2->id,
            'national_id'       => 'NAT-222222222',
            'license_number'    => 'LIC-222222222',
            'license_expiry'    => now()->addYears(2)->format('Y-m-d'),
            'status'            => 'Approved',
            'subscription_type' => 'multi_day',
            'accepted_gender'   => 'both',
            'gender'            => 'male',
            'shift'             => DriverShift::MORNING,
            'morning_go'        => true,  // ظٹط¹ظ…ظ„ طµط¨ط§ط­ظٹ ط°ظ‡ط§ط¨ ظپظ‚ط·
            'morning_return'    => false,
            'afternoon_go'      => false,
            'afternoon_return'  => false,
            'current_lat'       => 32.8700,
            'current_lng'       => 13.1700,
        ]);

        // ط³ظٹط§ط±ط© ط§ظ„ط³ط§ط¦ظ‚ 2 (ط³ط¹ط© 2 ظ…ظ‚ط¹ط¯ ظپظ‚ط·)
        DB::table('vehicles')->insert([
            'driver_id'       => $driver2->id,
            'plate_number'    => '5-99887',
            'brand'           => 'Hyundai',
            'model'           => 'H1',
            'year'            => '2022',
            'color'           => 'Silver',
            'type'            => 'Van',
            'capacity_manual' => 2,
            'is_verified'     => 1,
            'has_ac'          => 1,
            'status'          => 'Active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // ظ…ظ‚ط§ط¹ط¯ ط§ظ„ط³ط§ط¦ظ‚ 2 (ظ…طھط§ط­ط© ط¨ط§ظ„ظƒط§ظ…ظ„ 2/2)
        DriverSeatSlot::create([
            'driver_id'      => $driver2->id,
            'slot'           => DriverSeatSlot::MORNING_GO,
            'total_seats'    => 2,
            'reserved_seats' => 0,
        ]);

        // =========================================================================
        // 4. ط¥ظ†ط´ط§ط، ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ط§ظ„ط´ط§ظ…ظ„ ظˆط§ظ„ط§ط®طھط¨ط§ط±ط§طھ
        // =========================================================================
        $userParent = User::create([
            'full_name'     => 'ط§ظ„ط£ط³طھط§ط° ط£ط­ظ…ط¯ ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ط§ظ„ط§ط®طھط¨ط§ط±ظٹ',
            'email'         => 'parent.testing@darby.test',
            'phone_number'  => '0921111111',
            'password_hash' => Hash::make('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $parentModel = ParentModel::create([
            'user_id'    => $userParent->id,
            'is_trusted' => 1,
        ]);

        // ط¹ظ†ط§ظˆظٹظ† ط§ط®طھط¨ط§ط±ظٹط© ظ„ط±ط¨ط· request_children
        $homeAddressId = DB::table('addresses')->insertGetId([
            'parent_id'  => $userParent->id,
            'label'      => 'ظ…ظ†ط²ظ„ ط§ظ„ط§ط®طھط¨ط§ط± ط§ظ„ط±ط¦ظٹط³ظٹط©',
            'lat'        => 32.8840,
            'lng'        => 13.1870,
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $schoolAddressId = DB::table('addresses')->insertGetId([
            'parent_id'  => $userParent->id,
            'label'      => 'ظ…ط¯ط±ط³ط© ط§ظ„ط§ط®طھط¨ط§ط± ط§ظ„ط±ط¦ظٹط³ظٹط©',
            'lat'        => 32.8870,
            'lng'        => 13.1910,
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // -------------------------------------------------------------------------
        // ط§ظ„ط­ط§ظ„ط© A: ط§ظ„ط·ظپظ„ 1 (ط¹ظ„ظٹ) â€” ط§ط´طھط±ط§ظƒ ظ†ط´ط· ظˆظ…ط³ط§ط± ظˆط±ط­ظ„ط§طھ ط³ط§ط¨ظ‚ط© ظ„ظ„ط³ط§ط¦ظ‚ 1
        // -------------------------------------------------------------------------
        $child1 = Child::create([
            'parent_id'  => $parentModel->id,
            'school_id'  => $schoolId,
            'full_name'  => 'ط¹ظ„ظٹ ط£ط­ظ…ط¯ (ط§ط´طھط±ط§ظƒ ظ†ط´ط· ظ…ط³ط¨ظ‚ط§ظ‹)',
            'gender'     => 'male',
            'grade'      => 4,
            'birth_date' => '2016-03-15',
        ]);

        // 1. ط¥ظ†ط´ط§ط، ط§ظ„ط·ظ„ط¨ ط§ظ„ظ…ظ‚ط¨ظˆظ„ ط£ظˆظ„ط§ظ‹ ظ„ظ„ط§ط´طھط±ط§ظƒ ط§ظ„ظ†ط´ط·
        $acceptedReq = SubscriptionRequest::create([
            'parent_id'                => $parentModel->id,
            'driver_id'                => $driver1->id,
            'school_id'                => $schoolId,
            'timing'                   => 'MORNING',
            'direction'                => 'both',
            'status'                   => 'accepted',
            'subscription_type' => 'multi_day',
            'children_count'           => 1,
        ]);

        // 2. ط¹ظ‚ط¯ ط§ظ„ط·ظپظ„ 1 ظ…ط¹ ط§ظ„ط³ط§ط¦ظ‚ 1
        $contract1 = Contract::create([
            'subscription_request_id' => $acceptedReq->id,
            'parent_id'               => $userParent->id,
            'driver_id'               => $userDriver1->id,
            'contract_number'         => 'DRBY-ACTIVE-001',
            'subscription_type' => 'multi_day',
            'direction'               => 'both',
            'timing'                  => 'MORNING',
            'pickup_time'             => '07:00:00',
            'dropoff_time'            => '14:00:00',
            'max_waiting_time'        => 15,
            'start_date'              => now()->subDays(10)->format('Y-m-d'),
            'end_date'                => now()->addDays(20)->format('Y-m-d'),
            'days_count'              => 22,
            'total_price'             => 250.00,
            'clauses'                 => [],
            'status'                  => 'active',
        ]);

        // ظ…ط³ط§ط± ط§ظ„ط³ط§ط¦ظ‚ 1 ط§ظ„ظ†ط´ط·
        $route1 = RouteModel::create([
            'contract_id'        => $contract1->id,
            'driver_id'          => $driver1->id,
            'vehicle_id'         => $vehicle1Id,
            'route_name'         => 'ظ…ط³ط§ط± ط·ط±ط§ط¨ظ„ط³ ط§ظ„طµط¨ط§ط­ظٹ (ظ†ط´ط·)',
            'route_type'         => 'Morning',
            'start_time'         => '07:00:00',
            'total_distance'     => 8.50,
            'estimated_duration' => 25,
            'optimized_points'   => json_encode([
                [
                    'node_id'      => 'NODE_ALI_PICKUP',
                    'child_id'     => $child1->id,
                    'type'         => 'pickup',
                    'lat'          => 32.8820,
                    'lng'          => 13.1850,
                    'service_time' => 3,
                    'bell_time'    => '07:45',
                    'status'       => 'pending',
                ],
                [
                    'node_id'      => 'NODE_ALI_DROPOFF',
                    'child_id'     => $child1->id,
                    'type'         => 'dropoff',
                    'lat'          => 32.8870,
                    'lng'          => 13.1910,
                    'service_time' => 2,
                    'bell_time'    => '07:45',
                    'status'       => 'pending',
                ]
            ]),
            'status'             => 'Active',
        ]);

        // ط±ط¨ط· ط§ط´طھط±ط§ظƒ ط§ظ„ط·ظپظ„ 1 ط¨ط§ظ„ظ…ط³ط§ط± 1
        ActiveSubscription::create([
            'contract_id'        => $contract1->id,
            'parent_id'          => $userParent->id,
            'driver_id'          => $driver1->id,
            'child_id'           => $child1->id,
            'route_id'           => $route1->id,
            'pickup_lat'         => 32.8820,
            'pickup_lng'         => 13.1850,
            'dropoff_lat'        => 32.8870,
            'dropoff_lng'        => 13.1910,
            'pickup_time'        => '07:05:00',
            'dropoff_time'       => '07:25:00',
            'status'             => 'active',
        ]);

        // ط±ط­ظ„ط© ط³ط§ط¨ظ‚ط© ظˆظ…ظƒطھظ…ظ„ط© ظ„ظ„ط³ط§ط¦ظ‚ 1
        Trip::create([
            'route_id'             => $route1->id,
            'driver_id'            => $driver1->id,
            'trip_type'            => 'Morning',
            'trip_date'            => now()->subDay()->format('Y-m-d'),
            'scheduled_start_time' => '07:00:00',
            'actual_start_time'    => '07:02:00',
            'status'               => 'completed',
        ]);

        // -------------------------------------------------------------------------
        // ط§ظ„ط­ط§ظ„ط© B: ط§ظ„ط·ظپظ„ 2 (ط¹ظ…ط±) â€” ط·ظ„ط¨ ظ…ط¹ظ„ظ‚ ظ‚ظٹط§ط³ظٹ ظ…ط·ط¨ظ‚ ط¹ظ„ظ‰ ط§ظ„ط³ط§ط¦ظ‚ 1 (ط­ط§ظ„ط© ظ†ط¬ط§ط­)
        // -------------------------------------------------------------------------
        $child2 = Child::create([
            'parent_id'  => $parentModel->id,
            'school_id'  => $schoolId,
            'full_name'  => 'ط¹ظ…ط± ط£ط­ظ…ط¯ (ط·ظ„ط¨ ظ…ط¹ظ„ظ‚ - ظ…ظˆط§ظپظ‚ ط§ظ„ظ‚ظٹظˆط¯ ظˆط§ظ„ظ…ط³ط§ط±)',
            'gender'     => 'male',
            'grade'      => 2,
            'birth_date' => '2018-06-10',
        ]);

        $req2 = SubscriptionRequest::create([
            'parent_id'                => $parentModel->id,
            'driver_id'                => $driver1->id,
            'school_id'                => $schoolId,
            'subscription_type' => 'multi_day',
            'direction'                => 'both',
            'timing'                   => 'MORNING',
            'start_date'               => now()->addDays(2)->format('Y-m-d'),
            'end_date'                 => now()->addDays(32)->format('Y-m-d'),
            'days_count'               => 22,
            'total_price'              => 200.00,
            'status'                   => 'pending',
            'notes'                    => 'ط·ظ„ط¨ ظ‚ظٹط§ط³ظٹ ظ„ظ„ط§ط®طھط¨ط§ط± ظˆط§ظ„طھظˆطµظٹط©',
            'children_count'           => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'         => $req2->id,
            'child_id'           => $child2->id,
            'pickup_address_id'  => $homeAddressId,
            'dropoff_address_id' => $schoolId,
            'home_lat'           => 32.8840,
            'home_lng'           => 13.1870,
            'home_label'         => 'ط¨ظٹطھ ط¹ظ…ط±',
            'school_lat'         => 32.8870,
            'school_lng'         => 13.1910,
            'school_label'       => 'ظ…ط¯ط±ط³ط© ط·ط±ط§ط¨ظ„ط³',
            'price_per_child'    => 200.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // -------------------------------------------------------------------------
        // ط§ظ„ط­ط§ظ„ط© C: ط§ظ„ط·ظپظ„ 3 (ظپط§ط·ظ…ط©) â€” ط·ظ„ط¨ ظ…ط¹ظ„ظ‚ ظٹط·ظ„ط¨ ظ…ط³ط§ط¦ظٹ ظ…ظ† ط³ط§ط¦ظ‚ 2 ظ„ط§ ظٹط¹ظ…ظ„ ظ…ط³ط§ط¦ظٹ
        // -------------------------------------------------------------------------
        $child3 = Child::create([
            'parent_id'  => $parentModel->id,
            'school_id'  => $schoolId,
            'full_name'  => 'ظپط§ط·ظ…ط© ط£ط­ظ…ط¯ (ط·ظ„ط¨ ظ…ط¹ظ„ظ‚ - ظپطھط±ط© ط؛ظٹط± ظ…ط¯ط¹ظˆظ…ط© ظ„ط¯ظ‰ ط§ظ„ط³ط§ط¦ظ‚)',
            'gender'     => 'female',
            'grade'      => 5,
            'birth_date' => '2015-09-20',
        ]);

        $req3 = SubscriptionRequest::create([
            'parent_id'                => $parentModel->id,
            'driver_id'                => $driver2->id, // ط§ظ„ط³ط§ط¦ظ‚ 2 ظٹط´طھط؛ظ„ طµط¨ط§ط­ظٹ ط°ظ‡ط§ط¨ ظپظ‚ط·
            'school_id'                => $schoolId,
            'subscription_type' => 'multi_day',
            'direction'                => 'both',
            'timing'                   => 'EVENING', // ط·ظ„ط¨ ظ…ط³ط§ط¦ظٹ!
            'start_date'               => now()->addDays(2)->format('Y-m-d'),
            'end_date'                 => now()->addDays(32)->format('Y-m-d'),
            'days_count'               => 22,
            'total_price'              => 220.00,
            'status'                   => 'pending',
            'notes'                    => 'ط§ط®طھط¨ط§ط± ط±ظپط¶ ط§ظ„ظپظ„ط§طھط± ط¨ط³ط¨ط¨ ط§ظ„ظپطھط±ط©',
            'children_count'           => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'         => $req3->id,
            'child_id'           => $child3->id,
            'pickup_address_id'  => $homeAddressId,
            'dropoff_address_id' => $schoolId,
            'home_lat'           => 32.8750,
            'home_lng'           => 13.1750,
            'price_per_child'    => 220.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // -------------------------------------------------------------------------
        // ط§ظ„ط­ط§ظ„ط© D: ط§ظ„ط£ط·ظپط§ظ„ 4 ظˆ 5 (ط³ط§ط±ط© ظˆط­ط³ظ†) â€” ط·ظ„ط¨ ظ…طھط¹ط¯ط¯ ط£ط·ظپط§ظ„ ط¨طھط¬ط§ظˆط² ط§ظ„ظ…ظ‚ط§ط¹ط¯ ظˆظ‚ط¨ظˆظ„ ظپط±ط¯ظٹ
        // -------------------------------------------------------------------------
        $child4 = Child::create([
            'parent_id'  => $parentModel->id,
            'school_id'  => $schoolId,
            'full_name'  => 'ط³ط§ط±ط© ط£ط­ظ…ط¯ (ط·ظ„ط¨ ط£ط·ظپط§ظ„ ظ…طھط¹ط¯ط¯ظٹظ† - 1)',
            'gender'     => 'female',
            'grade'      => 1,
            'birth_date' => '2019-01-01',
        ]);

        $child5 = Child::create([
            'parent_id'  => $parentModel->id,
            'school_id'  => $schoolId,
            'full_name'  => 'ط­ط³ظ† ط£ط­ظ…ط¯ (ط·ظ„ط¨ ط£ط·ظپط§ظ„ ظ…طھط¹ط¯ط¯ظٹظ† - 2)',
            'gender'     => 'male',
            'grade'      => 3,
            'birth_date' => '2017-04-12',
        ]);

        $child6 = Child::create([
            'parent_id'  => $parentModel->id,
            'school_id'  => $schoolId,
            'full_name'  => 'ط²ظٹط§ط¯ ط£ط­ظ…ط¯ (ط·ظ„ط¨ ط£ط·ظپط§ظ„ ظ…طھط¹ط¯ط¯ظٹظ† - 3)',
            'gender'     => 'male',
            'grade'      => 6,
            'birth_date' => '2014-08-11',
        ]);

        $req4 = SubscriptionRequest::create([
            'parent_id'                => $parentModel->id,
            'driver_id'                => $driver2->id, // ط§ظ„ط³ط§ط¦ظ‚ 2 ظ„ط¯ظٹظ‡ ظ…ظ‚ط¹ط¯ظٹظ† ظ…طھط§ط­ظٹظ† ظپظ‚ط·!
            'school_id'                => $schoolId,
            'subscription_type' => 'multi_day',
            'direction'                => 'go',
            'timing'                   => 'MORNING',
            'start_date'               => now()->addDays(2)->format('Y-m-d'),
            'end_date'                 => now()->addDays(32)->format('Y-m-d'),
            'days_count'               => 22,
            'total_price'              => 500.00,
            'status'                   => 'pending',
            'notes'                    => 'ط·ظ„ط¨ 3 ط£ط·ظپط§ظ„ ظ„ط³ط§ط¦ظ‚ ظ„ط¯ظٹظ‡ ظ…ظ‚ط¹ط¯ط§ظ† ط¨ظˆط¶ط¹ ظ‚ط¨ظˆظ„ ظپط±ط¯ظٹ',
            'children_count'           => 3,
        ]);

        foreach ([$child4, $child5, $child6] as $ch) {
            DB::table('request_children')->insert([
                'request_id'         => $req4->id,
                'child_id'           => $ch->id,
                'pickup_address_id'  => $homeAddressId,
                'dropoff_address_id' => $schoolId,
                'home_lat'           => 32.8710,
                'home_lng'           => 13.1720,
                'price_per_child'    => 166.66,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }
}
