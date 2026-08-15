<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\ClassLevel;
use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource (public / student).
     */
    public function index(Request $request)
    {
        $request->validate([
            'class_id' => ['nullable', 'exists:class_levels,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
        ]);

        $query = Subject::with(['classLevel', 'board'])->withCount('chapters');

        // Filter by class_id if provided
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        // Filter by board_id if provided
        if ($request->filled('board_id')) {
            $query->where('board_id', $request->board_id);
        }

        $subjects = $query->orderBy('name')->get();

        return response()->json([
            'subjects' => $subjects->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'class' => [
                        'id' => $subject->classLevel->id ?? null,
                        'name' => $subject->classLevel->name ?? '',
                    ],
                    'board' => [
                        'id' => $subject->board->id ?? null,
                        'name' => $subject->board->name ?? '',
                    ],
                    'chapters_count' => $subject->chapters_count ?? 0,
                    'created_at' => $subject->created_at,
                ];
            }),
        ]);
    }

    /**
     * Admin listing with search, filtering, and pagination/full list.
     */
    public function adminIndex(Request $request)
    {
        $query = Subject::with(['classLevel', 'board'])->withCount('chapters');

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->filled('board_id')) {
            $query->where('board_id', $request->board_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhereHas('classLevel', function ($cq) use ($search) {
                        $cq->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('board', function ($bq) use ($search) {
                        $bq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        $subjects = $query->orderBy('class_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'subjects' => $subjects->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'class_id' => $subject->class_id,
                    'board_id' => $subject->board_id,
                    'class_name' => $subject->classLevel->name ?? 'Class ' . $subject->class_id,
                    'board_name' => $subject->board->name ?? 'CBSE',
                    'chapters_count' => $subject->chapters_count ?? 0,
                    'created_at' => $subject->created_at ? $subject->created_at->toIso8601String() : null,
                    'updated_at' => $subject->updated_at ? $subject->updated_at->toIso8601String() : null,
                ];
            }),
            'total' => $subjects->count(),
        ]);
    }

    /**
     * Store a newly created subject in database (Admin).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'class_id' => ['required', 'exists:class_levels,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
        ]);

        $boardId = $validated['board_id'] ?? 1; // Default to CBSE (id 1) if not specified

        // Check if subject already exists for this class and board
        $existing = Subject::where('class_id', $validated['class_id'])
            ->where('board_id', $boardId)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($validated['name']))])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Subject with this name already exists for the selected Class and Board.',
                'subject' => $existing,
            ], 422);
        }

        $subject = Subject::create([
            'name' => trim($validated['name']),
            'class_id' => $validated['class_id'],
            'board_id' => $boardId,
        ]);

        $subject->load(['classLevel', 'board']);

        return response()->json([
            'message' => 'Subject created successfully!',
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'class_id' => $subject->class_id,
                'board_id' => $subject->board_id,
                'class_name' => $subject->classLevel->name ?? '',
                'board_name' => $subject->board->name ?? '',
                'chapters_count' => 0,
                'created_at' => $subject->created_at,
            ],
        ], 201);
    }

    /**
     * Display the specified subject.
     */
    public function show(string $id)
    {
        $subject = Subject::with(['classLevel', 'board', 'chapters'])->withCount('chapters')->findOrFail($id);

        return response()->json([
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'class_id' => $subject->class_id,
                'board_id' => $subject->board_id,
                'class_name' => $subject->classLevel->name ?? '',
                'board_name' => $subject->board->name ?? '',
                'chapters_count' => $subject->chapters_count,
                'chapters' => $subject->chapters->map(function ($ch) {
                    return [
                        'id' => $ch->id,
                        'chapter_number' => $ch->chapter_number,
                        'title' => $ch->title,
                        'has_extracted_text' => ! empty($ch->extracted_text),
                        'has_pdf' => ! empty($ch->source_file_url),
                    ];
                }),
                'created_at' => $subject->created_at,
                'updated_at' => $subject->updated_at,
            ],
        ]);
    }

    /**
     * Update the specified subject in storage.
     */
    public function update(Request $request, string $id)
    {
        $subject = Subject::findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'class_id' => ['required', 'exists:class_levels,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
        ]);

        $boardId = $validated['board_id'] ?? $subject->board_id ?? 1;

        // Check duplicate name on other records
        $duplicate = Subject::where('class_id', $validated['class_id'])
            ->where('board_id', $boardId)
            ->where('id', '!=', $subject->id)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($validated['name']))])
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'Another subject with this name already exists for this Class and Board.',
            ], 422);
        }

        $subject->update([
            'name' => trim($validated['name']),
            'class_id' => $validated['class_id'],
            'board_id' => $boardId,
        ]);

        $subject->load(['classLevel', 'board']);
        $subject->loadCount('chapters');

        return response()->json([
            'message' => 'Subject updated successfully!',
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'class_id' => $subject->class_id,
                'board_id' => $subject->board_id,
                'class_name' => $subject->classLevel->name ?? '',
                'board_name' => $subject->board->name ?? '',
                'chapters_count' => $subject->chapters_count ?? 0,
                'created_at' => $subject->created_at,
                'updated_at' => $subject->updated_at,
            ],
        ]);
    }

    /**
     * Remove the specified subject from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $subject = Subject::withCount('chapters')->findOrFail($id);

        // If subject has chapters, prevent accidental deletion unless force is set
        if ($subject->chapters_count > 0 && ! $request->boolean('force')) {
            return response()->json([
                'message' => "Cannot delete subject '{$subject->name}' because it contains {$subject->chapters_count} chapter(s). Please delete the chapters first or confirm forced deletion.",
                'chapters_count' => $subject->chapters_count,
            ], 409);
        }

        // If force deleting, delete associated chapters or let DB cascade
        if ($subject->chapters_count > 0 && $request->boolean('force')) {
            foreach ($subject->chapters as $chapter) {
                $chapter->questions()->delete();
                $chapter->pages()->delete();
                $chapter->topics()->delete();
                $chapter->delete();
            }
        }

        $subject->delete();

        return response()->json([
            'message' => "Subject '{$subject->name}' deleted successfully.",
        ]);
    }
}
