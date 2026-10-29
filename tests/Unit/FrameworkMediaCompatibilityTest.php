<?php

namespace Tests\Unit;

use App\Services\FirebaseService;
use App\Services\ShiplyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Mpdf\Mpdf;
use ReflectionMethod;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class FrameworkMediaCompatibilityTest extends TestCase
{
    public function test_shipping_arabic_label_renders_and_can_be_imported_as_a_single_pdf_page(): void
    {
        Http::preventStrayRequests();
        $method = new ReflectionMethod(ShiplyService::class, 'convertShiplyV2HtmlToPdf');
        $binary = $method->invoke(
            new ShiplyService,
            '<html><head></head><body><h1>ملصق توصيل المتجر</h1><p>طلب تجريبي رقم 42 — الخليل</p></body></html>',
            'https://shipping.example.invalid/template',
            'A4',
            'arabic',
        );

        $this->assertStringStartsWith('%PDF-', $binary);
        $reader = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
        $this->assertSame(1, $reader->setSourceFile(StreamReader::createByString($binary)));
        $size = $reader->getTemplateSize($reader->importPage(1));
        $this->assertEqualsWithDelta(210, $size['width'], 1);
        $this->assertEqualsWithDelta(297, $size['height'], 1);
    }

    public function test_firebase_sdk_builds_the_existing_store_payload_without_a_network_send(): void
    {
        Log::spy();
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->once()->with(Mockery::on(function ($message): bool {
            $payload = $message->jsonSerialize();
            $this->assertSame('fixture-device-token', $payload['token']);
            $this->assertSame('طلب تجريبي', $payload['notification']['title']);
            $this->assertSame('42', $payload['data']['order_id']);
            $this->assertSame('fixture-store', $payload['android']['notification']['channel_id']);
            $this->assertSame('10', $payload['apns']['headers']['apns-priority']);

            return true;
        }))->andReturn(['name' => 'projects/fixture/messages/42']);

        $service = new class extends FirebaseService {
            public function resolveCredentialsFile(): ?string
            {
                return null;
            }

            public function serviceAccountProjectId(): ?string
            {
                return 'fixture';
            }

            public function deliver(Messaging $messaging): mixed
            {
                return $this->sendToTokenInternal($messaging, 'fixture-device-token', 'طلب تجريبي', 'جاهز', [
                    'order_id' => '42',
                    'notification_channel_id' => 'fixture-store',
                    'notification_priority' => 'high',
                ], true);
            }
        };

        $this->assertSame(['name' => 'projects/fixture/messages/42'], $service->deliver($messaging));
    }
}
