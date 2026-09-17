<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CallCenter;
use Illuminate\Support\Facades\File;

class CallCenterSeeder extends Seeder
{
    public function run()
    {
        $jsonPath = storage_path('app/private/call-center/data.json'); 

        if (!File::exists($jsonPath)) {
            return;
        }

        $data = json_decode(File::get($jsonPath), true);

        foreach ($data as $item) {
            CallCenter::updateOrCreate(
                ['ticket_code' => $item['id']], 
                [
                    'ticket_date' => $item['date'],
                    'business_unit' => $item['business_unit'],
                    'employee_id' => $item['employee_id'],
                    'name' => $item['name'],
                    'mobile' => $item['mobile'],
                    'complaint' => $item['complaint'],
                    'image_path' => $item['image_path'],
                    'status' => $item['status']
                ]
            );
        }
    }
}