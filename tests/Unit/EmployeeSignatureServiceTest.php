<?php

namespace Tests\Unit;

use App\Services\EmployeeSignatureService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EmployeeSignatureServiceTest extends TestCase
{
    public function test_it_removes_white_background_and_crops_signature(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $source = imagecreatetruecolor(600, 300);
        $white = imagecolorallocate($source, 255, 255, 255);
        $ink = imagecolorallocate($source, 25, 35, 65);
        imagefill($source, 0, 0, $white);
        imagesetthickness($source, 9);
        imageline($source, 120, 170, 290, 95, $ink);
        imageline($source, 290, 95, 460, 175, $ink);
        imageline($source, 170, 205, 430, 205, $ink);

        ob_start();
        imagepng($source);
        $binary = (string) ob_get_clean();
        imagedestroy($source);

        $method = new ReflectionMethod(EmployeeSignatureService::class, 'removeBackground');
        $processed = $method->invoke(new EmployeeSignatureService(), $binary);
        $result = imagecreatefromstring($processed);

        self::assertNotFalse($result);
        self::assertLessThan(600, imagesx($result));
        self::assertLessThan(300, imagesy($result));
        self::assertGreaterThanOrEqual(120, (imagecolorat($result, 0, 0) >> 24) & 0x7f);
        self::assertSame("\x89PNG\r\n\x1a\n", substr($processed, 0, 8));

        imagedestroy($result);
    }
}
