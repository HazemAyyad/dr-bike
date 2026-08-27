<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\PurchaseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PurchaseAttachmentService
{
    public function __construct(private PurchaseActivityService $activity)
    {
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, PurchaseAttachment>
     */
    public function store(
        ?Bill $bill,
        array $files,
        string $category,
        ?string $attachableType = null,
        ?int $attachableId = null,
        ?int $userId = null
    ): array {
        $created = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('purchase-evidence/'.($bill?->id ?? 'direct-returns'), 'public');
            $created[] = PurchaseAttachment::create([
                'bill_id' => $bill?->id,
                'attachable_type' => $attachableType,
                'attachable_id' => $attachableId,
                'category' => $category,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'created_by' => $userId,
            ]);
        }

        if ($created !== []) {
            $this->activity->log(
                $bill,
                'attachment_uploaded',
                'رفع مرفقات شراء',
                'تم رفع '.count($created).' مرفق للشراء أو المرتجع',
                null,
                array_map(fn (PurchaseAttachment $attachment) => $attachment->toArray(), $created),
                ['category' => $category],
                $attachableType,
                $attachableId,
                $userId
            );
        }

        return $created;
    }

    public function format(PurchaseAttachment $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'bill_id' => $attachment->bill_id ? (int) $attachment->bill_id : null,
            'attachable_type' => $attachment->attachable_type,
            'attachable_id' => $attachment->attachable_id ? (int) $attachment->attachable_id : null,
            'category' => $attachment->category,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size ? (int) $attachment->size : null,
            'path' => $attachment->path,
            'url' => Storage::disk($attachment->disk ?: 'public')->url($attachment->path),
            'created_at' => $attachment->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
