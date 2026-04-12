<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone ?? 'no phone number',
            'sub_phone' => $this->user->sub_phone ?? 'no sub phone number',

            'hour_work_price' => $this->hour_work_price,
            'overtime_work_price' => $this->overtime_work_price,
            'number_of_work_hours' => $this->number_of_work_hours,
            'start_work_time' => $this->start_work_time,
            'end_work_time' => $this->end_work_time,

            'employee_img' => $this->employee_img
                ? collect($this->employee_img)->map(fn($img) => 'public/EmployeeImages/'.$img)->toArray()
                : 'no images',

            'document_img' => $this->document_img
                ? collect($this->document_img)->map(fn($doc) => 'public/EmployeeDocumetImages/'.$doc)->toArray()
                : 'no document images',

        ];
    }
}
