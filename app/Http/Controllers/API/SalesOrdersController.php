<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SalesOrderFulfillmentService;
use App\Services\SalesOrderPartialService;
use App\Services\SalesOrderService;
use App\Services\SalesOrderStatementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesOrdersController extends Controller
{
    public function __construct(
        protected SalesOrderService $service,
        protected SalesOrderFulfillmentService $fulfillmentService,
        protected SalesOrderPartialService $partialService,
        protected SalesOrderStatementService $statementService,
    ) {}

    public function index(Request $request)
    {
        try {
            $filters = $request->validate([
                'status' => 'nullable|string|max:40',
                'search' => 'nullable|string|max:255',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'include_hidden' => 'nullable|boolean',
            ]);

            return response()->json([
                'status' => 'success',
                'sales_orders' => $this->service->list($filters),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function show(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
            ]);

            $order = $this->service->show((int) $data['sales_order_id']);

            return response()->json([
                'status' => 'success',
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function store(Request $request)
    {
        try {
            $order = $this->service->store($request->user(), $request);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_created'),
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
            ]);

            $order = $this->service->update(
                $request->user(),
                (int) $data['sales_order_id'],
                $request
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_updated'),
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function confirm(Request $request)
    {
        return $this->transition($request, 'confirm', __('messages.sales_order_confirmed'));
    }

    public function markReady(Request $request)
    {
        return $this->transition($request, 'ready', __('messages.sales_order_ready'));
    }

    public function handover(Request $request)
    {
        return $this->fulfillmentAction($request, 'handover', __('messages.sales_order_handover'));
    }

    public function deliver(Request $request)
    {
        return $this->fulfillmentAction($request, 'deliver', __('messages.sales_order_delivered'));
    }

    public function settle(Request $request)
    {
        return $this->fulfillmentAction($request, 'settle', __('messages.sales_order_settled'));
    }

    public function archive(Request $request)
    {
        return $this->fulfillmentAction($request, 'archive', __('messages.sales_order_archived'));
    }

    public function uploadMedia(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'media' => 'required|array',
                'media.*' => 'file|max:51200|mimes:jpg,jpeg,png,webp,gif,heic,mp4,mov,avi,mkv,webm',
                'category' => 'nullable|string|in:general,items_group,packaged,testing,document',
            ]);

            $files = $request->file('media', []);
            if (! is_array($files)) {
                $files = $files ? [$files] : [];
            }
            $files = array_values(array_filter($files));
            if ($files === []) {
                $collected = [];
                foreach ($request->allFiles() as $key => $file) {
                    if (! str_starts_with((string) $key, 'media')) {
                        continue;
                    }
                    if (is_array($file)) {
                        foreach ($file as $f) {
                            if ($f) {
                                $collected[] = $f;
                            }
                        }
                    } elseif ($file) {
                        $collected[] = $file;
                    }
                }
                $files = $collected;
            }

            $order = $this->fulfillmentService->uploadMedia(
                $request->user(),
                (int) $data['sales_order_id'],
                $files,
                $data['category'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_media_uploaded'),
                'sales_order' => $this->service->formatDetail($this->service->show($order->id)),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            \Log::error('sales_order.media_upload_failed', [
                'order_id' => $request->input('sales_order_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.something_wrong'),
            ], 200);
        }
    }

    public function cancel(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'note' => 'nullable|string|max:500',
            ]);

            $order = $this->service->cancel(
                $request->user(),
                (int) $data['sales_order_id'],
                $data['note'] ?? null
            );

            $message = $order->status === 'returned'
                ? __('messages.sales_order_returned')
                : __('messages.sales_order_canceled');

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'sales_order' => $this->service->formatDetail($this->service->show($order->id)),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function revertStatus(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'note' => 'nullable|string|max:500',
            ]);

            $order = $this->service->revertStatus(
                $request->user(),
                (int) $data['sales_order_id'],
                $data['note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_reverted'),
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function postpone(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'postponed_until' => 'required|date|after:now',
                'reason' => 'nullable|string|max:500',
            ]);

            $order = $this->service->postpone(
                $request->user(),
                (int) $data['sales_order_id'],
                $data['postponed_until'],
                $data['reason'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_postponed'),
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function partialDeliver(Request $request)
    {
        return $this->partialAction($request, 'partial_deliver', __('messages.sales_order_partial_delivered'));
    }

    public function followUp(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
            ]);

            $child = $this->partialService->createFollowUpOrder(
                $request->user(),
                (int) $data['sales_order_id']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_follow_up_created'),
                'sales_order' => $this->service->formatDetail($this->service->show((int) $data['sales_order_id'])),
                'follow_up_order' => [
                    'id' => $child->id,
                    'serial_number' => $child->serial_number,
                    'status' => $child->status,
                    'total' => (float) $child->total,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function partialReturn(Request $request)
    {
        return $this->partialAction($request, 'partial_return', __('messages.sales_order_partial_returned'));
    }

    public function alternativeReturn(Request $request)
    {
        return $this->partialAction($request, 'alternative_return', __('messages.sales_order_alternative_returned'));
    }

    public function statement(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
            ]);

            $order = $this->service->show((int) $data['sales_order_id']);
            $report = $this->statementService->generatePdfUrl($order);

            return response()->json([
                'status' => 'success',
                'report' => $report,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.sales_order_statement_failed'),
            ], 200);
        }
    }

    private function partialAction(Request $request, string $action, string $message)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'items' => 'nullable|array',
                'items.*.item_id' => 'required_with:items|integer',
                'items.*.quantity' => 'required_with:items|integer|min:1',
                'replacement_items' => 'nullable|array',
                'replacement_items.*.product_id' => 'required_with:replacement_items|integer|exists:products,id',
                'replacement_items.*.quantity' => 'required_with:replacement_items|integer|min:1',
                'replacement_items.*.unit_price' => 'required_with:replacement_items|numeric|min:0',
                'replacement_items.*.size_id' => 'nullable|integer',
                'replacement_items.*.size_color_id' => 'nullable|integer',
                'note' => 'nullable|string|max:500',
                'payment_amount' => 'nullable|numeric|min:0',
                'payment_box_id' => 'nullable|integer|exists:boxes,id',
            ]);

            $orderId = (int) $data['sales_order_id'];
            $user = $request->user();

            $order = match ($action) {
                'partial_deliver' => $this->partialService->partialDeliver(
                    $user,
                    $orderId,
                    $data['items'] ?? [],
                    $data
                ),
                'partial_return' => $this->partialService->partialReturn(
                    $user,
                    $orderId,
                    $data['items'] ?? [],
                    $data['note'] ?? null
                ),
                'alternative_return' => $this->partialService->alternativeReturn(
                    $user,
                    $orderId,
                    $data['items'] ?? [],
                    $data['replacement_items'] ?? [],
                    $data['note'] ?? null
                ),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'sales_order' => $this->service->formatDetail($this->service->show($order->id)),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function markStuck(Request $request)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'reason' => 'nullable|string|max:500',
            ]);

            $order = $this->service->markStuck(
                $request->user(),
                (int) $data['sales_order_id'],
                $data['reason'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_marked_stuck'),
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function bulkStatus(Request $request)
    {
        try {
            $data = $request->validate([
                'order_ids' => 'required|array|min:1|max:50',
                'order_ids.*' => 'integer|exists:sales_orders,id',
                'action' => 'required|string|in:confirm,mark_ready,cancel',
            ]);

            $result = $this->service->bulkStatusAction(
                $request->user(),
                array_map('intval', $data['order_ids']),
                (string) $data['action']
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.sales_order_bulk_done', ['count' => $result['updated']]),
                'updated' => $result['updated'],
                'failed' => $result['failed'],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function transition(Request $request, string $action, string $message)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
            ]);

            $order = match ($action) {
                'confirm' => $this->service->confirm($request->user(), (int) $data['sales_order_id']),
                'ready' => $this->service->markReady($request->user(), (int) $data['sales_order_id']),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'sales_order' => $this->service->formatDetail($order),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            \Log::error('sales_order.transition_failed', [
                'action' => $action,
                'order_id' => $request->input('sales_order_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.something_wrong'),
            ], 200);
        }
    }

    private function fulfillmentAction(Request $request, string $action, string $message)
    {
        try {
            $data = $request->validate([
                'sales_order_id' => 'required|integer|exists:sales_orders,id',
                'delivery_company_id' => 'nullable|integer|exists:delivery_companies,id',
                'delivery_company_name' => 'nullable|string|max:255',
                'tracking_number' => 'nullable|string|max:100',
                'carrier_delivery_cost' => 'nullable|numeric|min:0',
                'payment_amount' => 'nullable|numeric|min:0',
                'payment_box_id' => 'nullable|integer|exists:boxes,id',
                'delivery_settled_amount' => 'nullable|numeric|min:0',
            ]);

            $orderId = (int) $data['sales_order_id'];
            $user = $request->user();

            $order = match ($action) {
                'handover' => $this->fulfillmentService->handoverToDelivery($user, $orderId, $data),
                'deliver' => $this->fulfillmentService->markDelivered($user, $orderId, $data),
                'settle' => $this->fulfillmentService->settleDelivery($user, $orderId, $data),
                'archive' => $this->fulfillmentService->archive($user, $orderId),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'sales_order' => $this->service->formatDetail($this->service->show($order->id)),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            \Log::error('sales_order.fulfillment_failed', [
                'action' => $action,
                'order_id' => $request->input('sales_order_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : __('messages.something_wrong'),
            ], 200);
        }
    }
}
