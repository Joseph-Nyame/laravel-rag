<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FileProcessor
{
    public function processFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $data = [];

        try {
            if ($extension === 'csv') {
                $handle = fopen($file->getPathname(), 'r');
                $headers = fgetcsv($handle);
                while ($row = fgetcsv($handle)) {
                    $data[] = array_combine($headers, $row);
                }
                fclose($handle);
            } elseif ($extension === 'txt') {
                $content = file_get_contents($file->getPathname());
                $data = array_filter(array_map('trim', explode("\n", $content)));
            } elseif ($extension === 'sql') {
                $content = file_get_contents($file->getPathname());
                $data = array_filter(array_map('trim', explode(';', $content)));
            } elseif ($extension === 'json') {
                $content = json_decode(file_get_contents($file->getPathname()), true);
                $data = is_array($content) ? $content : [$content];
            } else {
                throw new \Exception('Unsupported file type: ' . $extension);
            }

            return $data;
        } catch (\Exception $e) {
            Log::error("File parsing failed: {$e->getMessage()}", [
                'file' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }
}