<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\ClassLevel;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class AdminDashboardController extends Controller
{
    public function dashboard()
    {
        $bytes = Chapter::whereNotNull('source_file_url')->with('subject')->get()->sum(fn ($chapter) => $this->fileSize($chapter));

        return $this->success([
            'total_classes' => ClassLevel::count(),
            'total_subjects' => Subject::count(),
            'total_chapters' => Chapter::count(),
            'total_pdfs' => Chapter::whereNotNull('source_file_url')->count(),
            'total_downloads' => 0,
            'storage_used' => $this->formatBytes($bytes),
            'storage_bytes' => $bytes,
        ]);
    }

    public function classes(Request $request)
    {
        $query = ClassLevel::query()->withCount(['subjects', 'subjects as chapters_count' => fn ($q) => $q->join('chapters', 'subjects.id', '=', 'chapters.subject_id')]);
        $query->withCount(['subjects as pdf_count' => fn ($q) => $q->join('chapters', 'subjects.id', '=', 'chapters.subject_id')->whereNotNull('chapters.source_file_url')]);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->string('search') . '%');
        }

        $sort = in_array($request->get('sort'), ['name', 'created_at', 'updated_at']) ? $request->get('sort') : 'name';
        $classes = $query->orderBy($sort, $request->get('direction') === 'desc' ? 'desc' : 'asc')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        $data = collect($classes->items())->map(fn ($class) => [
            'id' => $class->id,
            'name' => $class->name,
            'subject_count' => $class->subjects_count,
            'chapter_count' => $class->chapters_count,
            'pdf_count' => $class->pdf_count,
            'last_updated' => optional($class->updated_at)->toDateString(),
            'pdf_available' => $class->pdf_count > 0,
        ]);

        return $this->success($data, 'Classes fetched successfully', [
            'current_page' => $classes->currentPage(),
            'per_page' => $classes->perPage(),
            'total' => $classes->total(),
            'last_page' => $classes->lastPage(),
        ]);
    }

    public function classDetails($classId)
    {
        $class = ClassLevel::with('subjects')->findOrFail($classId);
        $chapters = Chapter::whereHas('subject', fn ($q) => $q->where('class_id', $class->id));

        return $this->success([
            'id' => $class->id,
            'name' => $class->name,
            'subject_count' => $class->subjects->count(),
            'chapter_count' => (clone $chapters)->count(),
            'pdf_count' => (clone $chapters)->whereNotNull('source_file_url')->count(),
            'subjects' => $class->subjects->map(fn ($subject) => $this->subjectData($subject)),
            'pdf_statistics' => [
                'available' => (clone $chapters)->whereNotNull('source_file_url')->count(),
                'missing' => (clone $chapters)->whereNull('source_file_url')->count(),
            ],
        ]);
    }

    public function updateClass(Request $request, $classId)
    {
        $class = ClassLevel::findOrFail($classId);
        $class->update($request->validate(['name' => ['required', 'string', 'max:20', 'unique:class_levels,name,' . $class->id]]));
        return $this->success($class, 'Class updated successfully');
    }

    public function subjects(Request $request, $classId)
    {
        abort_unless(ClassLevel::whereKey($classId)->exists(), 404);
        $query = Subject::where('class_id', $classId)->withCount('chapters')->withCount(['chapters as pdf_count' => fn ($q) => $q->whereNotNull('source_file_url')]);
        if ($request->filled('search')) $query->where('name', 'like', '%' . $request->string('search') . '%');
        $subjects = $query->orderBy($request->get('sort', 'name'), $request->get('direction') === 'desc' ? 'desc' : 'asc')->paginate(min((int) $request->get('per_page', 20), 100));

        return $this->success(collect($subjects->items())->map(fn ($subject) => $this->subjectData($subject)), 'Subjects fetched successfully', $this->pagination($subjects));
    }

    public function updateSubject(Request $request, $subjectId)
    {
        $subject = Subject::findOrFail($subjectId);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'class_id' => ['sometimes', 'exists:class_levels,id']]);
        $classId = $data['class_id'] ?? $subject->class_id;
        abort_if(Subject::where('id', '!=', $subject->id)->where('class_id', $classId)->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists(), 422, 'A subject with this name already exists for the class.');
        $subject->update($data);
        return $this->success($subject->fresh(), 'Subject updated successfully');
    }

    public function chapters(Request $request, $subjectId)
    {
        $query = Chapter::where('subject_id', $subjectId);
        if ($request->filled('search')) $query->where('title', 'like', '%' . $request->string('search') . '%');
        $chapters = $query->orderBy('chapter_number')->paginate(min((int) $request->get('per_page', 20), 100));
        return $this->success(collect($chapters->items())->map(fn ($chapter) => [
            'id' => $chapter->id, 'name' => $chapter->title, 'chapter_number' => $chapter->chapter_number,
            'pdf_count' => $chapter->source_file_url ? 1 : 0, 'last_updated' => optional($chapter->updated_at)->toDateString(),
        ]), 'Chapters fetched successfully', $this->pagination($chapters));
    }

    public function updateChapter(Request $request, $chapterId)
    {
        $chapter = Chapter::findOrFail($chapterId);
        $chapter->update($request->validate(['title' => ['sometimes', 'string', 'max:160'], 'chapter_number' => ['sometimes', 'integer', 'min:1'], 'description' => ['nullable', 'string']]));
        return $this->success($chapter->fresh(), 'Chapter updated successfully');
    }

    public function pdfs(Request $request)
    {
        $query = Chapter::with(['subject.classLevel'])->whereNotNull('source_file_url');
        foreach (['subject_id' => 'subject_id', 'chapter_id' => 'id'] as $filter => $column) {
            if (! $request->filled($filter)) continue;
            $query->where($column, $request->get($filter));
        }
        if ($request->filled('class_id')) $query->whereHas('subject', fn ($q) => $q->where('class_id', $request->get('class_id')));
        if ($request->filled('search')) $query->where('title', 'like', '%' . $request->string('search') . '%');
        if ($request->filled('status') && $request->get('status') === 'missing') $query->whereNull('source_file_url');
        $pdfs = $query->latest('updated_at')->paginate(min((int) $request->get('per_page', 20), 100));
        return $this->success(collect($pdfs->items())->map(fn ($chapter) => $this->pdfData($chapter)), 'PDFs fetched successfully', $this->pagination($pdfs));
    }

    public function preview($pdfId)
    {
        return $this->success($this->pdfData(Chapter::with('subject.classLevel')->findOrFail($pdfId)), 'PDF preview fetched successfully');
    }

    public function previewFile($pdfId)
    {
        $chapter = Chapter::with('subject')->findOrFail($pdfId);
        $path = $this->filePath($chapter);
        abort_unless($path && File::exists($path), 404, 'PDF file not found');
        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $chapter->title . '.pdf"']);
    }

    public function updatePdf(Request $request, $pdfId)
    {
        $chapter = Chapter::findOrFail($pdfId);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:160'], 'description' => ['nullable', 'string'], 'file' => ['sometimes', 'file', 'mimes:pdf', 'max:51200']]);
        if ($request->hasFile('file')) {
            $path = $this->filePath($chapter);
            if ($path) File::delete($path);
            $directory = storage_path($chapter->subject->class_id . '/' . $chapter->subject_id);
            File::ensureDirectoryExists($directory);
            $data['source_file_url'] = $request->file('file')->storeAs($chapter->subject->class_id . '/' . $chapter->subject_id, Str::slug($request->input('title', $chapter->title)) . '-' . Str::random(8) . '.pdf', 'local');
        }
        unset($data['file']);
        $chapter->update($data);
        return $this->success($this->pdfData($chapter->fresh('subject.classLevel')), 'PDF updated successfully');
    }

    public function deletePdf($pdfId)
    {
        $chapter = Chapter::findOrFail($pdfId);
        if ($path = $this->filePath($chapter)) File::delete($path);
        $chapter->delete();
        return response()->json(['success' => true, 'message' => 'PDF deleted successfully', 'data' => null]);
    }

    public function download($pdfId)
    {
        $chapter = Chapter::with('subject')->findOrFail($pdfId);
        $path = $this->filePath($chapter);
        abort_unless($path && File::exists($path), 404, 'PDF file not found');
        return response()->download($path, $chapter->title . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function bulkDownload(Request $request)
    {
        $request->validate(['pdf_ids' => ['required', 'array', 'min:1'], 'pdf_ids.*' => ['integer', 'exists:chapters,id']]);
        return $this->createZip(Chapter::whereIn('id', $request->input('pdf_ids'))->get(), 'selected-pdfs');
    }

    public function classBulkDownload($classId)
    {
        $chapters = Chapter::whereHas('subject', fn ($q) => $q->where('class_id', $classId))->whereNotNull('source_file_url')->get();
        return $this->createZip($chapters, 'class-' . $classId . '-pdfs');
    }

    public function subjectBulkDownload($subjectId)
    {
        return $this->createZip(Chapter::where('subject_id', $subjectId)->whereNotNull('source_file_url')->get(), 'subject-' . $subjectId . '-pdfs');
    }

    private function createZip($chapters, string $label)
    {
        $filename = $label . '-' . Str::random(12) . '.zip';
        $path = storage_path('app/admin-bulk/' . $filename);
        File::ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive();
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Could not create archive');
        foreach ($chapters as $chapter) if (($file = $this->filePath($chapter)) && File::exists($file)) $zip->addFile($file, $chapter->title . '.pdf');
        $zip->close();
        return $this->success(['download_url' => url('/api/admin/bulk-downloads/' . $filename), 'file_name' => $filename], 'Bulk archive created successfully');
    }

    public function bulkFile($filename)
    {
        abort_if(basename($filename) !== $filename || ! str_ends_with($filename, '.zip'), 404);
        $path = storage_path('app/admin-bulk/' . $filename);
        abort_unless(File::exists($path), 404, 'Archive not found');
        return response()->download($path, $filename, ['Content-Type' => 'application/zip']);
    }

    public function uploadPdf(Request $request, ChapterUploadController $uploader)
    {
        if ((!$request->filled('class_id') || $request->input('class_id') === '') && $request->filled('subject_id')) {
            $subject = Subject::find($request->input('subject_id'));
            if ($subject) {
                $request->merge(['class_id' => $subject->class_id]);
            }
        }

        if ($request->filled('chapter_id')) {
            $chapter = Chapter::with('subject')->find($request->input('chapter_id'));
            if ($chapter && $chapter->subject) {
                if (!$request->filled('subject_id') || $request->input('subject_id') === '') {
                    $request->merge(['subject_id' => $chapter->subject_id]);
                }
                if (!$request->filled('class_id') || $request->input('class_id') === '') {
                    $request->merge(['class_id' => $chapter->subject->class_id]);
                }
            }
        }

        if (!$request->filled('class_id') || $request->input('class_id') === '') {
            $firstClass = ClassLevel::orderBy('id')->first();
            if ($firstClass) {
                $request->merge(['class_id' => $firstClass->id]);
            }
        }

        if (!$request->filled('subject_id') || $request->input('subject_id') === '') {
            $classId = $request->input('class_id');
            $firstSubject = Subject::where('class_id', $classId)->first() ?: Subject::orderBy('id')->first();
            if ($firstSubject) {
                $request->merge(['subject_id' => $firstSubject->id, 'class_id' => $firstSubject->class_id]);
            }
        }

        $request->validate([
            'class_id' => ['required', 'exists:class_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'chapter_id' => ['nullable', 'exists:chapters,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'title' => ['sometimes', 'string', 'max:255'],
            'file' => ['sometimes', 'file', 'mimes:pdf', 'max:51200'],
            'pdf_file' => ['sometimes', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        if (!$request->hasFile('pdf_file') && count($request->allFiles()) > 0) {
            $allFiles = $request->allFiles();
            $request->files->set('pdf_file', reset($allFiles));
        }

        $title = $request->input('title', $request->input('name'));
        $chapterNumber = (int) ($request->input('chapter_number') ?: Chapter::where('subject_id', $request->input('subject_id'))->max('chapter_number') + 1);

        $request->merge([
            'title' => $title,
            'chapter_number' => $chapterNumber,
        ]);

        return $uploader->upload($request);
    }

    private function pdfData(Chapter $chapter): array
    {
        return ['id' => $chapter->id, 'name' => $chapter->title . '.pdf', 'class_id' => $chapter->subject->class_id, 'class_name' => $chapter->subject->classLevel->name ?? 'Class', 'subject_id' => $chapter->subject_id, 'subject_name' => $chapter->subject->name, 'chapter_id' => $chapter->id, 'chapter_name' => $chapter->title, 'file_size' => $this->formatBytes($this->fileSize($chapter)), 'status' => 'available', 'preview_url' => url('/api/admin/pdfs/' . $chapter->id . '/preview'), 'download_url' => url('/api/admin/pdfs/' . $chapter->id . '/download'), 'updated_at' => optional($chapter->updated_at)->toDateString()];
    }

    private function subjectData($subject): array { return ['id' => $subject->id, 'name' => $subject->name, 'chapters_count' => $subject->chapters_count ?? $subject->chapters()->count(), 'pdf_count' => $subject->pdf_count ?? $subject->chapters()->whereNotNull('source_file_url')->count()]; }
    private function filePath($chapter): ?string { if (! $chapter->source_file_url || ! $chapter->subject) return null; foreach ([storage_path($chapter->subject->class_id . '/' . $chapter->subject_id . '/' . $chapter->source_file_url), storage_path('app/' . $chapter->subject->class_id . '/' . $chapter->subject_id . '/' . $chapter->source_file_url)] as $path) if (File::exists($path)) return $path; return null; }
    private function fileSize($chapter): int { return ($path = $this->filePath($chapter)) ? File::size($path) : 0; }
    private function formatBytes($bytes): string { if ($bytes < 1024 ** 3) return number_format($bytes / 1024 ** 2, 1) . ' MB'; return number_format($bytes / 1024 ** 3, 1) . ' GB'; }
    private function pagination($p): array { return ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]; }
    private function success($data, string $message = 'Success', ?array $pagination = null) { $response = ['success' => true, 'message' => $message, 'data' => $data]; if ($pagination) $response['pagination'] = $pagination; return response()->json($response); }
}