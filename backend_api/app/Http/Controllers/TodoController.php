<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TodoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Todo::where('user_id', $user->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $todos = $query->paginate($perPage);

        $todos->getCollection()->transform(function ($todo) {
            if ($todo->pdf_path) {
                $todo->pdf_url = Storage::disk('public')->url($todo->pdf_path);
            }
            return $todo;
        });

        return response()->json($todos);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:pending,completed',
            'pdf' => 'nullable|file|mimes:pdf|max:20480', // 20MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $pdfPath = null;
            $originalFileName = null;
            
            if ($request->hasFile('pdf')) {
                $file = $request->file('pdf');
                $originalFileName = $file->getClientOriginalName();
                
                // Generate unique filename to avoid collisions
                $fileName = Str::uuid() . '_' . $originalFileName;
                $pdfPath = $file->storeAs('pdfs', $fileName, 'public');
            }

            $todo = Todo::create([
                'user_id' => $request->user()->id,
                'title' => $request->title,
                'description' => $request->description,
                'status' => $request->status ?? 'pending',
                'pdf_path' => $pdfPath,
                'pdf_original_name' => $originalFileName,
            ]);

            if ($todo->pdf_path) {
                $todo->pdf_url = Storage::disk('public')->url($todo->pdf_path);
            }

            return response()->json($todo, 201);

        } catch (\Exception $e) {
            if ($pdfPath && Storage::disk('public')->exists($pdfPath)) {
                Storage::disk('public')->delete($pdfPath);
            }
            
            return response()->json([
                'message' => 'Failed to create todo',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->findOrFail($id);
        
        if ($todo->pdf_path) {
            $todo->pdf_url = Storage::disk('public')->url($todo->pdf_path);
        }
        
        return response()->json($todo);
    }

    public function update(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:pending,completed',
            'pdf' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $updateData = $request->only(['title', 'description', 'status']);
            
            if ($request->hasFile('pdf')) {
                if ($todo->pdf_path) {
                    Storage::disk('public')->delete($todo->pdf_path);
                }
                
                $file = $request->file('pdf');
                $originalFileName = $file->getClientOriginalName();
                $fileName = Str::uuid() . '_' . $originalFileName;
                $pdfPath = $file->storeAs('pdfs', $fileName, 'public');
                
                $updateData['pdf_path'] = $pdfPath;
                $updateData['pdf_original_name'] = $originalFileName;
            }

            $todo->update($updateData);

            if ($todo->pdf_path) {
                $todo->pdf_url = Storage::disk('public')->url($todo->pdf_path);
            }

            return response()->json($todo);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update todo',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->findOrFail($id);
        
        try {
            if ($todo->pdf_path) {
                Storage::disk('public')->delete($todo->pdf_path);
            }
            
            $todo->delete();
            
            return response()->json(['message' => 'Todo deleted successfully.']);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete todo',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:todos,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $todos = Todo::where('user_id', $request->user()->id)
                         ->whereIn('id', $request->ids)
                         ->get();

            foreach ($todos as $todo) {
                if ($todo->pdf_path) {
                    Storage::disk('public')->delete($todo->pdf_path);
                }
            }

            $deletedCount = Todo::where('user_id', $request->user()->id)
                               ->whereIn('id', $request->ids)
                               ->delete();

            return response()->json([
                'message' => "{$deletedCount} todos deleted successfully."
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete todos',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function downloadPdf(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->findOrFail($id);
        
        if (!$todo->pdf_path || !Storage::disk('public')->exists($todo->pdf_path)) {
            return response()->json(['message' => 'PDF not found'], 404);
        }

        return Storage::disk('public')->download(
            $todo->pdf_path, 
            $todo->pdf_original_name ?? 'todo-attachment.pdf'
        );
    }
}