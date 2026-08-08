<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Board;

class BoardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $boards = Board::all(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $boards,
        ]);
    }
}
