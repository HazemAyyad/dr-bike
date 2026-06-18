<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\ShiplyCity;
use App\Models\ShiplyVillage;
use App\Support\ShiplySettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiplyService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function addressOptions(?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();

        return ShiplyCity::query()
            ->where('mode', $mode)
            ->whereNull('deleted_at_remote')
            ->orderBy('name')
            ->get()
            ->map(function (ShiplyCity $city) use ($mode) {
                $villages = ShiplyVillage::query()
                    ->where('mode', $mode)
                    ->where('shiply_city_id', $city->shiply_id)
                    ->whereNull('deleted_at_remote')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (ShiplyVillage $village) => [
                        'id' => $village->shiply_id,
                        'name' => $village->name,
                        'is_closed' => (bool) $village->is_closed,
                        'note' => $village->note,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $city->shiply_id,
                    'name' => $city->name,
                    'villages' => $villages,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{synced_cities: int, synced_villages: int}
     */
    public function syncAddresses(?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();
        $cities = $this->request('POST', '/address/getCitiesAndVillages', [], $mode);

        if (! is_array($cities)) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_sync_failed')],
            ]);
        }

        $cityCount = 0;
        $villageCount = 0;

        DB::transaction(function () use ($cities, $mode, &$cityCount, &$villageCount) {
            foreach ($cities as $cityRow) {
                if (! is_array($cityRow) || empty($cityRow['id'])) {
                    continue;
                }

                ShiplyCity::query()->updateOrCreate(
                    ['shiply_id' => (int) $cityRow['id'], 'mode' => $mode],
                    [
                        'name' => (string) ($cityRow['name'] ?? ''),
                        'deleted_at_remote' => ! empty($cityRow['deleted_at']) ? now() : null,
                    ]
                );
                $cityCount++;

                foreach ($cityRow['villages'] ?? [] as $villageRow) {
                    if (! is_array($villageRow) || empty($villageRow['id'])) {
                        continue;
                    }

                    ShiplyVillage::query()->updateOrCreate(
                        ['shiply_id' => (int) $villageRow['id'], 'mode' => $mode],
                        [
                            'shiply_city_id' => (int) ($villageRow['city_id'] ?? $cityRow['id']),
                            'name' => (string) ($villageRow['name'] ?? ''),
                            'region_id' => isset($villageRow['region_id']) ? (int) $villageRow['region_id'] : null,
                            'region_type' => isset($villageRow['region_type']) ? (int) $villageRow['region_type'] : null,
                            'note' => $villageRow['note'] ?? null,
                            'is_closed' => (int) ($villageRow['is_closed'] ?? 0) === 1,
                            'deleted_at_remote' => ! empty($villageRow['deleted_at']) ? now() : null,
                        ]
                    );
                    $villageCount++;
                }
            }
        });

        return [
            'synced_cities' => $cityCount,
            'synced_villages' => $villageCount,
        ];
    }

    /**
     * @return array{parcel_code: string, qr_code: ?string}
     */
    public function createAndSubmitParcel(SalesOrder $order, string $employeeEmail, ?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();
        $this->assertOrderReadyForShiply($order, $mode);

        $payload = $this->buildCreateParcelPayload($order, $employeeEmail);
        $create = $this->request('POST', '/parcels/create', $payload, $mode);

        if (! is_array($create) || empty($create['success'])) {
            $errors = $create['errors'] ?? $create['error'] ?? $create['message'] ?? null;
            Log::warning('shiply.create_parcel_failed', [
                'mode' => $mode,
                'employee_email' => $employeeEmail,
                'response' => $create,
            ]);
            throw ValidationException::withMessages([
                'shiply' => [$this->formatShiplyError($errors, $employeeEmail)],
            ]);
        }

        $parcelCode = (string) ($create['parcel_code'] ?? '');
        if ($parcelCode === '') {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_create_parcel_failed')],
            ]);
        }

        $qrCode = null;
        try {
            $submit = $this->request('GET', '/parcels/assignQRCode/'.rawurlencode($parcelCode), [
                'employee_email' => $employeeEmail,
            ], $mode);
            if (is_array($submit) && ! empty($submit['success'])) {
                $qrCode = $submit['qr_code'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('shiply.assign_qr_failed', [
                'parcel_code' => $parcelCode,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'parcel_code' => $parcelCode,
            'qr_code' => is_string($qrCode) ? $qrCode : null,
        ];
    }

    public function cancelParcel(string $parcelCode, string $employeeEmail, ?string $mode = null): void
    {
        if ($parcelCode === '') {
            return;
        }

        $mode = $mode ?? ShiplySettings::mode();
        $response = $this->request('GET', '/parcels/cancel/'.rawurlencode($parcelCode), [
            'employee_email' => $employeeEmail,
        ], $mode);

        if (is_array($response) && array_key_exists('success', $response) && ! $response['success']) {
            Log::warning('shiply.cancel_parcel_failed', [
                'parcel_code' => $parcelCode,
                'response' => $response,
            ]);
        }
    }

    public function registerWebhook(?string $mode = null): bool
    {
        $mode = $mode ?? ShiplySettings::mode();
        $response = $this->request('PUT', '/customer/webhookURL', [
            'webhook_url' => ShiplySettings::webhookUrl(),
        ], $mode);

        return is_array($response) && ! empty($response['success']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getParcel(string $parcelCode, ?string $mode = null): array
    {
        $mode = $mode ?? ShiplySettings::mode();
        $response = $this->request('GET', '/parcels/'.rawurlencode($parcelCode), [], $mode);

        return is_array($response) ? $response : [];
    }

    /**
     * @return array{delivery_cost: float, extra_price: float, returned_extra_price: float}
     */
    public function calculateDeliveryCost(int $villageId, float $parcelPrice = 0, ?string $mode = null): array
    {
        $response = $this->request('POST', '/parcels/fees', [
            'village_id' => $villageId,
            'price' => max(0, $parcelPrice),
        ], $mode);

        return [
            'delivery_cost' => (float) ($response['delivery_cost'] ?? 0),
            'extra_price' => (float) ($response['extra_price'] ?? 0),
            'returned_extra_price' => (float) ($response['returned_extra_price'] ?? 0),
        ];
    }

    private function assertOrderReadyForShiply(SalesOrder $order, string $mode): void
    {
        if (empty($order->shiply_village_id)) {
            throw ValidationException::withMessages([
                'shiply_village_id' => [__('messages.shiply_village_required')],
            ]);
        }

        if (trim((string) $order->customer_address) === '') {
            throw ValidationException::withMessages([
                'customer_address' => [__('messages.shiply_street_required')],
            ]);
        }

        if (trim((string) $order->customer_phone) === '') {
            throw ValidationException::withMessages([
                'customer_phone' => [__('messages.shiply_phone_required')],
            ]);
        }

        if (empty($order->shiply_city_id)) {
            throw ValidationException::withMessages([
                'shiply_city_id' => [__('messages.shiply_city_required')],
            ]);
        }

        $village = ShiplyVillage::query()
            ->where('mode', $mode)
            ->where('shiply_id', $order->shiply_village_id)
            ->first();

        if (! $village) {
            try {
                $this->syncAddresses($mode);
            } catch (\Throwable $e) {
                Log::warning('shiply.sync_before_parcel_failed', [
                    'mode' => $mode,
                    'message' => $e->getMessage(),
                ]);
            }

            $village = ShiplyVillage::query()
                ->where('mode', $mode)
                ->where('shiply_id', $order->shiply_village_id)
                ->first();
        }

        if (! $village) {
            throw ValidationException::withMessages([
                'shiply_village_id' => [__('messages.shiply_village_invalid')],
            ]);
        }

        if ($village->is_closed) {
            throw ValidationException::withMessages([
                'shiply_village_id' => [__('messages.shiply_village_closed')],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreateParcelPayload(SalesOrder $order, string $employeeEmail): array
    {
        $order->loadMissing('items');
        $description = $order->items
            ->where('is_hidden', false)
            ->take(5)
            ->map(fn ($item) => trim((string) ($item->product_name ?? 'صنف')).' x'.(int) $item->quantity)
            ->implode(' | ');

        if ($description === '') {
            $description = 'طلبية '.($order->serial_number ?? '#'.$order->id);
        }

        $description = mb_substr($description, 0, 255);
        if (mb_strlen($description) < 3) {
            $description = 'طلبية';
        }

        $recipientName = trim((string) ($order->customer_name ?? ''));
        if ($recipientName === '') {
            $recipientName = 'مستلم';
        }

        return [
            'recipient' => [
                'first_name' => mb_substr($recipientName, 0, 100),
                'phone' => trim((string) $order->customer_phone),
            ],
            'address' => [
                'city_id' => (int) $order->shiply_city_id,
                'village_id' => (int) $order->shiply_village_id,
                'street_name' => mb_substr(trim((string) $order->customer_address), 0, 500),
            ],
            'total_price' => (int) round((float) $order->total),
            'actual_price' => (int) max(0, round((float) $order->subtotal - (float) $order->discount)),
            'description' => $description,
            'note' => $order->notes ? mb_substr((string) $order->notes, 0, 1023) : null,
            'reference_number' => $order->serial_number ?: (string) $order->id,
            'employee_email' => $employeeEmail,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function request(string $method, string $path, array $payload = [], ?string $mode = null): array
    {
        if (! ShiplySettings::isEnabled()) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_disabled')],
            ]);
        }

        $mode = $mode ?? ShiplySettings::mode();
        $apiKey = ShiplySettings::apiKey($mode);
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_api_key_missing')],
            ]);
        }

        $url = rtrim(ShiplySettings::baseUrl($mode), '/').'/'.ltrim($path, '/');
        $body = array_merge($payload, ['Shiply_API_KEY' => $apiKey]);
        $method = strtoupper($method);

        try {
            $client = Http::timeout((int) config('shiply.http_timeout', 30))->acceptJson();

            $response = match ($method) {
                'GET', 'HEAD', 'DELETE' => $client->withOptions([
                    'query' => $body,
                ])->send($method, $url),
                default => $client->asJson()->send($method, $url, ['json' => $body]),
            };
        } catch (ConnectionException $e) {
            Log::error('shiply.connection_failed', [
                'method' => $method,
                'path' => $path,
                'mode' => $mode,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_connection_failed')],
            ]);
        } catch (RequestException $e) {
            Log::error('shiply.http_failed', [
                'method' => $method,
                'path' => $path,
                'status' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);

            throw ValidationException::withMessages([
                'shiply' => [$this->formatHttpErrorMessage($e)],
            ]);
        }

        if ($response->failed()) {
            $json = $response->json();
            Log::warning('shiply.http_error', [
                'method' => $method,
                'path' => $path,
                'mode' => $mode,
                'status' => $response->status(),
                'body' => $json ?? $response->body(),
            ]);

            $errors = is_array($json) ? ($json['errors'] ?? $json['message'] ?? null) : null;
            if ($response->status() === 401 || $this->isUnauthorizedShiplyError($errors)) {
                throw ValidationException::withMessages([
                    'shiply' => [__('messages.shiply_api_unauthorized', ['mode' => $mode])],
                ]);
            }

            throw ValidationException::withMessages([
                'shiply' => [$this->extractResponseErrorMessage($json) ?? __('messages.shiply_request_failed')],
            ]);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw ValidationException::withMessages([
                'shiply' => [__('messages.shiply_request_failed')],
            ]);
        }

        return $json;
    }

    private function formatHttpErrorMessage(RequestException $e): string
    {
        $json = $e->response?->json();
        if (is_array($json)) {
            $message = $this->extractResponseErrorMessage($json);
            if ($message !== null) {
                return $message;
            }
        }

        return __('messages.shiply_request_failed');
    }

    private function extractResponseErrorMessage(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $errors = $json['errors'] ?? null;
        if ($errors !== null) {
            return $this->formatShiplyError($errors, '');
        }

        $message = trim((string) ($json['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return null;
    }

    private function formatShiplyError(mixed $errors, string $employeeEmail): string
    {
        if ($this->isUnauthorizedShiplyError($errors)) {
            return __('messages.shiply_unauthorized', [
                'email' => $employeeEmail !== '' ? $employeeEmail : '?',
            ]);
        }

        if (is_array($errors)) {
            $text = trim(implode(' ', array_map('strval', $errors)));

            return $text !== '' ? $text : __('messages.shiply_create_parcel_failed');
        }

        $text = trim((string) ($errors ?? ''));

        return $text !== '' ? $text : __('messages.shiply_create_parcel_failed');
    }

    private function isUnauthorizedShiplyError(mixed $errors): bool
    {
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if ($this->isUnauthorizedShiplyError($error)) {
                    return true;
                }
            }

            return false;
        }

        return stripos(trim((string) $errors), 'unauthorized') !== false;
    }
}

