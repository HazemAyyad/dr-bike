<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\Expense;
use App\Services\ExpenseBoxAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Str;
class ExpensesAPI extends Controller
{
    private $expensesMediaPath = 'Expenses/ExpensesMedia';
    private $invoiceImagesPath = 'Expenses/InvoiceImages';

    public function availableBoxes(Request $request, ExpenseBoxAccessService $access)
    {
        $openDailyIds = $access->openDailyBoxIds();
        $boxes = $access->availableBoxes($request->user())->map(function (Box $box) use ($openDailyIds) {
            $isDailyOpen = $openDailyIds->contains((int) $box->id);

            return [
                'box_id' => $box->id,
                'box_name' => $box->name,
                'total_balance' => $box->total,
                'currency' => $box->currency,
                'type' => $box->type,
                'is_daily_open' => $isDailyOpen,
                'access_source' => $isDailyOpen ? 'open_daily_session' : 'permission',
            ];
        })->values();

        return response()->json(['status' => 'success', 'boxes' => $boxes]);
    }

    private function mediaStorage(Request $request,$type,$fileName,$path){

        
        $files = [];
       // $imageName = null;
        if ($request->hasFile($fileName)) {
            if($type==='media'){
            foreach ($request->file($fileName) as $file) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';
                $extension = strtolower($file->getClientOriginalExtension());
                $fullName = (string) Str::uuid().($extension ? '.'.$extension : '');
                $file->move(public_path($path . '/' . $folder), $fullName);
                $files[] = $fullName;
              }
           }
        //    elseif($type==='singleFile'){
        //         $imageName = $request->file($fileName)->getClientOriginalName();
        //         $request->file($fileName)->move(public_path($path), $imageName);
        //         $imageName = $imageName;
        //         return $imageName;
        //    }
            elseif($type==='multiImages'){
                foreach($request->file($fileName) as $imageFile){
                    $extension = strtolower($imageFile->getClientOriginalExtension());
                    $imageName = (string) Str::uuid().($extension ? '.'.$extension : '');
                    $imageFile->move(public_path($path), $imageName);
                    $files[] = $imageName;
                }

           }
           

        }

