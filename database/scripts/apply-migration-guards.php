<?php

/**
 * يضيف تحقق Schema::hasTable قبل كل Schema::create في ملفات المايغريشن.
 * تشغيل مرة عند الحاجة: php database/scripts/apply-migration-guards.php
 */
declare(strict_types=1);

$migrationDir = dirname(__DIR__).'/migrations';
$files = glob($migrationDir.'/*.php') ?: [];
$patched = 0;
$skipped = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        continue;
    }
    if (! preg_match('/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,/s', $content, $m)) {
        continue;
    }
    $table = $m[1];
    if (preg_match("/Schema::hasTable\s*\(\s*['\"]".preg_quote($table, '/')."['\"]/", $content)) {
        continue;
    }

    $guard = "        if (Schema::hasTable('{$table}')) {\n            return;\n        }\n\n        ";
    $patterns = [
        '/(public function up\(\)(?:\s*:\s*void)?\s*\{)\s*(Schema::create\s*\(\s*[\'"]'.preg_quote($table, '/').'[\'"]\s*,)/s',
    ];

    $newContent = $content;
    foreach ($patterns as $pattern) {
        $newContent = preg_replace($pattern, '$1'."\n".$guard.'$2', $content, 1, $count);
        if ($count > 0) {
            break;
        }
    }

    if ($newContent === $content || $newContent === null) {
        $skipped[] = basename($file)." (table: {$table})";

        continue;
    }

    file_put_contents($file, $newContent);
    $patched++;
}

echo "Patched {$patched} create migrations.\n";
if ($skipped !== []) {
    echo "Skipped (manual review):\n".implode("\n", $skipped)."\n";
}
