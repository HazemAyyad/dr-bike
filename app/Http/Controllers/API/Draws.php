<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;


class Draws extends Controller
{
    public function store(Request $request)
{
  try{
        $request->validate([
            'draw_way' => 'required|string',
            'customer_id' => 'required|exists:customers,id',
            'box_id' => 'required|exists:boxes,id',
            'total' => 'required|numeric|min:0',
            'receipt_image' => 'required|image|mimes:jpeg,png,jpg',
            'notes' => 'nullable|string',

            'other_ways' => 'nullable|array',
            'other_ways.*.draw_way' => 'required|string',
            'other_ways.*.customer_id' => 'required|exists:customers,id',
            'other_ways.*.box_id' => 'required|exists:boxes,id',
            'other_ways.*.total' => 'required|numeric|min:0',
            'other_ways.*.receipt_image' => 'required|image|mimes:jpeg,png,jpg',
        ]);

    
            $imageName = '';
            if($request->hasFile('receipt_image')){
            $imageFile = $request->file('receipt_image');
            $imageName = $imageFile->getClientOriginalName();
            $imageFile->move(public_path('DrawReceipts'), $imageName);
            }
            // Save main draw
            $mainDraw = Draw::create([
                'draw_way' => $request->draw_way,
                'customer_id' => $request->customer_id,
                'box_id' => $request->box_id,
                'total' => $request->total,
                'receipt_image' => $imageName,
                'notes' => $request->notes?? 'no notes',
            ]);

            // Save other draw ways if provided
            if ($request->has('other_ways')) {
                foreach ($request->other_ways as $index => $way) {
                    $fileKey = "other_ways.$index.receipt_image";
                    $otherImageName = '';

                    if ($request->hasFile($fileKey)) {
                        $otherFile = $request->file($fileKey);
                        $otherImageName = $otherFile->getClientOriginalName();
                        $otherFile->move(public_path('DrawReceipts'), $otherImageName);
                    }
                    Draw::create([
                        'draw_way' => $way['draw_way'],
                        'customer_id' => $way['customer_id'],
                        'box_id' => $way['box_id'],
                        'total' => $way['total'],
                        'receipt_image' => $otherImageName,
                        'parent_id' => $mainDraw->id,
                    ]);
                }
            }
        Logs::createLog('اضافة سحب','اضافة سحب جديد للزبون'.' '.$mainDraw->customer->name,'draws');

        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.draw_created_successfully')
                ], 200);

            }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_creat_draw')
            ], 200);
        }


}

}