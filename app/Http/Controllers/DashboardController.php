<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $siteId = $request->input('site_id');

        if (empty($siteId)) {
            return response()->json(['message' => 'Pilih Site'], 400);
        }

        $devIds = DB::table('tm_device')
            ->where('site_id', $siteId)
            ->pluck('dev_id');

        if ($devIds->isEmpty()) {
            return response()->json(['message' => 'Site tidak ditemukan'], 404);
        }

        $temperatureData = $this->getTemperature($devIds);
        $humidityData = $this->getHumidity($devIds);
        $windData = $this->getWind($devIds);
        $luxData = $this->getLux($devIds);
        $rainData = $this->getRain($devIds);

        $lastUpdated = $this->getLastUpdatedDate($devIds);

        $plants = Plant::whereIn('dev_id', $devIds)->get()->map(function ($plant) {
            $commodityVariety = $plant->getCommodityVariety();

            return [
                'pl_id' => $plant->pl_id,
                'pl_name' => $plant->pl_name,
                'pl_desc' => $plant->pl_desc,
                'pl_date_planting' => $plant->pl_date_planting,
                'age' => $plant->age(),
                'phase' => $plant->phase(),
                'timeto_harvest' => $plant->timetoHarvest(),
                'pt_id' => $plant->pt_id,
                'commodity' => $commodityVariety['commodity'],
                'variety' => $commodityVariety['variety']
            ];
        });

        if ($plants->isEmpty()) {
            return response()->json(['message' => 'Tidak ada tanaman pada site ini'], 404);
        }

        $todos = [];
        foreach ($plants as $plant) {
            $plantTodos = DB::table('tr_plant_handling_copy')
                ->where('pt_id', $plant['pt_id'])
                ->where('hand_day', '>=', $plant['age'])
                ->get()
                ->map(function ($todo) use ($plant) {
                    // Menghitung tanggal kegiatan berdasarkan tanggal tanam dan hand_day
                    $plantDate = new \Carbon\Carbon($plant['pl_date_planting']);

                    // Menghitung tanggal kegiatan sesuai hand_day
                    $todoDate = $plantDate->copy()->addDays($todo->hand_day);

                    // Menghitung tanggal kegiatan dengan toleransi (hand_day + hand_day_toleran)
                    $tolerantDate = $plantDate->copy()->addDays($todo->hand_day + $todo->hand_day_toleran);

                    // Menghitung sisa hari menuju kegiatan 
                    $daysRemaining = $todo->hand_day - $plant['age'];
                    $daysTolerantRemaining = ($todo->hand_day + $todo->hand_day_toleran) - $plant['age'];

                    return [
                        'hand_title' => $todo->hand_title,
                        'hand_day' => $todo->hand_day,
                        'hand_day_toleran' => $todo->hand_day_toleran,
                        'fertilizer_type' => $todo->fertilizer_type,
                        'todo_date' => $todoDate->format('d-m-Y'),
                        'tolerant_date' => $tolerantDate->format('d-m-Y'),
                        'days_remaining' => $daysRemaining,
                        'days_tolerant_remaining' => $daysTolerantRemaining
                    ];
                });

            $todos[] = [
                'plant_id' => $plant['pl_id'],
                'todos' => $plantTodos
            ];
        }

        return response()->json(
            [
                'site_id' => $siteId,
                'temperature' => $temperatureData,
                'humidity' => $humidityData,
                'wind' => $windData,
                'lux' => $luxData,
                'rain' => $rainData,
                'plants' => $plants,
                'todos' => $todos,
                'last_updated' => $lastUpdated
            ],
            200,
            [],
            JSON_PRETTY_PRINT
        );
    }

    private function getLastUpdatedDate($devIds)
    {
        $latestReadDate = DB::table('tm_sensor_read')
            ->whereIn('dev_id', $devIds)
            ->max('read_date');

        return $latestReadDate ? \Carbon\Carbon::parse($latestReadDate)->format('d-m-Y H:i') : null;
    }

    
    // Fungsi untuk mengambil batas atas batas bawah dari sensor
    private function getSensorThresholds($ds_id)
    {
        $sensorThresholds = DB::table('td_device_sensors')
            ->where('ds_id', $ds_id)
            ->select('ds_min_norm_value', 'ds_max_norm_value', 'min_danger_action', 'max_danger_action')
            ->first();

        if (!$sensorThresholds) {
            Log::error("No thresholds found for sensor ID: $ds_id");
            return null;
        }

        return $sensorThresholds;
    }

    public function getSensorName($ds_id)
    {
        return DB::table('td_device_sensors')
            ->where('ds_id', $ds_id)
            ->value('ds_name');
    }

private function getSensorData($devIds, $sensors, $sensorType, $valueModifier = 1)
{

    $data = [];

    foreach ($sensors as $sensor) {
        $sensorData = DB::table('tm_sensor_read')
            ->select('ds_id', 'read_value', 'read_date')
            ->where('ds_id', $sensor)
            ->whereIn('dev_id', $devIds)
            ->orderBy('read_date', 'DESC')
            ->first();

        $sensorLimits = $this->getSensorThresholds($sensor);

        if (!$sensorLimits) {
            Log::warning("No thresholds found for sensor: $sensor");
            continue;
        }

        $minValue = $sensorLimits->ds_min_norm_value * $valueModifier;
        $maxValue = $sensorLimits->ds_max_norm_value * $valueModifier;
        $minDangerAct = $sensorLimits->min_danger_action;
        $maxDangerAct = $sensorLimits->max_danger_action;

        $valueStatus = '';
        $actionMessage = '';
        $statusMessage = '';
        $sensorName = $this->getSensorName($sensor);

        if ($sensorData) {
            $readValue = $sensorData->read_value * $valueModifier;

            if ($readValue >= $minValue && $readValue <= $maxValue) {
                $valueStatus = 'OK';
                $statusMessage = "$sensorType dalam kondisi normal";
            } elseif ($readValue < $minValue) {
                $valueStatus = 'Danger';
                $statusMessage = "$sensorType di bawah batas normal";
                $actionMessage = $minDangerAct;
            } elseif ($readValue > $maxValue) {
                $valueStatus = 'Danger';
                $statusMessage = "$sensorType di atas batas normal";
                $actionMessage = $maxDangerAct;
            } else {
                $valueStatus = 'Warning';
                $statusMessage = "$sensorType mendekati ambang batas";
                $actionMessage = "Periksa kondisi lebih lanjut untuk $sensorType.";
            }

            $readValue = $sensorData->read_value * $valueModifier;

            $data[] = [
                'sensor' => $sensor,
                'read_value' => $readValue,
                'read_date' => $sensorData->read_date ?? null,
                'value_status' => $valueStatus,
                'status_message' => $statusMessage,
                'action_message' => $actionMessage,
                'sensor_name' => $sensorName
            ];
        }
    }

    return $data;
}

public function getTemperature($devIds)
{
    $sensors = ['temp'];
    return $this->getSensorData($devIds, $sensors, 'Suhu Lingkungan');
}

public function getHumidity($devIds)
{
    $sensors = ['hum'];
    return $this->getSensorData($devIds, $sensors, 'Kelembapan Lingkungan');
}

public function getWind($devIds)
{
    $sensors = ['wind'];
    return $this->getSensorData($devIds, $sensors, 'Kecepatan Angin');
}

public function getLux($devIds)
{
    $sensors = ['ilum'];
    return $this->getSensorData($devIds, $sensors, 'Intensitas Cahaya', 100);
}

public function getRain($devIds)
{
    $sensors = ['rain'];
    return $this->getSensorData($devIds, $sensors, 'Hujan');
}
}
