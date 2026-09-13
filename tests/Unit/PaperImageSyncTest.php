<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Papers;
use App\Models\Paper;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PaperImageSyncTest extends TestCase
{
    public function test_explicit_retained_images_detach_removed_references_without_accepting_unknown_files(): void
    {
        $paper = new Paper(['img' => ['old-one.jpg', 'old-two.jpg']]);
        $request = Request::create('/edit/paper', 'POST', [
            'retained_img' => json_encode([
                'https://example.test/public/Papers/old-two.jpg',
                'https://example.test/public/Papers/not-on-paper.jpg',
            ]),
        ]);

        $this->assertSame(['old-two.jpg'], $this->syncImages($request, $paper));
    }

    public function test_missing_retained_images_field_preserves_references_for_older_clients(): void
    {
        $paper = new Paper(['img' => ['old-one.jpg', 'old-two.jpg']]);
        $request = Request::create('/edit/paper', 'POST');

        $this->assertSame(
            ['old-one.jpg', 'old-two.jpg'],
            $this->syncImages($request, $paper),
        );
    }

    private function syncImages(Request $request, Paper $paper): array
    {
        $method = new ReflectionMethod(Papers::class, 'syncImagesWithoutDeletingFiles');
        $method->setAccessible(true);

        return $method->invoke(new Papers(), $request, $paper);
    }
}
