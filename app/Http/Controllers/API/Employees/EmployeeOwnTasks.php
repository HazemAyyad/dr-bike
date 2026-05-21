<?php

namespace App\Http\Controllers\API\Employees;

use App\Http\Controllers\API\CommonUse;
use App\Http\Controllers\Controller;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeOwnTasks extends Controller
{


    public function editEmployeeTasksImages(Request $request){
        try{
            $request->validate([
                'employee_task_id'=>'required|integer|exists:employee_tasks,id',

                'employee_img' => ['nullable','array'],
                'employee_img.*' => ['nullable'],

            ]);
            $user = $request->user();
            $employee = $user->employee;

            $task = EmployeeTask::
            findOrFail($request->employee_task_id);
            if($task->employee_id != $employee->id){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.unauthorized'),
                ]);
            }

        $finalEmployeeImages = CommonUse::handleImageUpdate(
            $request,
            'employee_img',
            'EmployeeTasksImages',
            $task->employee_img ?? []
        );

        $task->employee_img = $finalEmployeeImages;
        $task->save();
        // EmployeeTask::where('parent_id',$task->id)
        // ->update(['employee_img' => $finalEmployeeImages]);

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.employee_images_updated'),
        ],200);

        }


    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);

   }

       catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')], 200);
        }

       catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);        }

    
    }



        public function editEmployeeSubTasksImages(Request $request){
        try{
            $request->validate([
                'sub_employee_task_id' => 'required|integer|exists:sub_employee_tasks,id',
            ]);
            $user = $request->user();
            $employee = $user->employee;

            $subTask = EmployeeSubTask::
            findOrFail($request->sub_employee_task_id);
            if($subTask->employeeTask->employee_id != $employee->id){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.unauthorized'),
                ]);
            }

        $subTask->employee_img = self::storeEmployeeProofImages(
            $request,
            'EmployeeSubTasks/EmployeeImages',
            $subTask->employee_img ?? []
        );
        $subTask->save();

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.employee_sub_task_images_updated'),
        ],200);

        }


    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);

   }

       catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')], 200);
        }

       catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);        }

    
    }

    /**
     * Accept employee_img or employee_img[] from mobile multipart uploads.
     *
     * @return array<int, string>
     */
    private static function storeEmployeeProofImages(
        Request $request,
        string $path,
        array $currentFiles = []
    ): array {
        $newFiles = [];
        foreach (['employee_img', 'employee_img[]'] as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }
            $uploaded = $request->file($field);
            $list = is_array($uploaded) ? $uploaded : [$uploaded];
            foreach ($list as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                    $storedName = self::safeProofStoredFilename($file);
                    $file->move(public_path($path), $storedName);
                    $newFiles[] = $storedName;
                }
            }
        }

        return array_values(array_unique(array_merge($currentFiles, $newFiles)));
    }

    private static function safeProofStoredFilename(\Illuminate\Http\UploadedFile $file): string
    {
        $name = trim((string) $file->getClientOriginalName());
        $name = $name !== '' ? basename($name) : '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === '') {
            $mime = strtolower((string) $file->getMimeType());
            $ext = match (true) {
                str_contains($mime, 'quicktime') => 'mov',
                str_contains($mime, 'webm') => 'webm',
                str_contains($mime, 'avi') => 'avi',
                str_contains($mime, 'video') => 'mp4',
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'gif') => 'gif',
                str_contains($mime, 'webp') => 'webp',
                default => 'jpg',
            };
            $name = 'proof_'.uniqid('', true).'.'.$ext;
        }

        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?: 'proof_'.uniqid('', true).'.jpg';
    }

    public function editOccurrenceTaskImages(Request $request)
    {
        try {
            $request->validate([
                'occurrence_id' => 'required|integer|exists:employee_task_occurrences,id',
            ]);

            $employee = $request->user()->employee;
            $task = EmployeeTaskOccurrence::findOrFail($request->occurrence_id);

            if (! $employee || $task->employee_id != $employee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $task->employee_img = self::storeEmployeeProofImages(
                $request,
                'EmployeeTasksImages',
                $task->employee_img ?? []
            );
            $task->save();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_images_updated'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function editOccurrenceSubtaskImages(Request $request)
    {
        try {
            $request->validate([
                'sub_task_id' => 'required|integer|exists:employee_task_occurrence_subtasks,id',
            ]);

            $employee = $request->user()->employee;
            $subTask = EmployeeTaskOccurrenceSubtask::with('occurrence')
                ->findOrFail($request->sub_task_id);

            if (! $employee || $subTask->occurrence->employee_id != $employee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $subTask->employee_img = self::storeEmployeeProofImages(
                $request,
                'EmployeeSubTasks/EmployeeImages',
                $subTask->employee_img ?? []
            );
            $subTask->save();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_sub_task_images_updated'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
