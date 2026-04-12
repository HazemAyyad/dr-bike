<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CommonUse extends Controller
{
    public static function handleImageUpdate(Request $request, string $field, string $path, array $currentFiles = []): array
{
    $finalFiles = [];
    $keepFiles = [];
    $newFiles = [];

    // 1. Handle keeping existing images from request (they come as URLs or filenames)
    $requestItems = $request->input($field, []);
    foreach ($requestItems as $item) {
        if (is_string($item)) {
            $filename = basename($item); // extract filename if it's a URL
            if (in_array($filename, $currentFiles)) {
                $keepFiles[] = $filename;
            }
        }
    }

    // 2. Upload new files if any
    if ($request->hasFile($field)) {
        foreach ($request->file($field) as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $imageName =  $file->getClientOriginalName();
                $file->move(public_path($path), $imageName);
                $newFiles[] = $imageName;
            }
        }
    }

    // 3. Delete removed files from filesystem
    $removedFiles = array_diff($currentFiles, $keepFiles);
    foreach ($removedFiles as $oldFile) {
        $filePath = public_path($path . '/' . $oldFile);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // 4. Merge kept + new
    $finalFiles = array_merge($keepFiles, $newFiles);

    return $finalFiles;
}
}
