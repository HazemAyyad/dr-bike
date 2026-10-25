<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderMedia;
use App\Models\SalesOrderNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SalesOrderNotesService
{
    /** Both scopes are staff notes; neither is published to the customer. */
    public function add(User $user, int $orderId, string $scope, ?string $text, array $images): SalesOrderNote
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($user, $orderId, $scope, $text, $images, &$paths) {
                SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
                $note = SalesOrderNote::query()->create([
                    'sales_order_id' => $orderId,
                    'scope' => $scope,
                    'note' => trim((string) $text),
                    'created_by' => $user->id,
                ]);
                foreach ($images as $image) {
                    $path = $image->store("sales-orders/{$orderId}/notes", 'public');
                    if (! $path) {
                        throw new \RuntimeException('Could not store sales order note attachment.');
                    }
                    $paths[] = $path;
                    SalesOrderMedia::query()->create([
                        'sales_order_id' => $orderId,
                        'sales_order_note_id' => $note->id,
                        'category' => 'note',
                        'type' => str_starts_with((string) $image->getMimeType(), 'video/') ? 'video' : 'image',
                        'path' => $path,
                        'mime' => $image->getMimeType(),
                        'size_bytes' => $image->getSize(),
                        'uploaded_by' => $user->id,
                    ]);
                }

                return $note->load('media');
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($paths);
            throw $e;
        }
    }
}
