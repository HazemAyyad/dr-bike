<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;

class Invoices extends Controller
{
        // return all invoices
        public function allInvoices(){

            $invoices = Invoice::with(['order:id,customer_id',
            'order.customer:id,user_id',
            'order.customer.user:id,name'])->get();
    
            $formatted = $invoices->map(function ($invoice) {
                return [
                    'invoice_id' => $invoice->id,
                    'customer_name' => $invoice->order->customer->user->name,
                    'total' => $invoice->total,
                    'payment_method'=>$invoice->payment_method,
                ];
            });
    
            return response(['all invoices'=>$formatted]);
    
    
        }
}
