<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    /**
     * Track and accumulate time spent on a chapter by the authenticated user.
     */
    public function trackTime(Request $request)
    {
        $request->validate([
            'chapter_id' => ['required', 'exists:chapters,id'],
            'seconds' => ['nullable', 'integer', 'min:0'],
            'seconds_added' => ['nullable', 'integer', 'min:0'],
            'percent_complete' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed'],
        ]);

        $user = $request->user();
        $chapterId = $request->chapter_id;
        $seconds = (int) ($request->input('seconds') ?? $request->input('seconds_added') ?? 10);

        $progress = Progress::firstOrCreate(
            [
                'student_id' => $user->id,
                'chapter_id' => $chapterId,
            ],
            [
                'status' => 'in_progress',
                'percent_complete' => 0,
                'time_spent_seconds' => 0,
                'last_accessed_at' => now(),
            ]
        );

        $progress->time_spent_seconds += max(0, $seconds);
        $progress->last_accessed_at = now();

        if ($request->filled('percent_complete')) {
            $progress->percent_complete = max($progress->percent_complete, (int) $request->percent_complete);
        }

        if ($request->status === 'completed' || $progress->percent_complete >= 100) {
            $progress->status = 'completed';
            $progress->percent_complete = 100;
            if (!$progress->completed_at) {
                $progress->completed_at = now();
            }
        } elseif ($progress->time_spent_seconds > 0 && $progress->status !== 'completed') {
            $progress->status = 'in_progress';
        }

        $progress->save();
        $progress->load('chapter.subject');

        return response()->json([
            'message' => 'Chapter time tracked successfully.',
            'progress' => $progress,
        ]);
    }

    /**
     * Update status or completion percentage of a chapter.
     */
    public function updateProgress(Request $request)
    {
        $request->validate([
            'chapter_id' => ['required', 'exists:chapters,id'],
            'percent_complete' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed'],
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $chapterId = $request->chapter_id;

        $progress = Progress::firstOrCreate(
            [
                'student_id' => $user->id,
                'chapter_id' => $chapterId,
            ],
            [
                'status' => 'not_started',
                'percent_complete' => 0,
                'time_spent_seconds' => 0,
                'last_accessed_at' => now(),
            ]
        );

        if ($request->has('time_spent_seconds')) {
            $progress->time_spent_seconds = max(0, (int) $request->time_spent_seconds);
        }

        if ($request->has('percent_complete')) {
            $progress->percent_complete = (int) $request->percent_complete;
        }

        if ($request->has('status')) {
            $progress->status = $request->status;
        }

        if ($progress->status === 'completed' || $progress->percent_complete >= 100) {
            $progress->status = 'completed';
            $progress->percent_complete = 100;
            if (!$progress->completed_at) {
                $progress->completed_at = now();
            }
        }

        $progress->last_accessed_at = now();
        $progress->save();
        $progress->load('chapter.subject');

        return response()->json([
            'message' => 'Chapter progress updated successfully.',
            'progress' => $progress,
        ]);
    }

    /**
     * Get specific chapter progress for authenticated student.
     */
    public function getChapterProgress(Request $request, $chapterId)
    {
        $user = $request->user();
        $progress = Progress::with('chapter.subject')
            ->where('student_id', $user->id)
            ->where('chapter_id', $chapterId)
            ->first();

        if (!$progress) {
            return response()->json([
                'chapter_id' => (int) $chapterId,
                'status' => 'not_started',
                'percent_complete' => 0,
                'time_spent_seconds' => 0,
                'formatted_time_spent' => '0s',
                'completed_at' => null,
                'last_accessed_at' => null,
            ]);
        }

        return response()->json([
            'progress' => $progress,
        ]);
    }

    /**
     * Detailed parent report & summary of chapter time spent and completion stats.
     * Can be viewed by parent (specifying student_id) or logged-in student.
     */
    public function parentReport(Request $request)
    {
        $user = $request->user();
        $targetStudentId = $request->get('student_id');

        if ($targetStudentId && ($user->role === 'parent' || $user->role === 'admin' || $user->id == $targetStudentId)) {
            $student = User::find($targetStudentId);
        } else {
            $student = $user;
        }

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 444);
        }

        // Fetch all progress records for the student with chapter & subject info
        $progressList = Progress::with(['chapter.subject.classLevel'])
            ->where('student_id', $student->id)
            ->orderBy('last_accessed_at', 'desc')
            ->get();

        $totalTimeSeconds = (int) $progressList->sum('time_spent_seconds');
        $totalChaptersStarted = $progressList->where('status', '!=', 'not_started')->count();
        $totalChaptersCompleted = $progressList->where('status', 'completed')->count();

        // Format total time
        $hours = floor($totalTimeSeconds / 3600);
        $minutes = floor(($totalTimeSeconds % 3600) / 60);
        $seconds = $totalTimeSeconds % 60;

        $formattedTotalTime = $hours > 0
            ? "{$hours}h {$minutes}m {$seconds}s"
            : ($minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s");

        $chaptersSummary = $progressList->map(function ($p) {
            return [
                'id' => $p->id,
                'chapter_id' => $p->chapter_id,
                'chapter_title' => $p->chapter->title ?? "Chapter {$p->chapter_id}",
                'chapter_number' => $p->chapter->chapter_number ?? null,
                'subject_name' => $p->chapter->subject->name ?? 'General',
                'class_name' => $p->chapter->subject->classLevel->name ?? '',
                'status' => $p->status,
                'percent_complete' => $p->percent_complete,
                'time_spent_seconds' => $p->time_spent_seconds,
                'formatted_time_spent' => $p->formatted_time_spent,
                'completed_at' => $p->completed_at ? $p->completed_at->toIso8601String() : null,
                'last_accessed_at' => $p->last_accessed_at ? $p->last_accessed_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'role' => $student->role,
            ],
            'summary' => [
                'total_time_spent_seconds' => $totalTimeSeconds,
                'formatted_total_time_spent' => $formattedTotalTime,
                'total_chapters_started' => $totalChaptersStarted,
                'total_chapters_completed' => $totalChaptersCompleted,
            ],
            'chapters' => $chaptersSummary,
        ]);
    }
}
