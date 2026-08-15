<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

// Serve PDF files from storage
Route::get('/{class_id}/{subject_id}/{filename}', function ($classId, $subjectId, $filename) {
    $possiblePaths = [
        storage_path("{$classId}/{$subjectId}/{$filename}"),
        storage_path("app/{$classId}/{$subjectId}/{$filename}"),
        storage_path("app/public/{$classId}/{$subjectId}/{$filename}"),
        public_path("{$classId}/{$subjectId}/{$filename}"),
    ];

    $path = null;
    foreach ($possiblePaths as $testPath) {
        if (file_exists($testPath)) {
            $path = $testPath;
            break;
        }
    }

    if (! $path) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
    ]);
})->where(['class_id' => '[0-9]+', 'subject_id' => '[0-9]+', 'filename' => '.*\.pdf']);
