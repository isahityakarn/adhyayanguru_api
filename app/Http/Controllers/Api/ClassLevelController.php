<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassLevel;

class ClassLevelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $classLevels = ClassLevel::all(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $classLevels,
        ]);
    }
}