           return $files;

    }

    public function store(Request $request, ExpenseBoxAccessService $access){
        try{
            $data = $request->validate([
                'name'=>'required|string|max:255',
                'expense_type' => 'nullable|string|in:general',
                'expense_date' => 'nullable|date',
                'price'=>'required|numeric|min:1',
                'notes' => 'nullable|string',
//                'payment_method' => 'required|string|max:255',
                'invoice_img' => 'nullable|array|max:10',
                'invoice_img.*' => 'nullable|file|image|max:10240',

                'media' => 'nullable|array|max:15',
                'media.*' => 'file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',

                'box_id' => 'required|integer|exists:boxes,id',
            ]);

            if (! $access->canUse($request->user(), (int) $request->box_id)) {
                throw ValidationException::withMessages([
                    'box_id' => ['الصندوق غير مسموح للموظف أو أن جلسته اليومية مغلقة.'],
                ]);
            }

            $data['expense_type'] = $data['expense_type'] ?? 'general';
            $data['expense_date'] = $data['expense_date'] ?? now()->toDateString();
            $data['created_by_user_id'] = $request->user()->id;

            $box = Box::findOrFail($request->box_id);
            if(!$box->currency || $box->currency !== 'شيكل'){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.box_must_be_shekel'),
                ],200);
            }

            $files = $this->mediaStorage($request,'media','media',$this->expensesMediaPath);
            $invoice_img = $this->mediaStorage($request,'multiImages','invoice_img',$this->invoiceImagesPath);
            $data['media'] = $files;
            $data['invoice_img'] = $invoice_img;

            DB::transaction(function () use ($data, $request) {
                $lockedBox = Box::query()->lockForUpdate()->findOrFail($request->box_id);
                if ((float) $request->price > (float) $lockedBox->total) {
                    throw ValidationException::withMessages([
                        'price' => [__('messages.box_out_of_money')],
                    ]);
                }

                Expense::create($data);
                $lockedBox->decrement('total', $request->price);
                $lockedBox->refresh();

                Logs::createLog('اضافة مصروف جديد','تم اضافة المصروف'.' '.$request->name.' '.'بسعر'.
                    ' '. $request->price, 'expenses');

                BoxLogs::createBoxLog($lockedBox,'تم سحب رصيد من الصندوق لصرف مصروف باسم '.' '.$request->name,
                    'minus',$request->price);
            });

            return response()->json([
                'status'=>'success',
                'message' => __('messages.expense_created'),
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


        public function showExpense(Request $request){
        try{

            $request->validate(['expense_id'=>'required|integer|exists:expenses,id']);

            $expense = Expense::with('box:id,name,total,currency')->
            findOrFail($request->expense_id);

            $formattedMedia  = [];
            if($expense->media && count($expense->media) > 0){
                foreach($expense->media as $file){
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                            $formattedMedia[] = 'public/'.$this->expensesMediaPath.'/images/'.$file;
                        }
                        else{
                            $formattedMedia[] = 'public/'.$this->expensesMediaPath.'/videos/'.$file;

                        }
            }

                }
        $formattedInvoice = [];
        if($expense->invoice_img && count($expense->invoice_img)>0){
            foreach($expense->invoice_img as $invoiceImage){
                $formattedInvoice[] = 'public/'.$this->invoiceImagesPath.'/'.$invoiceImage;
            }
        }
        $expense['media'] = $formattedMedia;
        $expense['invoice_img'] = $formattedInvoice;
        $expense->makeHidden('payment_method','box_id');
        return response()->json([
            'status' =>'success',
            'expense' => $expense,
        ],200);

      }  catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
         } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

        public function editExpense(Request $request){
        try{

            $data = $request->validate([
                'expense_id' => 'required|integer|exists:expenses,id',
                'name'=>'required|string|max:255',
 //               'price'=>'required|numeric|min:1',
 //               'payment_method' => 'required|string',
                'notes' => 'nullable|string',
                'invoice_img' => 'nullable|array',
                'invoice_img.*' => 'nullable',

                'media' => 'nullable|array',
                'media.*' => [
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if (is_string($value)) {
                            // must be a string filename, skip further checks
                            return;
                        }

                        if ($value instanceof \Illuminate\Http\UploadedFile) {
                             $allowed = ['image/jpeg','image/png','image/jpg','image/gif','image/tiff','image/webp','image/avif','image/svg+xml','video/mp4','video/quicktime','video/x-msvideo','video/x-ms-wmv','video/x-matroska','video/webm'];
                            if (! in_array($value->getMimeType(), $allowed)) {
                                $fail("The {$attribute} must be a valid image or video file.");
                            }
                        } else {
                            $fail("The {$attribute} must be either a filename or an uploaded file.");
                        }
                    },
                ],
            ]);

            $expense = Expense::findOrFail($data['expense_id']);
            if ($expense->expense_type === 'salary' || $expense->salary_period_id !== null) {
                throw ValidationException::withMessages([
                    'expense_id' => ['قيد الراتب محمي ويجب إدارته من ملف الراتب.'],
                ]);
            }
            $updatedData = Arr::except($data, ['expense_id', 'media','invoice_img']);
            $expense->update($updatedData);

            $finalMedia = Assets::handleMediaUpdate($request, 'media', $this->expensesMediaPath, $expense->media);
            $invoiceImages = CommonUse::handleImageUpdate($request,'invoice_img',$this->invoiceImagesPath,$expense->invoice_img);
            $expense->update([
                'media' => $finalMedia,
                'invoice_img' => $invoiceImages,
            ]);   
            Logs::createLog('تعديل بيانات مصروف ','تم تعديل بيانات المصروف'.' '.$expense->name.' '.'بسعر'.
            ' '. $expense->price
        
            ,'expenses');
            return response()->json([
                'status'=>'success',
                'message'=> __('messages.expense_updated'),
            ],200);
}

       catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
         } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    private function filteredExpensesQuery(Request $request): Builder
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'expense_type' => 'nullable|string|in:general,salary,destruction',
            'box_id' => 'nullable|integer|exists:boxes,id',
            'employee_id' => 'nullable|integer|exists:employee_details,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|gte:min_price',
        ]);

        return Expense::query()
            ->with('box:id,name,currency,type')
            ->when($data['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            })
            ->when($data['expense_type'] ?? null, fn (Builder $query, string $type) => $query->where('expense_type', $type))
            ->when($data['box_id'] ?? null, fn (Builder $query, int $boxId) => $query->where('box_id', $boxId))
            ->when($data['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employee_id', $employeeId))
            ->when($data['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate(DB::raw('COALESCE(expense_date, created_at)'), '>=', $from))
            ->when($data['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate(DB::raw('COALESCE(expense_date, created_at)'), '<=', $to))
            ->when(isset($data['min_price']), fn (Builder $query) => $query->where('price', '>=', $data['min_price']))
            ->when(isset($data['max_price']), fn (Builder $query) => $query->where('price', '<=', $data['max_price']));
    }

    public function report(Request $request)
    {
        $query = $this->filteredExpensesQuery($request);
        $rows = (clone $query)->get(['id', 'expense_type', 'price', 'box_id', 'employee_id', 'expense_date', 'created_at']);
        $byType = $rows->groupBy(fn (Expense $expense) => $expense->expense_type ?: 'general')
            ->map(fn ($items, $type) => [
                'type' => $type,
                'count' => $items->count(),
                'total' => round((float) $items->sum('price'), 2),
                'average' => round((float) $items->avg('price'), 2),
            ])->values();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'count' => $rows->count(),
                'total' => round((float) $rows->sum('price'), 2),
                'average' => round((float) $rows->avg('price'), 2),
                'by_type' => $byType,
            ],
        ]);
    }

    public function exportReport(Request $request, string $format)
    {
        $rows = $this->filteredExpensesQuery($request)
            ->latest('id')
            ->get();
        $summary = [
            'count' => $rows->count(),
            'total' => round((float) $rows->sum('price'), 2),
            'average' => round((float) ($rows->avg('price') ?? 0), 2),
            'by_type' => $rows->groupBy(fn (Expense $expense) => $expense->expense_type ?: 'general')
                ->map(fn ($items) => round((float) $items->sum('price'), 2)),
        ];
        $filename = 'expenses-report-'.now()->format('Ymd-His');

        if ($format === 'data') {
            return response()->json([
                'status' => 'success',
                'generated_at' => now()->toIso8601String(),
                'filters' => $request->only([
                    'expense_type', 'box_id', 'from', 'to', 'min_price', 'max_price',
                ]),
                'summary' => $summary,
                'rows' => $rows->map(fn (Expense $expense) => [
                    'id' => $expense->id,
                    'date' => $expense->expense_date?->format('Y-m-d')
                        ?? $expense->created_at?->format('Y-m-d'),
                    'expense_type' => $expense->expense_type ?: 'general',
                    'name' => $expense->name,
                    'price' => (float) $expense->price,
                    'box_name' => $expense->box?->name,
                    'currency' => $expense->box?->currency ?: 'شيكل',
                    'notes' => $expense->notes,
                ])->values(),
            ]);
        }

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.expenses-report', [
                'expenses' => $rows,
                'summary' => $summary,
                'filters' => $request->only([
                    'expense_type', 'box_id', 'from', 'to', 'min_price', 'max_price',
                ]),
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->fromArray([
            ['الرقم', 'التاريخ', 'النوع', 'اسم المصروف', 'القيمة', 'الصندوق', 'العملة', 'ملاحظات'],
        ], null, 'A1');

        $typeLabels = ['general' => 'عمومي', 'salary' => 'راتب', 'destruction' => 'إتلاف بضاعة'];
        $rowNumber = 2;
        foreach ($rows as $expense) {
            $sheet->fromArray([[
                $expense->id,
                $expense->expense_date?->format('Y-m-d') ?? $expense->created_at?->format('Y-m-d'),
                $typeLabels[$expense->expense_type ?: 'general'] ?? $expense->expense_type,
                $expense->name,
                (float) $expense->price,
                $expense->box?->name,
                $expense->box?->currency,
                $expense->notes,
            ]], null, 'A'.$rowNumber++);
        }
        $sheet->fromArray([['', '', '', 'الإجمالي', $summary['total']]], null, 'A'.$rowNumber);
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet, $format) {
            $writer = $format === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);
            if ($writer instanceof Csv) {
                $writer->setUseBOM(true)->setDelimiter(',');
            }
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.'.$format, [
            'Content-Type' => $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function getExpenses(Request $request){
        try{
            $expenses = $this->filteredExpensesQuery($request)
                ->latest('id')
                ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));
            $formatted = $expenses->map(function($expense){

                    $imagePath = null;

                    if (is_array($expense->media) && count($expense->media) > 0) {
                        foreach ($expense->media as $file) {
                            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                            if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                                // found the first image → stop searching
                                $imagePath = 'public/'.$this->expensesMediaPath.'/images/' . $file;
                                break;
                            }
                        }
                    }
                return [
                    'id'=>$expense->id,
                    'name' => $expense->name,
                    'price' => $expense->price,
                    'expense_type' => $expense->expense_type ?: 'general',
                    'salary_period_id' => $expense->salary_period_id,
                    'created_at' => $expense->expense_date?->format('Y-m-d')
                        ?? ($expense->created_at?->format('Y-m-d') ?: 'no date'),
                    'image'=> $imagePath,
                    'box' => $expense->box ? [
                        'id' => $expense->box->id,
                        'name' => $expense->box->name,
                        'currency' => $expense->box->currency,
                        'type' => $expense->box->type,
                    ] : null,
                ];
            });
            return response()->json([
                'status'=>'success',
                'expenses' => $formatted,
                'pagination' => [
                    'current_page' => $expenses->currentPage(),
                    'last_page' => $expenses->lastPage(),
                    'per_page' => $expenses->perPage(),
                    'total' => $expenses->total(),
                ],
  
            ]);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
}
