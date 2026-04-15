<?php

/**
 * يضيف if (! Schema::hasColumn(...)) حول أسطر إضافة الأعمدة داخل Schema::table في up().
 * يتخطى الملفات التي فيها dropColumn / renameColumn / ->change( داخل up() فقط.
 *
 * تشغيل: php database/scripts/apply-table-column-guards.php
 */
declare(strict_types=1);

$migrationDir = dirname(__DIR__).'/migrations';
$files = glob($migrationDir.'/*.php') ?: [];

function extractUpMethodBody(string $content): ?string
{
    if (! preg_match('/public function up\(\)(?:\s*:\s*void)?\s*\{/s', $content, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($content);
    for ($i = $start; $i < $len; $i++) {
        $c = $content[$i];
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, $start, $i - $start);
            }
        }
    }

    return null;
}

function replaceUpMethodBody(string $content, string $newInnerBody): ?string
{
    if (! preg_match('/public function up\(\)(?:\s*:\s*void)?\s*\{/s', $content, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($content);
    for ($i = $start; $i < $len; $i++) {
        $c = $content[$i];
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, 0, $start).$newInnerBody.substr($content, $i);
            }
        }
    }

    return null;
}

function patchTableClosure(string $upBody, string $table): string
{
    $lines = preg_split('/\r\n|\n|\r/', $upBody);
    $out = [];
    $pending = '';

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '//')) {
            $out[] = $line;

            continue;
        }

        // دمج الأسطر المستمرة حتى ';'
        $combined = $pending.$line;
        if (! str_contains($combined, ';') && $trim !== '' && ! str_ends_with($trim, '{')) {
            $pending = $combined."\n";

            continue;
        }
        $pending = '';
        $work = $combined;

        if (preg_match('/Schema::table\s*\(/', $work) && ! str_contains($work, '$table->')) {
            $out[] = $line;

            continue;
        }

        // timestamps
        if (preg_match('/^\s*\$table->timestamps\(\)\s*;/', $work)) {
            $indent = preg_match('/^(\s*)/', $work, $im) ? $im[1] : '            ';
            $out[] = $indent."if (! Schema::hasColumn('{$table}', 'created_at') && ! Schema::hasColumn('{$table}', 'updated_at')) {";
            $out[] = $indent.'    $table->timestamps();';
            $out[] = $indent.'}';

            continue;
        }

        // id()
        if (preg_match('/^\s*\$table->id\(\)\s*;/', $work)) {
            $indent = preg_match('/^(\s*)/', $work, $im) ? $im[1] : '            ';
            $out[] = $indent."if (! Schema::hasColumn('{$table}', 'id')) {";
            $out[] = $indent.'    $table->id();';
            $out[] = $indent.'}';

            continue;
        }

        // $table->method('col' ... ;
        if (preg_match('/^\s*\$table->([a-zA-Z0-9_]+)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*[,)]/', $work, $mm)) {
            $col = $mm[2];
            if (str_contains($work, 'Schema::hasColumn')) {
                $out[] = $line;

                continue;
            }
            $indent = preg_match('/^(\s*)/', $work, $im) ? $im[1] : '            ';
            $inner = trim($work);
            $out[] = $indent."if (! Schema::hasColumn('{$table}', '{$col}')) {";
            $out[] = $indent.'    '.$inner;
            $out[] = $indent.'}';

            continue;
        }

        // double-quoted column name
        if (preg_match('/^\s*\$table->([a-zA-Z0-9_]+)\(\s*"((?:[^"\\\\]|\\\\.)*)"\s*[,)]/', $work, $mm)) {
            $col = $mm[2];
            if (str_contains($work, 'Schema::hasColumn')) {
                $out[] = $line;

                continue;
            }
            $indent = preg_match('/^(\s*)/', $work, $im) ? $im[1] : '            ';
            $inner = trim($work);
            $out[] = $indent.'if (! Schema::hasColumn(\''.$table.'\', "'.$col.'")) {';
            $out[] = $indent.'    '.$inner;
            $out[] = $indent.'}';

            continue;
        }

        $out[] = $line;
    }

    return implode("\n", $out);
}

$patched = 0;
$skipped = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false || ! str_contains($content, 'Schema::table')) {
        continue;
    }
    if (preg_match('/Schema::create\s*\(/s', $content) && preg_match('/public function up\(\)/s', $content)) {
        $up = extractUpMethodBody($content);
        if ($up !== null && str_contains($up, 'Schema::create')) {
            continue;
        }
    }

    $up = extractUpMethodBody($content);
    if ($up === null) {
        $skipped[] = basename($file).' (no up)';

        continue;
    }

    if (preg_match('/dropColumn|dropColumns|renameColumn|->change\s*\(/s', $up)) {
        $skipped[] = basename($file).' (drop/rename/change in up)';

        continue;
    }

    if (! preg_match("/Schema::table\s*\(\s*'([^']+)'/", $up, $tm)) {
        $skipped[] = basename($file).' (no Schema::table quote)';

        continue;
    }
    $table = $tm[1];

    if (str_contains($up, "Schema::hasColumn('{$table}'") || str_contains($up, 'Schema::hasColumn("'.$table.'"')) {
        continue;
    }

    $newUp = patchTableClosure($up, $table);
    if ($newUp === $up) {
        $skipped[] = basename($file).' (nothing to wrap)';

        continue;
    }

    $newContent = replaceUpMethodBody($content, $newUp);
    if ($newContent === null) {
        $skipped[] = basename($file).' (replace failed)';

        continue;
    }

    file_put_contents($file, $newContent);
    $patched++;
}

echo "Patched {$patched} table migrations.\n";
if ($skipped !== []) {
    echo "Skipped:\n".implode("\n", $skipped)."\n";
}
