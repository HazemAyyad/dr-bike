<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;

class WhatsAppTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = WhatsAppTemplate::query()->orderBy('name');
        if ($request->filled('active')) $query->where('is_active', $request->boolean('active'));
        return response()->json(['status' => 'success', 'templates' => $query->get()]);
    }

    public function store(Request $request)
    {
        $template = WhatsAppTemplate::query()->create($this->validated($request));
        return response()->json(['status' => 'success', 'template' => $template], 201);
    }

    public function update(Request $request, int $id)
    {
        $template = WhatsAppTemplate::query()->findOrFail($id);
        $template->update($this->validated($request, $id));
        return response()->json(['status' => 'success', 'template' => $template->fresh()]);
    }

    public function destroy(int $id)
    {
        WhatsAppTemplate::query()->findOrFail($id)->delete();
        return response()->json(['status' => 'success']);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255|unique:whatsapp_templates,name'.($id ? ','.$id : ''),
            'category' => 'nullable|string|max:255',
            'language' => 'required|string|max:16',
            'body' => 'required|string',
            'variables' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);
    }
}
