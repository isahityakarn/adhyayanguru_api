<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            'class_id' => ['nullable', 'exists:class_levels,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
        ]);

        $query = Subject::with(['classLevel', 'board']);

        // Filter by class_id if provided
        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        // Filter by board_id if provided
        if ($request->has('board_id')) {
            $query->where('board_id', $request->board_id);
        }

        $subjects = $query->get();

        return response()->json([
            'subjects' => $subjects->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'class' => [
                        'id' => $subject->classLevel->id,
                        'name' => $subject->classLevel->name,
                    ],
                    'board' => [
                        'id' => $subject->board->id,
                        'name' => $subject->board->name,
                    ],
                    'created_at' => $subject->created_at,
                ];
            }),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
