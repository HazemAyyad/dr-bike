<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtFormatted extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $debts = $this->map(function($debt){
            return [
                'debt_id' => $debt->id,
                'customer_id' => $debt->customer_id ? $debt->customer->id:null,

                'customer_name' => $debt->customer_id ? $debt->customer->name:null,
                'customer_is_canceled' => $debt->customer_id ? $debt->customer->is_canceled:null,
                'seller_id' => $debt->seller_id ? $debt->seller->id:null,

                'seller_name' => $debt->seller_id? $debt->seller->name:null,
                'seller_is_canceled' => $debt->seller_id?$debt->seller->is_canceled:null,
                'due_date'=>$debt->due_date?? null,
                'total' => $debt->total,
                'status'=>$debt->status,
             
                'receipt_image' => $debt->receipt_image
                ? 'public/DebtsReceipts/'.$debt->receipt_image[0]:'no image',
             
             
                'debt_type' => $debt->type,
                'debt_created_at' => $debt->created_at? $debt->created_at->format('Y-m-d'):null,
                'notes' => $debt->notes?? 'no notes',
            ];
        });

        return $debts->toArray();

    }
}
