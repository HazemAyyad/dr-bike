<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\EmployeeSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeSignatureService
{
    public function create(
        EmployeeDetail $employee,
        string $name,
        string $source,
        string $dataUrl,
        bool $makeDefault = false
    ): EmployeeSignature {
        [$original, $extension, $processed] = $this->prepare($dataUrl);
        $uuid = (string) Str::uuid();
        $originalRelative = 'public/EmployeeSignatures/Originals/'.$uuid.'.'.$extension;
        $processedRelative = 'public/EmployeeSignatures/Processed/'.$uuid.'.png';

        $this->putPublicFile($originalRelative, $original);
        $this->putPublicFile($processedRelative, $processed);

        try {
            return DB::transaction(function () use (
                $employee,
                $name,
                $source,
                $originalRelative,
                $processedRelative,
                $processed,
                $makeDefault
            ) {
                $existing = EmployeeSignature::query()
                    ->where('employee_id', $employee->id)
                    ->lockForUpdate()
                    ->get();
                $isDefault = $makeDefault || $existing->isEmpty();
                if ($isDefault) {
                    EmployeeSignature::query()
                        ->where('employee_id', $employee->id)
                        ->update(['is_default' => false]);
                }

                return EmployeeSignature::create([
                    'employee_id' => $employee->id,
                    'name' => trim($name),
                    'source' => $source,
                    'original_path' => $originalRelative,
                    'processed_path' => $processedRelative,
                    'signature_hash' => hash('sha256', $processed),
                    'is_default' => $isDefault,
                    'approved_at' => now(),
                ]);
            }, 3);
        } catch (\Throwable $error) {
            File::delete($this->absolutePath($originalRelative));
            File::delete($this->absolutePath($processedRelative));
            throw $error;
        }
    }

    public function makeDefault(EmployeeDetail $employee, EmployeeSignature $signature): EmployeeSignature
    {
        $this->assertOwned($employee, $signature);

        return DB::transaction(function () use ($employee, $signature) {
            EmployeeSignature::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->get();
            EmployeeSignature::query()
                ->where('employee_id', $employee->id)
                ->update(['is_default' => false]);
            $signature->update(['is_default' => true]);

            return $signature->fresh();
        }, 3);
    }

    public function delete(EmployeeDetail $employee, EmployeeSignature $signature): void
    {
        $this->assertOwned($employee, $signature);
        DB::transaction(function () use ($employee, $signature) {
            $wasDefault = (bool) $signature->is_default;
            $signature->delete();
            if ($wasDefault) {
                $replacement = EmployeeSignature::query()
                    ->where('employee_id', $employee->id)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                $replacement?->update(['is_default' => true]);
            }
        }, 3);
    }

    /** @return array{path:string,original_path:string,hash:string,name:string,source:string,id:int|null} */
    public function snapshotStored(EmployeeDetail $employee, EmployeeSignature $signature): array
    {
        $this->assertOwned($employee, $signature);
        $processed = File::get($this->absolutePath($signature->processed_path));

        return $this->writeReceiptSnapshot(
            $processed,
            $signature->name,
            'stored',
            $signature->id,
            $signature->original_path
        );
    }

    /** @return array{path:string,original_path:string,hash:string,name:string,source:string,id:int|null} */
    public function snapshotInline(string $dataUrl, string $name, string $source): array
    {
        [$original, $extension, $processed] = $this->prepare($dataUrl);
        $originalRelative = 'public/SalaryReceipts/SignatureOriginals/'.Str::uuid().'.'.$extension;
        $this->putPublicFile($originalRelative, $original);

        return $this->writeReceiptSnapshot($processed, $name, $source, null, $originalRelative);
    }

    /** @return array{0:string,1:string,2:string} */
    private function prepare(string $dataUrl): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages([
                'signature' => ['معالجة التوقيع غير متاحة على السيرفر.'],
            ]);
        }

        $encoded = preg_replace('/^data:image\/(png|jpe?g|webp);base64,/i', '', trim($dataUrl), 1, $count);
        if ($count !== 1) {
            throw ValidationException::withMessages([
                'signature' => ['صيغة صورة التوقيع غير مدعومة.'],
            ]);
        }
        $binary = base64_decode(str_replace(' ', '+', (string) $encoded), true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'signature' => ['صورة التوقيع غير صالحة أو كبيرة جدًا.'],
            ]);
        }
        $info = @getimagesizefromstring($binary);
        if (! is_array($info) || ($info[0] ?? 0) < 20 || ($info[1] ?? 0) < 20) {
            throw ValidationException::withMessages(['signature' => ['تعذر قراءة صورة التوقيع.']]);
        }
        if (($info[0] * $info[1]) > 24_000_000) {
            throw ValidationException::withMessages(['signature' => ['دقة صورة التوقيع كبيرة جدًا.']]);
        }
        $extension = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        return [$binary, $extension, $this->removeBackground($binary)];
    }

    private function removeBackground(string $binary): string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw ValidationException::withMessages(['signature' => ['تعذر معالجة صورة التوقيع.']]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 1600 / max($width, $height));
        if ($scale < 1) {
            $scaled = imagescale($source, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if ($scaled === false) {
                imagedestroy($source);
                throw ValidationException::withMessages(['signature' => ['تعذر تصغير صورة التوقيع.']]);
            }
            imagedestroy($source);
            $source = $scaled;
            $width = imagesx($source);
            $height = imagesy($source);
        }

        $corners = [[2, 2], [$width - 3, 2], [2, $height - 3], [$width - 3, $height - 3]];
        $background = [0, 0, 0];
        $transparentCorners = 0;
        foreach ($corners as [$x, $y]) {
            $rgba = imagecolorat($source, max(0, $x), max(0, $y));
            if ((($rgba >> 24) & 0x7f) > 100) {
                $transparentCorners++;
            }
            $background[0] += ($rgba >> 16) & 0xff;
            $background[1] += ($rgba >> 8) & 0xff;
            $background[2] += $rgba & 0xff;
        }
        $background = array_map(fn ($value) => $value / count($corners), $background);
        $backgroundLuma = .299 * $background[0] + .587 * $background[1] + .114 * $background[2];
        $transparentBackground = $transparentCorners >= 2;

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        $inkPixels = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($source, $x, $y);
                $red = ($rgba >> 16) & 0xff;
                $green = ($rgba >> 8) & 0xff;
                $blue = $rgba & 0xff;
                $sourceAlpha = ($rgba >> 24) & 0x7f;
                $distance = sqrt(
                    (($red - $background[0]) ** 2) +
                    (($green - $background[1]) ** 2) +
                    (($blue - $background[2]) ** 2)
                );
                $luma = .299 * $red + .587 * $green + .114 * $blue;
                $strength = $transparentBackground
                    ? (127 - $sourceAlpha) * 2
                    : max($distance * 3.2, max(0, $backgroundLuma - $luma) * 4.2);
                if ($strength < 34) {
                    continue;
                }
                $opacity = min(255, max(40, (int) round($strength)));
                $alpha = 127 - (int) round(($opacity / 255) * 127);
                imagesetpixel($canvas, $x, $y, imagecolorallocatealpha($canvas, $red, $green, $blue, $alpha));
                $inkPixels++;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
        imagedestroy($source);

        if ($inkPixels < 80 || $maxX < $minX || $maxY < $minY) {
            imagedestroy($canvas);
            throw ValidationException::withMessages([
                'signature' => ['لم يتم اكتشاف توقيع واضح في الصورة.'],
            ]);
        }

        $margin = 18;
        $minX = max(0, $minX - $margin);
        $minY = max(0, $minY - $margin);
        $maxX = min($width - 1, $maxX + $margin);
        $maxY = min($height - 1, $maxY + $margin);
        $cropWidth = $maxX - $minX + 1;
        $cropHeight = $maxY - $minY + 1;
        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagefill($cropped, 0, 0, imagecolorallocatealpha($cropped, 255, 255, 255, 127));
        imagecopy($cropped, $canvas, 0, 0, $minX, $minY, $cropWidth, $cropHeight);
        imagedestroy($canvas);

        ob_start();
        imagepng($cropped, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($cropped);

        return $png;
    }

    /** @return array{path:string,original_path:string,hash:string,name:string,source:string,id:int|null} */
    private function writeReceiptSnapshot(
        string $processed,
        string $name,
        string $source,
        ?int $id,
        string $originalPath
    ): array
    {
        $relative = 'public/SalaryReceipts/Signatures/'.Str::uuid().'.png';
        $this->putPublicFile($relative, $processed);

        return [
            'path' => $relative,
            'original_path' => $originalPath,
            'hash' => hash('sha256', $processed),
            'name' => trim($name),
            'source' => $source,
            'id' => $id,
        ];
    }

    private function putPublicFile(string $relative, string $contents): void
    {
        $path = $this->absolutePath($relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    private function absolutePath(string $relative): string
    {
        return public_path(preg_replace('#^public/#', '', $relative));
    }

    private function assertOwned(EmployeeDetail $employee, EmployeeSignature $signature): void
    {
        if ((int) $signature->employee_id !== (int) $employee->id) {
            abort(404);
        }
    }
}
