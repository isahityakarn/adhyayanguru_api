<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class PlanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $plans = Plan::all(['id', 'name', 'price_inr', 'duration_days', 'features']);

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }
}
