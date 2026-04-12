<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class Partners extends Controller
{
    public function allPartners(){
        try{
        $partners = Partner::all();

            return response()->json([
                'status' => 'success',
                'all partners' => $partners
            ], 200);

        }  catch (QueryException $e) {
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

}
