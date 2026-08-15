<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class PdfExtractorService
{
    /**
     * Extract text from a PDF file URL or local path.
     */
    public function extractText(string $pdfPath): ?string
    {
        try {
            $parser = new Parser();

            // Check if it's a URL or local path
            if (filter_var($pdfPath, FILTER_VALIDATE_URL)) {
                return $this->extractFromUrl($pdfPath, $parser);
            }

            return $this->extractFromLocalPath($pdfPath, $parser);
        } catch (\Exception $e) {
            Log::error('PDF extraction error', [
                'path' => $pdfPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract text from a PDF URL.
     */
    private function extractFromUrl(string $url, Parser $parser): ?string
    {
        try {
            // Download the PDF content
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::warning('Failed to download PDF', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            // Save temporarily
            $tempPath = storage_path('app/temp/'.uniqid('pdf_', true).'.pdf');

            // Create temp directory if it doesn't exist
            if (! file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }

            file_put_contents($tempPath, $response->body());

            // Parse the PDF
            $pdf = $parser->parseFile($tempPath);
            $text = $pdf->getText();

            // Clean up
            @unlink($tempPath);

            return $this->cleanText($text);
        } catch (\Exception $e) {
            Log::error('PDF URL extraction error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract text from a local PDF file.
     */
    private function extractFromLocalPath(string $path, Parser $parser): ?string
    {
        try {
            // Try multiple possible locations
            $possiblePaths = [
                public_path($path),                    // public/5/1/file.pdf
                storage_path('app/'.$path),           // storage/app/5/1/file.pdf
                storage_path($path),                  // storage/5/1/file.pdf
                base_path($path),                     // from project root
            ];

            $fullPath = null;

            foreach ($possiblePaths as $testPath) {
                if (file_exists($testPath)) {
                    $fullPath = $testPath;
                    break;
                }
            }

            if (! $fullPath) {
                Log::warning('PDF file not found in any location', [
                    'path' => $path,
                    'tried_paths' => $possiblePaths,
                ]);

                return null;
            }

            Log::info('PDF file found', [
                'original_path' => $path,
                'full_path' => $fullPath,
            ]);

            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();

            return $this->cleanText($text);
        } catch (\Exception $e) {
            Log::error('PDF local extraction error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clean and normalize extracted text.
     */
    private function cleanText(string $text): string
    {
        // Fix UTF-8 encoding issues
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove invalid UTF-8 sequences
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        // Remove null bytes
        $text = str_replace("\0", '', $text);

        // Remove control characters except newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Remove common PDF artifacts and repeated words
        $artifacts = [
            'yojak chini',
            'YOJAK CHINI',
            'Yojak Chini',
            'yojak',
            'chini',
        ];

        foreach ($artifacts as $artifact) {
            // Remove the artifact and any repetitions (case-insensitive)
            $text = preg_replace('/\b'.preg_quote($artifact, '/').'\b\s*/ui', '', $text);
        }

        // Remove multiple underscores (formatting marks)
        $text = preg_replace('/_{3,}/u', '', $text);

        // Remove standalone ## symbols (likely page numbers or section markers)
        $text = preg_replace('/^##\s*$/m', '', $text);

        // Remove lines with only special characters or numbers
        $text = preg_replace('/^[\s\d\-_#]+$/m', '', $text);

        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove excessive whitespace (but keep single newlines)
        $text = preg_replace('/[ \t]+/u', ' ', $text);

        // Remove more than 2 consecutive newlines
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        // Trim each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);

        // Remove empty lines
        $lines = array_filter($lines, function ($line) {
            return $line !== '';
        });

        $text = implode("\n", $lines);

        // Remove empty lines at start and end
        $text = trim($text);

        // Limit to reasonable size (approximately 50KB of text)
        if (strlen($text) > 50000) {
            $text = mb_substr($text, 0, 50000, 'UTF-8').' [... text truncated for length]';
        }

        return $text;
    }

    /**
     * Extract text from PDF with page information.
     */
    public function extractTextByPages(string $pdfPath, ?int $maxPages = null): array
    {
        try {
            $parser = new Parser();

            // Download or load PDF
            if (filter_var($pdfPath, FILTER_VALIDATE_URL)) {
                $response = Http::timeout(30)->get($pdfPath);

                if (! $response->successful()) {
                    return [];
                }

                $tempPath = storage_path('app/temp/'.uniqid('pdf_', true).'.pdf');

                if (! file_exists(dirname($tempPath))) {
                    mkdir(dirname($tempPath), 0755, true);
                }

                file_put_contents($tempPath, $response->body());
                $pdf = $parser->parseFile($tempPath);
                @unlink($tempPath);
            } else {
                $possiblePaths = [
                    public_path($pdfPath),
                    storage_path('app/'.$pdfPath),
                    storage_path($pdfPath),
                    base_path($pdfPath),
                ];

                $fullPath = null;
                foreach ($possiblePaths as $testPath) {
                    if (file_exists($testPath)) {
                        $fullPath = $testPath;
                        break;
                    }
                }

                if (! $fullPath) {
                    return [];
                }

                $pdf = $parser->parseFile($fullPath);
            }

            $pages = $pdf->getPages();
            $result = [];
            $count = 0;

            foreach ($pages as $pageNumber => $page) {
                if ($maxPages && $count >= $maxPages) {
                    break;
                }

                $result[] = [
                    'page' => $pageNumber + 1,
                    'text' => $this->cleanText($page->getText()),
                ];

                $count++;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('PDF page extraction error', [
                'path' => $pdfPath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
