<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    /**
     * Display a listing of questions with optional filters.
     */
    public function index(Request $request)
    {
        $query = Question::with(['chapter.subject.classLevel']);

        if ($request->filled('chapter_id')) {
            $query->where('chapter_id', $request->chapter_id);
        }

        if ($request->filled('question_type')) {
            $query->where('question_type', $request->question_type);
        }

        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where('question_text', 'like', $search);
        }

        $questions = $query->latest()->paginate($request->input('per_page', 20));

        return response()->json($questions);
    }

    /**
     * Get all questions for a specific chapter (used by Quiz and chapter practice).
     */
    public function indexByChapter(Request $request, $chapterId)
    {
        $chapter = Chapter::with(['subject.classLevel'])->findOrFail($chapterId);

        $questions = Question::where('chapter_id', $chapterId)
            ->orderBy('id')
            ->get();

        return response()->json([
            'chapter' => [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'chapter_number' => $chapter->chapter_number,
                'subject' => $chapter->subject->name ?? '',
                'class' => $chapter->subject->classLevel->name ?? '',
            ],
            'questions' => $questions,
            'total' => $questions->count(),
        ]);
    }

    /**
     * Store a newly created question in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'exists:chapters,id'],
            'topic_id' => ['nullable', 'exists:topics,id'],
            'question_text' => ['required', 'string'],
            'question_type' => ['required', 'in:mcq,short_answer'],
            'options' => ['nullable', 'array'],
            'correct_answer' => ['required', 'string'],
            'difficulty' => ['required', 'in:easy,medium,hard'],
        ]);

        $question = Question::create($validated);

        // Update chapter questions JSON cache
        $chapter = Chapter::find($validated['chapter_id']);
        if ($chapter) {
            $chapter->questions = Question::where('chapter_id', $chapter->id)->get()->toJson();
            $chapter->save();
        }

        return response()->json([
            'message' => 'Question created successfully.',
            'question' => $question,
        ], 201);
    }

    /**
     * Display the specified question.
     */
    public function show(string $id)
    {
        $question = Question::with(['chapter.subject.classLevel'])->findOrFail($id);

        return response()->json([
            'question' => $question,
        ]);
    }

    /**
     * Update the specified question in storage.
     */
    public function update(Request $request, string $id)
    {
        $question = Question::findOrFail($id);

        $validated = $request->validate([
            'question_text' => ['sometimes', 'required', 'string'],
            'question_type' => ['sometimes', 'required', 'in:mcq,short_answer'],
            'options' => ['nullable', 'array'],
            'correct_answer' => ['sometimes', 'required', 'string'],
            'difficulty' => ['sometimes', 'required', 'in:easy,medium,hard'],
        ]);

        $question->update($validated);

        // Update chapter questions JSON cache
        $chapter = Chapter::find($question->chapter_id);
        if ($chapter) {
            $chapter->questions = Question::where('chapter_id', $chapter->id)->get()->toJson();
            $chapter->save();
        }

        return response()->json([
            'message' => 'Question updated successfully.',
            'question' => $question,
        ]);
    }

    /**
     * Remove the specified question from storage.
     */
    public function destroy(string $id)
    {
        $question = Question::findOrFail($id);
        $chapterId = $question->chapter_id;

        $question->delete();

        // Update chapter questions JSON cache
        $chapter = Chapter::find($chapterId);
        if ($chapter) {
            $chapter->questions = Question::where('chapter_id', $chapter->id)->get()->toJson();
            $chapter->save();
        }

        return response()->json([
            'message' => 'Question deleted successfully.',
        ]);
    }
}
