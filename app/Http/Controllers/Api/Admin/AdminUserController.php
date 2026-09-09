<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    private function success(mixed $data, string $message = 'OK', array $pagination = []): \Illuminate\Http\JsonResponse
    {
        $response = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($pagination)) {
            $response['pagination'] = $pagination;
        }
        return response()->json($response);
    }

    /**
     * List all users with optional search, role filter, status filter,
     * sorting, and pagination.
     *
     * GET /api/admin/users
     * Requires: auth:sanctum + super_admin middleware
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Search by name, email, or phone
        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // Sorting
        $allowedSorts = ['name', 'email', 'role', 'status', 'created_at'];
        $sort      = in_array($request->get('sort'), $allowedSorts) ? $request->get('sort') : 'created_at';
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        $users = $query->orderBy($sort, $direction)
                       ->paginate(min((int) $request->get('per_page', 20), 100));

        $data = collect($users->items())->map(fn (User $user) => [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email ?? '—',
            'phone'         => $user->phone,
            'role'          => $user->role,
            'status'        => $user->status,
            'language_pref' => $user->language_pref,
            'created_at'    => optional($user->created_at)->toDateString(),
        ]);

        return $this->success($data, 'Users fetched successfully.', [
            'current_page' => $users->currentPage(),
            'per_page'     => $users->perPage(),
            'total'        => $users->total(),
            'last_page'    => $users->lastPage(),
        ]);
    }

    /**
     * Block or unblock a user.
     *
     * PATCH /api/admin/users/{id}/status
     */
    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:active,blocked',
        ]);

        $user = User::findOrFail($id);
        $user->status = $request->string('status');
        $user->save();

        return $this->success([
            'id'     => $user->id,
            'status' => $user->status,
        ], 'User status updated.');
    }

    /**
     * Return a student's subject completion and quiz marks for admin reporting.
     *
     * GET /api/admin/users/{id}/progress
     */
    public function progress(int $id)
    {
        $student = User::findOrFail($id);
        $progressByChapter = $student->progress()->get()->keyBy('chapter_id');
        $latestAttempts = QuizAttempt::where('student_id', $student->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->get()
            ->unique('chapter_id')
            ->keyBy('chapter_id');

        $classId = $student->studentProfile?->class_id;
        $subjects = Subject::with('chapters')
            ->when($classId, fn ($query) => $query->where('class_id', $classId))
            ->orderBy('name')
            ->get()
            ->map(function (Subject $subject) use ($progressByChapter, $latestAttempts) {
                $chapters = $subject->chapters->map(function ($chapter) use ($progressByChapter, $latestAttempts) {
                    $progress = $progressByChapter->get($chapter->id);
                    $attempt = $latestAttempts->get($chapter->id);

                    return [
                        'id' => $chapter->id,
                        'title' => $chapter->title,
                        'status' => $progress?->status ?? 'not_started',
                        'percent_complete' => (int) ($progress?->percent_complete ?? 0),
                        'marks' => $attempt ? (float) $attempt->total_score : null,
                        'max_marks' => $attempt ? (float) $attempt->max_score : null,
                    ];
                });
                $completedChapters = $chapters->where('status', 'completed')->count();

                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'completed' => $chapters->isNotEmpty() && $completedChapters === $chapters->count(),
                    'completed_chapters' => $completedChapters,
                    'total_chapters' => $chapters->count(),
                    'chapters' => $chapters->values(),
                ];
            })
            ->filter(fn ($subject) => $subject['total_chapters'] > 0)
            ->values();

        return $this->success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'summary' => [
                'completed_subjects' => $subjects->where('completed', true)->count(),
                'total_subjects' => $subjects->count(),
                'total_marks' => $latestAttempts->sum('total_score'),
                'max_marks' => $latestAttempts->sum('max_score'),
            ],
            'subjects' => $subjects,
        ], 'Student progress fetched successfully.');
    }
}
