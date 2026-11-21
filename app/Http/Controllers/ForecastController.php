<?php

namespace App\Http\Controllers;

use App\Models\DailyForecast;
use App\Models\HourlyForecast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function index(Request $request): Collection
    {
        $validated = $request->validate([
            'resolution' => ['required', 'string', 'in:hourly,daily'],
        ]);

        if ($validated['resolution'] === 'hourly') {
            $query = HourlyForecast::query();
        } else {
            $query = DailyForecast::query();
        }

        return $query->orderByDesc('collected')
            ->orderBy('time')
            ->orderBy('id')
            ->get();
    }
}
