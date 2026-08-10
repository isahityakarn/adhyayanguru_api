<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

// Serve PDF files from storage
Route::get('/{class_id}/{subject_id}/{filename}', function ($classId, $subjectId, $filename) {
    $path = storage_path("{$classId}/{$subjectId}/{$filename}");

    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
    ]);
})->where(['class_id' => '[0-9]+', 'subject_id' => '[0-9]+', 'filename' => '.*\.pdf']);
