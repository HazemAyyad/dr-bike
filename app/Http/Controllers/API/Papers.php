<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\FileBox;
use App\Models\Paper;
use App\Models\Treasury;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class Papers extends Controller
{


    private  function storeImage(Request $request,$fileName,$path){
        $imgNames = [];
        if ($request->hasFile($fileName)) {
            foreach($request->file($fileName) as $file){
                $extension = strtolower($file->getClientOriginalExtension());
                $fullName = (string) Str::uuid().($extension ? '.'.$extension : '');
                $file->move(public_path($path.'/'), $fullName);
                $imgNames[] = $fullName;
            }
        }

        return $imgNames;
    }

    private function appendImagesWithoutDeleting(Request $request, Paper $paper): array
    {
        $files = collect($paper->img ?? [])->filter()->map(fn ($file) => basename((string) $file));

        foreach ((array) $request->input('img', []) as $existing) {
            if (is_string($existing) && $existing !== '') {
                $files->push(basename($existing));
            }
        }

        if ($request->hasFile('img')) {
            foreach ($request->file('img') as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $name = (string) Str::uuid().($extension ? '.'.$extension : '');
                $file->move(public_path('Papers'), $name);
                $files->push($name);
            }
        }

        return $files->unique()->values()->all();
    }
    public function store(Request $request){
        try{
            $data = $request->validate([
                'name'=>'required|string|max:255',
                'file_id' => 'required|integer|exists:files,id',
                'img'=>'nullable|array|max:20',
                'img.*' => 'required|file|image|max:10240',
                'notes'=>'nullable|string',

            ]);

        // // Step 1: Check if file_box belongs to treasury
        // $fileBox = FileBox::where('id', $data['file_box_id'])
        //     ->where('treasury_id', $data['treasury_id'])
        //     ->first();

        // if (!$fileBox) {
        //     return response()->json([
        //         'status'=>'error',
        //         'message'=>__('messages.file_box_not_for_treasury_selected'),
        //     ],200);
        // }

        // //  Step 2: Check if file belongs to file_box
        // $file = File::where('id', $data['file_id'])
        //     ->where('file_box_id', $data['file_box_id'])
        //     ->first();

        // if (!$file) {
        //     return response()->json([
        //         'status'=>'error',
        //         'message'=>__('messages.file_not_for_fil_box_selected'),
        //     ],200);
        // }
            $imageNames = $this->storeImage($request,'img','Papers');
            $data['img'] = $imageNames;
            Paper::create($data);

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.paper_created'),
            ]);


        }

        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200);
        } 
        
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        }
        
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }


    public function getPapers(Request $request){
        try{
            $filters = $request->validate([
                'search' => 'nullable|string|max:255',
                'treasury_id' => 'nullable|integer|exists:treasuries,id',
                'file_box_id' => 'nullable|integer|exists:file_boxes,id',
                'file_id' => 'nullable|integer|exists:files,id',
                'status' => 'nullable|string|in:active,archived,all',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'has_media' => 'nullable|boolean',
            ]);
            $status = $filters['status'] ?? 'active';
            $papers = Paper::query()
            ->with('file.fileBox.treasury:id,name')
            ->when($status === 'active', fn ($q) => $q->where('is_cancelled', 0))
            ->when($status === 'archived', fn ($q) => $q->where('is_cancelled', 1))
            ->when($filters['treasury_id'] ?? null, fn ($q, $id) => $q->where(fn ($nested) => $nested->where('treasury_id', $id)->orWhereHas('file.fileBox', fn ($box) => $box->where('treasury_id', $id))))
            ->when($filters['file_box_id'] ?? null, fn ($q, $id) => $q->where(fn ($nested) => $nested->where('file_box_id', $id)->orWhereHas('file', fn ($file) => $file->where('file_box_id', $id))))
            ->when($filters['file_id'] ?? null, fn ($q, $id) => $q->where('file_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%")))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when(isset($filters['has_media']), fn ($q) => $filters['has_media'] ? $q->whereNotNull('img') : $q->whereNull('img'))
            ->latest('id')
            ->get(['id','treasury_id','file_box_id','file_id','name','img','created_at','notes','is_cancelled']);

            $formatted = $papers->map(function($paper){
                $images = [];
                if($paper->img && count($paper->img)>0){
                    foreach($paper->img as $img){
                        $images[] = 'public/Papers/'.$img;
                    }
                }
                return [
                    'paper_id'=>$paper->id,
                    'paper_name'=>$paper->name,
                    'treasury_name'=>$paper->file?->fileBox?->treasury?->name,
                    'file_box_name'=>$paper->file?->fileBox?->name,
                    'file_name'=>$paper->file?->name,

                    'img'=>$images,
                    'created_at' => $paper->created_at? $paper->created_at->format('Y-m-d'):'no date',
                    'note' => $paper->notes,
                    'is_cancelled' => (bool) $paper->is_cancelled,
                ];
            });

            return response()->json([
                'status'=>'success',
                'papers'=>$formatted,
            ],200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

    }


    public function cancelPaper(Request $request){
        try{

            $request->validate(['paper_id'=>'required|integer|exists:papers,id']);

            $paper = Paper::findOrFail($request->paper_id);
            $paper->update(['is_cancelled'=>1]);

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.paper_cancelled'),
            ],200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } 
        
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function showPaper(Request $request){
        try{
            $request->validate(['paper_id'=>'required|integer|exists:papers,id']);

            $paper = Paper::findOrFail($request->paper_id);
            $images = [];
                if($paper->img && count($paper->img)>0){
                    foreach($paper->img as $img){
                        $images[] = 'public/Papers/'.$img;
                    }
                }
            $formatted =  [
                    'paper_id'=>$paper->id,
                    'paper_name'=>$paper->name,
                    'treasury_name'=>$paper->file->fileBox->treasury->name,
                    'file_box_name'=>$paper->file->fileBox->name,
                    'file_name'=>$paper->file->name,

                    'img'=>$images,
                    'created_at' => $paper->created_at? $paper->created_at->format('Y-m-d'):'no date',

                ];
            return response()->json([
                'status'=>'success',
                'paper'=> $formatted,
            ],200);
          

        }

             catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } 
        
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }


        public function editPaper(Request $request){
        try{

            $data = $request->validate([
                'paper_id'=>'required|integer|exists:papers,id',
                'name'=>'required|string|max:255',
                'file_id' => 'required|integer|exists:files,id',
                'img'=>'nullable|array|max:20',
                'img.*' => [
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if (is_string($value)) {
                            return; // Existing image reference; it is preserved.
                        }
                        if (! $value instanceof \Illuminate\Http\UploadedFile || ! str_starts_with((string) $value->getMimeType(), 'image/')) {
                            $fail("The {$attribute} must be an image or an existing image reference.");
                            return;
                        }
                        if ($value->getSize() > 10 * 1024 * 1024) {
                            $fail("The {$attribute} may not be greater than 10 MB.");
                        }
                    },
                ],
                'notes'=>'nullable|string',
            ]);

            $paper = Paper::findOrFail($request->paper_id);
            $data['img'] = $this->appendImagesWithoutDeleting($request, $paper);
            $paper->update($data);
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.paper_updated'),
            ],200);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()

            ], 200);
        } 
        
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
}
