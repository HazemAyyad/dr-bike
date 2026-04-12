<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Treasury;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Treasuries extends Controller
{
    public function store(Request $request){
        try{
            $request->validate(['name'=>'required|string|max:255']);

            Treasury::create(['name'=>$request->name]);
            Logs::createLog('اضافة خزنة جديدة','تم اضافة خزنة جديدة باسم'.' '.$request->name,'treasuries');
        
            return response()->json([
                'status'=>'success',
                'message' => __('messages.treasury_created'),
            ],200);
        
        }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function getTreasuries(){
        try{
            $treasuries = Treasury::where('is_canceled',0)
            ->with([
            'fileBoxes'=> function($q){
                $q->where('is_cancelled',0)->select('id','treasury_id','name');
            },
            'fileBoxes.files' => function ($query) {
                $query->where('is_canceled', 0)
                    ->select('id', 'file_box_id', 'name');
            }
        ])
        ->get(['id', 'name']);

            return response()->json([
                'status'=>'success',
                'treasuries' => $treasuries,
            ]);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }


    // public function getTreasuryFileBoxes(Request $request){
    //     try{
    //         $request->validate(['treasury_id'=>'required|exists:treasuries,id']);

    //         $fileBoxes = $tr
    //     }
    // }

    public function cancelTreasury(Request $request){
        try{
            $request->validate([
                'treasury_id'=>'required|integer|exists:treasuries,id'
            ]);

            $treasury = Treasury::findOrFail($request->treasury_id);
            $treasury->update(['is_canceled'=>1]);
            $treasury->fileBoxes()->update(['is_cancelled'=>1]);
           foreach ($treasury->fileBoxes as $fileBox) {
                foreach ($fileBox->files as $file) {  
                                $file->update(['is_canceled' => 1]);

                            foreach ($file->papers as $paper) {
                                $paper->update(['is_cancelled' => 1]);
                            }
                        }
        }
            Logs::createLog('حذف خزنة ','تم حذف خزنة باسم'.' '.$treasury->name,'treasuries');

            return response()->json([
                'status'=>'success',
                'message' =>__('messages.treasury_cancelled'),
            ],200);
        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } 
        catch (ModelNotFoundException $e) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.something_wrong')
                    ], 200);
                }         
        
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } 
        
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
}
