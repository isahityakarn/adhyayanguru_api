<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use Illuminate\Http\Request;

class ChapterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
        ]);

        $chapters = Chapter::with(['subject', 'topics'])
            ->where('subject_id', $request->subject_id)
            ->orderBy('chapter_number')
            ->get();

        return response()->json([
            'chapters' => $chapters->map(function ($chapter) {
                return [
                    'id' => $chapter->id,
                    'chapter_number' => $chapter->chapter_number,
                    'title' => $chapter->title,
                    'description' => $chapter->description,
                    'source_file_url' => $chapter->source_file_url,
                    'subject' => [
                        'id' => $chapter->subject->id,
                        'name' => $chapter->subject->name,
                    ],
                    'topics_count' => $chapter->topics->count(),
                    'created_at' => $chapter->created_at,
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
    public function show(Request $request, string $id)
    {
        $user = $request->user();

        if (! $user || ! $user->studentProfile) {
            return response()->json([
                'message' => 'Student profile not found.',
            ], 403);
        }

        $chapter = Chapter::with(['subject.classLevel', 'topics'])->findOrFail($id);

        // Check if the chapter's subject class matches the user's class
        if ($chapter->subject->class_id !== $user->studentProfile->class_id) {
            return response()->json([
                'message' => 'You do not have access to this chapter.',
            ], 403);
        }

        // Build the full source file URL
        $sourceFileUrl = null;
        if ($chapter->source_file_url) {
            $baseUrl = rtrim(config('app.url'), '/');
            $classId = $chapter->subject->class_id;
            $subjectId = $chapter->subject_id;
            $sourceFileUrl = "{$baseUrl}/{$classId}/{$subjectId}/{$chapter->source_file_url}";
        }

        return response()->json([
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'description' => $chapter->description,
                'source_file_url' => $sourceFileUrl,
                'subject' => [
                    'id' => $chapter->subject->id,
                    'name' => $chapter->subject->name,
                ],
                'class' => [
                    'id' => $chapter->subject->classLevel->id,
                    'name' => $chapter->subject->classLevel->name,
                ],
                'topics_count' => $chapter->topics->count(),
                'created_at' => $chapter->created_at,
                'updated_at' => $chapter->updated_at,
            ],
        ]);
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
