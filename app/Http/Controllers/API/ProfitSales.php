<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Box;
use App\Models\Customer;
use App\Models\ProfitSale;
use App\Models\Seller;
use App\Services\DebtLedgerService;
use App\Services\SalesDailySessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class ProfitSales extends Controller
{
    private string $profitSaleMediaPath = 'profit-sale-media';

    private function normalizeNumericInput($value): string
    {
        $text = trim((string) ($value ?? ''));
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ',' => '', '،' => '', ' ' => '',
        ]);

        return $text === '' ? '0' : $text;
    }

    private function storeProfitSaleFile(Request $request, string $field): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }

        $file = $request->file($field);
        $name = uniqid($field.'_').'.'.$file->getClientOriginalExtension();
        $file->move(public_path($this->profitSaleMediaPath), $name);

        return 'public/'.$this->profitSaleMediaPath.'/'.$name;
    }

    private function reverseBoxForCancelledProfitSale(ProfitSale $sale): void
    {
        $boxId = $sale->payment_box_id;
        if (! $boxId && ! empty($sale->payment_box_name)) {
            $boxId = Box::where('name', $sale->payment_box_name)->value('id');
        }

        $amount = (float) ($sale->payment_box_value ?? 0);
        if (! $boxId || $amount <= 0) {
            return;
        }

        $box = Box::lockForUpdate()->findOrFail($boxId);
        $box->total = (float) ($box->total ?? 0) - $amount;
        $box->save();

        BoxLogs::createBoxLog(
            $box,
            'سحب — عكس قبض بيع ربحي',
            'minus',
            -$amount,
            'إلغاء بيع ربحي #'.$sale->id.' بقيمة '.number_format($amount, 2, '.', '')
        );
    }

    private function markProfitSaleCancelled(ProfitSale $sale): void
    {
        $payload = [];
        if (Schema::hasColumn('profit_sales', 'cancelled_at')) {
            $payload['cancelled_at'] = now();
        }
        if (Schema::hasColumn('profit_sales', 'status')) {
            $payload['status'] = 'cancelled';
        }
        if (! empty($payload)) {
            $sale->update($payload);
        }
    }

    private function profitSalePersonLabel(ProfitSale $sale): string
    {
        $sale->loadMissing(['customer:id,name', 'seller:id,name']);
        if ($sale->customer) {
            return 'للزبون '.$sale->customer->name;
        }
        if ($sale->seller) {
            return 'للتاجر '.$sale->seller->name;
        }
        $name = trim((string) ($sale->buyer_name ?? ''));
        if ($name !== '') {
            return 'للشخص '.$name;
        }

        return 'بدون زبون';
    }

    public function store(Request $request)
 {
    try{
    $dailySession = app(SalesDailySessionService::class)->assertCanCreateSale($request->user());

    if ($request->has('total_cost')) {
        $request->merge(['total_cost' => $this->normalizeNumericInput($request->input('total_cost'))]);
    }
    if ($request->has('payment_box_value')) {
        $request->merge(['payment_box_value' => $this->normalizeNumericInput($request->input('payment_box_value'))]);
    }

    $data = $request->validate([
        'total_cost' => 'required|numeric|min:0',
        'notes' => 'nullable|string',
        'image' => 'nullable|image|max:10240',
        'video' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska|max:51200',
        'buyer_type' => 'nullable|string|in:customer,seller,unknown',
        'buyer_id' => 'nullable|integer|exists:customers,id',
        'seller_id' => 'nullable|integer|exists:sellers,id',
        'buyer_name' => 'nullable|string|max:255',
        'buyer_phone' => 'nullable|string|max:30',
        'payment_box_value' => 'nullable|numeric|min:0',
    ]);

    unset($data['image'], $data['video']);
    $data['image_path'] = $this->storeProfitSaleFile($request, 'image');
    $data['video_path'] = $this->storeProfitSaleFile($request, 'video');
    $buyerType = $request->input('buyer_type', 'unknown');
    $buyerName = trim((string) $request->input('buyer_name', ''));
    $buyerPhone = trim((string) $request->input('buyer_phone', ''));

    if ($request->filled('buyer_id') && $request->filled('seller_id')) {
        throw ValidationException::withMessages([
            'buyer_id' => [__('messages.must_select_either_customer_or_seller')],
        ]);
    }
    if ($buyerType === 'customer' && ! $request->filled('buyer_id') && $buyerName === '') {
        throw ValidationException::withMessages([
            'buyer_id' => [__('messages.must_select_customer_or_seller')],
        ]);
    }
    if ($buyerType === 'seller' && ! $request->filled('seller_id')) {
        throw ValidationException::withMessages([
            'seller_id' => [__('messages.must_select_customer_or_seller')],
        ]);
    }
    if ($buyerType === 'unknown' && ($request->filled('buyer_id') || $request->filled('seller_id'))) {
        throw ValidationException::withMessages([
            'buyer_type' => [__('messages.must_select_either_customer_or_seller')],
        ]);
    }

    $profitSale = DB::transaction(function () use ($data, $request, $buyerType, $buyerName, $buyerPhone, $dailySession) {
        unset($data['buyer_id'], $data['buyer_phone']);

        if ($buyerType === 'customer') {
            unset($data['seller_id']);
            if ($request->filled('buyer_id')) {
                $data['customer_id'] = (int) $request->input('buyer_id');
                unset($data['buyer_name']);
            } elseif ($buyerName !== '') {
                $customer = Customer::firstOrCreate(
                    $buyerPhone !== '' ? ['phone' => $buyerPhone] : ['name' => $buyerName],
                    [
                        'name' => $buyerName,
                        'phone' => $buyerPhone !== '' ? $buyerPhone : null,
                        'ID_image' => [],
                        'license_image' => [],
                    ]
                );
                $data['customer_id'] = $customer->id;
                $data['buyer_name'] = $customer->name;
            }
        } elseif ($buyerType === 'seller' && $request->filled('seller_id')) {
            unset($data['customer_id']);
            $data['seller_id'] = (int) $request->input('seller_id');
            unset($data['buyer_name']);
        } else {
            unset($data['customer_id'], $data['seller_id']);
            $data['buyer_type'] = 'unknown';
        }

        $data['payment_box_value'] = (float) ($data['payment_box_value'] ?? $data['total_cost']);
        $dailyBox = app(SalesDailySessionService::class)
            ->dailyBoxForSessionCurrency($dailySession, 'شيكل');
        if (! $dailyBox) {
            throw ValidationException::withMessages([
                'payment_box_id' => [__('messages.sales_daily_box_required')],
            ]);
        }
        $data['payment_box_id'] = (int) $dailyBox->id;
        $data['payment_box_name'] = (string) $dailyBox->name;

        $data['sales_daily_session_id'] = $dailySession->id;
        if (Schema::hasColumn('profit_sales', 'created_by')) {
            $data['created_by'] = $request->user()->id;
        }

        $profitSale = ProfitSale::create($data);

        if ($profitSale->payment_box_id && $profitSale->payment_box_value > 0) {
            $box = Box::lockForUpdate()->find($profitSale->payment_box_id);
            if ($box) {
                $box->total = (float) ($box->total ?? 0) + (float) $profitSale->payment_box_value;
                $box->save();
                $boxLogNote = 'قبض بيع ربحي #'.$profitSale->id.' بقيمة '.number_format((float) $profitSale->payment_box_value, 2, '.', '');
                if ((int) $dailySession->user_id !== (int) $request->user()->id) {
                    $dailySession->loadMissing('user');
                    $request->user()->loadMissing('employee.user');
                    $actorName = $request->user()->employee?->user?->name ?? $request->user()->name ?? 'موظف';
                    $ownerName = $dailySession->user?->name ?? 'غير محدد';
                    $boxLogNote .= ' | المنفذ: '.$actorName.' | صاحب الصندوق: '.$ownerName;
                }

                BoxLogs::createBoxLog(
                    $box,
                    'قبض — بيع ربحي',
                    'add',
                    $profitSale->payment_box_value,
                    $boxLogNote
                );
            }
        }

        app(DebtLedgerService::class)->syncProfitSaleToLedger($profitSale->fresh(['paymentBox']));

        return $profitSale;
    });

        $freshProfitSale = $profitSale->fresh(['customer:id,name', 'seller:id,name']);
        Logs::createLog(
            'اضافة ربح نقدي جديد',
            'اضافة ربح نقدي جديد رقم الفاتورة #'.$freshProfitSale->id.' '.$this->profitSalePersonLabel($freshProfitSale).' بقيمة '.$freshProfitSale->total_cost,
            'profit_sales'
        );
        app(SalesDailySessionService::class)->notifyExternalSaleMovement(
            $request->user(),
            $dailySession,
            'profit',
            (int) $freshProfitSale->id,
            (float) (($freshProfitSale->payment_box_value ?? 0) > 0
                ? $freshProfitSale->payment_box_value
                : $freshProfitSale->total_cost),
            $freshProfitSale->payment_box_id ? (int) $freshProfitSale->payment_box_id : null
        );
        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.profit_sale_created_successfully')
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
                'message' => __('messages.create_data_error'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }

}

public function getProfitSales(Request $request)
{
    try {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        $profitSales = ProfitSale::query()
            ->with([
                'customer:id,name,phone,sub_phone',
                'seller:id,name,phone,sub_phone',
                'paymentBox:id,name',
            ])
            ->when(
                $data['date'] ?? null,
                fn ($query, $date) => $query->whereDate('created_at', $date)
            )
            ->when($search !== '', function ($query) use ($search) {
                $term = "%{$search}%";
                $query->where(function ($searchQuery) use ($search, $term) {
                    $searchQuery->where('buyer_name', 'like', $term)
                        ->orWhere('notes', 'like', $term)
                        ->orWhereHas('customer', function ($customerQuery) use ($term) {
                            $customerQuery->where(function ($identityQuery) use ($term) {
                                $identityQuery->where('name', 'like', $term)
                                    ->orWhere('phone', 'like', $term)
                                    ->orWhere('sub_phone', 'like', $term);
                            });
                        })
                        ->orWhereHas('seller', function ($sellerQuery) use ($term) {
                            $sellerQuery->where(function ($identityQuery) use ($term) {
                                $identityQuery->where('name', 'like', $term)
                                    ->orWhere('phone', 'like', $term)
                                    ->orWhere('sub_phone', 'like', $term);
                            });
                        });

                    if (ctype_digit($search)) {
                        $searchQuery->orWhere('id', (int) $search);
                    }
                });
            })
            ->orderByDesc('id')
            ->get();
        $profitSales->transform(function (ProfitSale $sale) {
            if ($sale->customer && empty($sale->buyer_name)) {
                $sale->buyer_name = $sale->customer->name;
            } elseif ($sale->seller && empty($sale->buyer_name)) {
                $sale->buyer_name = $sale->seller->name;
            }
            if ($sale->paymentBox && empty($sale->payment_box_name)) {
                $sale->payment_box_name = $sale->paymentBox->name;
            }
            $linkedBuyer = $sale->customer ?? $sale->seller;
            $sale->setAttribute(
                'buyer_phone',
                $linkedBuyer?->phone ?: $linkedBuyer?->sub_phone
            );

            return $sale;
        });
        return response()->json([
            'status' => 'success',
            'profit_sales' => $profitSales,
        ], 200);
    } catch (QueryException $e) {
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

    public function cancel(Request $request)
    {
        try {
            $request->validate([
                'profit_sale_id' => 'required|integer|exists:profit_sales,id',
            ]);

            DB::transaction(function () use ($request) {
                $profitSale = ProfitSale::query()
                    ->with('salesDailySession')
                    ->lockForUpdate()
                    ->findOrFail($request->profit_sale_id);

                app(SalesDailySessionService::class)->assertCanDirectCancelSale($request->user(), $profitSale);

                if ($profitSale->isCancelled()) {
                    throw ValidationException::withMessages([
                        'profit_sale_id' => [__('messages.instant_sale_already_cancelled')],
                    ]);
                }

                $this->reverseBoxForCancelledProfitSale($profitSale);
                $this->markProfitSaleCancelled($profitSale);
                app(DebtLedgerService::class)->deleteSourceLedger('profit_sale', (int) $profitSale->id);

                Logs::createLog(
                    'إلغاء بيع ربحي',
                    'تم إلغاء بيع ربحي #'.$profitSale->id.' '.$this->profitSalePersonLabel($profitSale),
                    'profit_sales'
                );
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.profit_sale_cancelled_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
    }

    public function showProfitSale(Request $request){
        try{
            $request->validate(['profit_sale_id'=>'required|exists:profit_sales,id']);
            $profitSale = ProfitSale::findOrFail($request->profit_sale_id)
            ;

            return response()->json([
                'status'=>'success',
                'profit_sale_details' => $profitSale,
            ],200);
    }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
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

    public function edit(Request $request){
        try{
        if ($request->has('total_cost')) {
            $request->merge(['total_cost' => $this->normalizeNumericInput($request->input('total_cost'))]);
        }
        if ($request->has('payment_box_value')) {
            $request->merge(['payment_box_value' => $this->normalizeNumericInput($request->input('payment_box_value'))]);
        }

        $data = $request->validate([
            'profit_sale_id' => 'required|integer|exists:profit_sales,id',
            'total_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'buyer_type' => 'required|string|in:customer,seller,unknown',
            'buyer_id' => 'nullable|integer|exists:customers,id',
            'seller_id' => 'nullable|integer|exists:sellers,id',
            'buyer_name' => 'nullable|string|max:255',
            'payment_box_value' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|max:10240',
            'video' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska|max:51200',
        ]);

        if ($data['buyer_type'] === 'customer' && ! $request->filled('buyer_id')) {
            throw ValidationException::withMessages([
                'buyer_id' => [__('messages.must_select_customer_or_seller')],
            ]);
        }
        if ($data['buyer_type'] === 'seller' && ! $request->filled('seller_id')) {
            throw ValidationException::withMessages([
                'seller_id' => [__('messages.must_select_customer_or_seller')],
            ]);
        }
        if ($request->filled('buyer_id') && $request->filled('seller_id')) {
            throw ValidationException::withMessages([
                'buyer_id' => [__('messages.must_select_either_customer_or_seller')],
            ]);
        }
        if ($data['buyer_type'] === 'unknown'
            && ($request->filled('buyer_id') || $request->filled('seller_id'))) {
            throw ValidationException::withMessages([
                'buyer_type' => [__('messages.must_select_either_customer_or_seller')],
            ]);
        }

        $profitSale = DB::transaction(function () use ($request, $data) {
            $profitSale = ProfitSale::query()
                ->with('salesDailySession')
                ->lockForUpdate()
                ->findOrFail($data['profit_sale_id']);

            if ($profitSale->isCancelled()) {
                throw ValidationException::withMessages([
                    'profit_sale_id' => [__('messages.instant_sale_already_cancelled')],
                ]);
            }

            $dailySessions = app(SalesDailySessionService::class);
            $dailySessions->assertCanDirectCancelSale($request->user(), $profitSale);
            if ($dailySessions->saleBelongsToClosedSession($profitSale)) {
                throw ValidationException::withMessages([
                    'profit_sale_id' => ['لا يمكن تعديل بيع ربحي تابع لصندوق يومي مغلق.'],
                ]);
            }

            $newTotal = round((float) $data['total_cost'], 2);
            $oldPaid = round((float) ($profitSale->payment_box_value ?? 0), 2);
            $newPaid = array_key_exists('payment_box_value', $data)
                ? round((float) $data['payment_box_value'], 2)
                : $oldPaid;
            if ($newPaid > $newTotal + 0.0001) {
                throw ValidationException::withMessages([
                    'payment_box_value' => [__('messages.validation_failed')],
                ]);
            }

            $customerId = null;
            $sellerId = null;
            $buyerName = trim((string) ($data['buyer_name'] ?? ''));
            if ($data['buyer_type'] === 'customer') {
                $customer = Customer::findOrFail((int) $data['buyer_id']);
                $customerId = (int) $customer->id;
                $buyerName = (string) $customer->name;
            } elseif ($data['buyer_type'] === 'seller') {
                $seller = Seller::findOrFail((int) $data['seller_id']);
                $sellerId = (int) $seller->id;
                $buyerName = (string) $seller->name;
            }

            $delta = round($newPaid - $oldPaid, 2);
            if (abs($delta) > 0.0001) {
                $boxId = (int) ($profitSale->payment_box_id ?? 0);
                $box = $boxId > 0
                    ? Box::query()->lockForUpdate()->find($boxId)
                    : null;
                if (! $box) {
                    throw ValidationException::withMessages([
                        'payment_box_value' => [__('messages.sales_daily_box_required')],
                    ]);
                }
                $nextBoxTotal = round((float) ($box->total ?? 0) + $delta, 2);
                if ($nextBoxTotal < -0.0001) {
                    throw ValidationException::withMessages([
                        'payment_box_value' => [__('messages.box_out_of_money')],
                    ]);
                }
                $box->total = max(0, $nextBoxTotal);
                $box->save();
                BoxLogs::createBoxLog(
                    $box,
                    $delta > 0 ? 'إضافة — فرق تعديل بيع ربحي' : 'سحب — فرق تعديل بيع ربحي',
                    $delta > 0 ? 'add' : 'minus',
                    abs($delta),
                    'تعديل بيع ربحي #'.$profitSale->id.' — فرق المقبوض '.number_format($delta, 2, '.', '')
                );
            }

            $update = [
                'total_cost' => $newTotal,
                'notes' => $data['notes'] ?? null,
                'buyer_type' => $data['buyer_type'],
                'customer_id' => $customerId,
                'seller_id' => $sellerId,
                'buyer_name' => $buyerName !== '' ? $buyerName : null,
                'payment_box_value' => $newPaid,
            ];
            if ($request->hasFile('image')) {
                $update['image_path'] = $this->storeProfitSaleFile($request, 'image');
            }
            if ($request->hasFile('video')) {
                $update['video_path'] = $this->storeProfitSaleFile($request, 'video');
            }

            $profitSale->update($update);
            $fresh = $profitSale->fresh(['paymentBox', 'customer:id,name', 'seller:id,name']);
            app(DebtLedgerService::class)->syncProfitSaleToLedger($fresh);

            return $fresh;
        });

        Logs::createLog(
            'تعديل ربح نقدي',
            'تم تعديل ربح نقدي #'.$profitSale->id.' '.$this->profitSalePersonLabel($profitSale),
            'profit_sales'
        );

        return response()->json([
            'status' => 'success',
            'message' => __('messages.profit_sale_updated_successfully'),
            'profit_sale' => $profitSale,
        ], 200);
    }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()

            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
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

}
