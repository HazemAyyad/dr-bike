<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class SubtaskAdminMediaStorage
{
    public static function store(UploadedFile $file): string
    {
        $dir = public_path('EmployeeSubTasks/AdminImages/');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safe = preg_replace('/[^\w\-]+/u', '_', $base) ?: 'file';
        $fullName = $safe.'_'.uniqid('', true).'.'.$ext;
        $file->move($dir, $fullName);

        return $fullName;
    }

    /**
     * @return list<string>
     */
    public static function collectFromRequest($request, int $index): array
    {
        $names = [];
        $key = "sub_employee_tasks.{$index}.admin_subtask__img";

        if (! $request->hasFile($key)) {
            return $names;
        }

        foreach ($request->file($key) as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $names[] = self::store($file);
            }
        }

        return $names;
    }
}
