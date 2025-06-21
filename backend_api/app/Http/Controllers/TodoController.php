<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\TodoPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TodoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Todo::where('user_id', $user->id)->with('pdfs');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->has('due_date')) {
            $query->whereDate('due_date', $request->due_date);
        }

        if ($request->has('overdue') && $request->overdue) {
            $query->where('due_date', '<', now())->where('status', '!=', 'completed');
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $todos = $query->paginate($perPage);

        // Add computed attributes
        $todos->getCollection()->transform(function ($todo) {
            $todo->is_overdue = $todo->isOverdue();
            $todo->is_due_today = $todo->isDueToday();
            $todo->priority_color = $todo->priority_color;
            $todo->status_color = $todo->status_color;
            return $todo;
        });

        return response()->json($todos);
    }

    public function store(Request $request)
    {
        // Enhanced validation for multiple PDF uploads
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:pending,completed',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'due_date' => 'nullable|date|after_or_equal:today',
            // Handle both single and multiple file uploads
            'pdfs' => 'nullable|array|max:10',
            'pdfs.*' => 'file|mimes:pdf|max:20480', // Each file must be PDF, max 20MB
            'pdf' => 'nullable|file|mimes:pdf|max:20480', // Single file upload
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $todo = Todo::create([
                'user_id' => $request->user()->id,
                'title' => $request->title,
                'description' => $request->description,
                'status' => $request->status ?? 'pending',
                'priority' => $request->priority ?? 'medium',
                'due_date' => $request->due_date,
            ]);

            // Handle PDF uploads
            $uploadedPdfs = [];
            $pdfFiles = $this->getPdfFiles($request);
            
            if (!empty($pdfFiles)) {
                foreach ($pdfFiles as $index => $pdfFile) {
                    if ($this->isValidPdfFile($pdfFile)) {
                        try {
                            $uploadedPdf = $this->savePdfFile($todo->id, $pdfFile);
                            if ($uploadedPdf) {
                                $uploadedPdfs[] = $uploadedPdf;
                                
                                Log::info("PDF uploaded successfully", [
                                    'todo_id' => $todo->id,
                                    'file_name' => $pdfFile->getClientOriginalName(),
                                    'file_path' => $uploadedPdf->pdf_path
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::error("Failed to upload PDF", [
                                'todo_id' => $todo->id,
                                'file_index' => $index,
                                'error' => $e->getMessage()
                            ]);
                            // Continue with other files even if one fails
                        }
                    } else {
                        Log::warning("Invalid PDF file at index {$index}");
                    }
                }
            }

            // Load relationships and add computed attributes
            $todo->load('pdfs');
            $todo->is_overdue = $todo->isOverdue();
            $todo->is_due_today = $todo->isDueToday();
            $todo->priority_color = $todo->priority_color;
            $todo->status_color = $todo->status_color;
            
            // Add upload summary
            $todo->upload_summary = [
                'total_files_processed' => count($pdfFiles),
                'successful_uploads' => count($uploadedPdfs),
                'failed_uploads' => count($pdfFiles) - count($uploadedPdfs)
            ];

            return response()->json($todo, 201);

        } catch (\Exception $e) {
            Log::error("Failed to create todo", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Failed to create todo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)
                    ->with('pdfs')
                    ->findOrFail($id);
        
        // Add computed attributes
        $todo->is_overdue = $todo->isOverdue();
        $todo->is_due_today = $todo->isDueToday();
        $todo->priority_color = $todo->priority_color;
        $todo->status_color = $todo->status_color;
        
        return response()->json($todo);
    }

    public function update(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->findOrFail($id);
        
        // Enhanced validation for multiple PDF uploads
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:pending,completed',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            // Handle both single and multiple file uploads
            'pdfs' => 'nullable|array|max:10',
            'pdfs.*' => 'file|mimes:pdf|max:20480', // Each file must be PDF, max 20MB
            'pdf' => 'nullable|file|mimes:pdf|max:20480', // Single file upload
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $updateData = $request->only(['title', 'description', 'status', 'priority', 'due_date']);
            $todo->update($updateData);

            // Handle additional PDF uploads
            $uploadedPdfs = [];
            $pdfFiles = $this->getPdfFiles($request);
            
            if (!empty($pdfFiles)) {
                foreach ($pdfFiles as $index => $pdfFile) {
                    if ($this->isValidPdfFile($pdfFile)) {
                        try {
                            $uploadedPdf = $this->savePdfFile($todo->id, $pdfFile);
                            if ($uploadedPdf) {
                                $uploadedPdfs[] = $uploadedPdf;
                                
                                Log::info("PDF uploaded successfully on update", [
                                    'todo_id' => $todo->id,
                                    'file_name' => $pdfFile->getClientOriginalName(),
                                    'file_path' => $uploadedPdf->pdf_path
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::error("Failed to upload PDF on update", [
                                'todo_id' => $todo->id,
                                'file_index' => $index,
                                'error' => $e->getMessage()
                            ]);
                            // Continue with other files even if one fails
                        }
                    } else {
                        Log::warning("Invalid PDF file at index {$index} on update");
                    }
                }
            }

            // Load relationships and add computed attributes
            $todo->load('pdfs');
            $todo->is_overdue = $todo->isOverdue();
            $todo->is_due_today = $todo->isDueToday();
            $todo->priority_color = $todo->priority_color;
            $todo->status_color = $todo->status_color;
            
            // Add upload summary if files were processed
            if (!empty($pdfFiles)) {
                $todo->upload_summary = [
                    'total_files_processed' => count($pdfFiles),
                    'successful_uploads' => count($uploadedPdfs),
                    'failed_uploads' => count($pdfFiles) - count($uploadedPdfs)
                ];
            }

            return response()->json($todo);

        } catch (\Exception $e) {
            Log::error("Failed to update todo", [
                'todo_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Failed to update todo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->with('pdfs')->findOrFail($id);
        
        try {
            // Delete all associated PDFs
            foreach ($todo->pdfs as $pdf) {
                if (Storage::disk('public')->exists($pdf->pdf_path)) {
                    Storage::disk('public')->delete($pdf->pdf_path);
                }
                $pdf->delete();
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
                         ->with('pdfs')
                         ->get();

            // Delete all associated PDFs
            foreach ($todos as $todo) {
                foreach ($todo->pdfs as $pdf) {
                    if (Storage::disk('public')->exists($pdf->pdf_path)) {
                        Storage::disk('public')->delete($pdf->pdf_path);
                    }
                    $pdf->delete();
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
        $pdfId = $request->get('pdf_id');
        
        if ($pdfId) {
            // Download specific PDF
            $pdf = $todo->pdfs()->findOrFail($pdfId);
        } else {
            // Download first PDF if no specific ID provided
            $pdf = $todo->pdfs()->first();
        }
        
        if (!$pdf || !Storage::disk('public')->exists($pdf->pdf_path)) {
            return response()->json(['message' => 'PDF not found'], 404);
        }

        return Storage::disk('public')->download(
            $pdf->pdf_path, 
            $pdf->original_name
        );
    }

    public function deletePdf(Request $request, $id)
    {
        $todo = Todo::where('user_id', $request->user()->id)->findOrFail($id);
        $pdfId = $request->get('pdf_id');
        
        if (!$pdfId) {
            return response()->json(['message' => 'PDF ID is required'], 400);
        }
        
        $pdf = $todo->pdfs()->findOrFail($pdfId);
        
        try {
            if (Storage::disk('public')->exists($pdf->pdf_path)) {
                Storage::disk('public')->delete($pdf->pdf_path);
            }
            
            $pdf->delete();
            
            return response()->json(['message' => 'PDF deleted successfully.']);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete PDF',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function getStatusOptions()
    {
        return response()->json(Todo::getStatusOptions());
    }

    public function getPriorityOptions()
    {
        return response()->json(Todo::getPriorityOptions());
    }

    /**
     * Enhanced debug method to test PDF upload
     */
    public function debugPdfUpload(Request $request)
    {
        // Log the request details
        Log::info('Debug PDF Upload - Request started', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->header('User-Agent'),
        ]);

        $debug = [
            'request_method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'has_file_pdfs' => $request->hasFile('pdfs'),
            'has_file_pdf' => $request->hasFile('pdf'),
            'all_files' => $request->allFiles(),
            'pdfs_raw' => $request->file('pdfs'),
            'pdf_raw' => $request->file('pdf'),
            'request_content' => $request->all(),
        ];

        // Log file detection
        Log::info('Debug PDF Upload - File detection', [
            'has_file_pdfs' => $request->hasFile('pdfs'),
            'has_file_pdf' => $request->hasFile('pdf'),
            'all_files_keys' => array_keys($request->allFiles()),
        ]);

        $pdfFiles = $this->getPdfFiles($request);
        $debug['processed_pdf_files'] = count($pdfFiles);
        
        // Log processed files
        Log::info('Debug PDF Upload - Processed files', [
            'total_pdf_files' => count($pdfFiles),
            'pdf_files_array' => $pdfFiles ? 'not_empty' : 'empty',
        ]);

        if (!empty($pdfFiles)) {
            $debug['pdf_details'] = [];
            foreach ($pdfFiles as $index => $pdfFile) {
                Log::info("Debug PDF Upload - Processing file {$index}", [
                    'file_exists' => $pdfFile ? 'yes' : 'no',
                    'file_object_type' => $pdfFile ? get_class($pdfFile) : 'null',
                ]);

                if ($this->isValidPdfFile($pdfFile)) {
                    $fileDetails = [
                        'original_name' => $pdfFile->getClientOriginalName(),
                        'size' => $pdfFile->getSize(),
                        'mime_type' => $pdfFile->getMimeType(),
                        'extension' => $pdfFile->getClientOriginalExtension(),
                        'is_valid' => $pdfFile->isValid(),
                        'error' => $pdfFile->getError(),
                        'status' => 'Valid'
                    ];
                    
                    $debug['pdf_details'][$index] = $fileDetails;
                    
                    Log::info("Debug PDF Upload - File {$index} is valid", $fileDetails);
                } else {
                    $invalidDetails = [
                        'status' => 'Invalid file',
                        'file_object' => $pdfFile ? 'exists' : 'null',
                        'error' => $pdfFile ? $pdfFile->getError() : 'File is null',
                        'is_valid' => $pdfFile ? $pdfFile->isValid() : false,
                        'mime_type' => $pdfFile ? $pdfFile->getMimeType() : 'unknown',
                        'size' => $pdfFile ? $pdfFile->getSize() : 0,
                    ];
                    
                    $debug['pdf_details'][$index] = $invalidDetails;
                    
                    Log::warning("Debug PDF Upload - File {$index} is invalid", $invalidDetails);
                }
            }
        } else {
            Log::warning('Debug PDF Upload - No PDF files found in request');
            
            // Log all request data for debugging
            Log::info('Debug PDF Upload - Full request data', [
                'all_input' => $request->all(),
                'all_files' => $request->allFiles(),
                'headers' => $request->headers->all(),
            ]);
        }

        // Log the final debug response
        Log::info('Debug PDF Upload - Response prepared', [
            'debug_response_keys' => array_keys($debug),
            'pdf_files_count' => $debug['processed_pdf_files'],
        ]);

        return response()->json($debug);
    }

    /**
     * Helper method to extract PDF files from request
     * Handles both single and multiple file uploads
     */
    private function getPdfFiles(Request $request): array
    {
        $pdfFiles = [];

        Log::info('getPdfFiles - Starting file extraction', [
            'has_file_pdfs' => $request->hasFile('pdfs'),
            'has_file_pdf' => $request->hasFile('pdf'),
            'all_files_keys' => array_keys($request->allFiles()),
        ]);

        // Handle multiple file uploads (pdfs[])
        if ($request->hasFile('pdfs')) {
            $files = $request->file('pdfs');
            
            Log::info('getPdfFiles - Processing pdfs field', [
                'files_type' => gettype($files),
                'files_is_array' => is_array($files),
                'files_count' => is_array($files) ? count($files) : 1,
            ]);
            
            if (is_array($files)) {
                $pdfFiles = array_merge($pdfFiles, $files);
                Log::info('getPdfFiles - Added array of files', ['count' => count($files)]);
            } else {
                $pdfFiles[] = $files;
                Log::info('getPdfFiles - Added single file from pdfs field');
            }
        }

        // Handle single file upload (pdf)
        if ($request->hasFile('pdf')) {
            $singleFile = $request->file('pdf');
            $pdfFiles[] = $singleFile;
            
            Log::info('getPdfFiles - Added single file from pdf field', [
                'file_exists' => $singleFile ? 'yes' : 'no',
                'file_type' => $singleFile ? get_class($singleFile) : 'null',
            ]);
        }

        Log::info('getPdfFiles - Final result', [
            'total_files_found' => count($pdfFiles),
            'files_array' => $pdfFiles ? 'not_empty' : 'empty',
        ]);

        return $pdfFiles;
    }

    /**
     * Helper method to validate PDF file
     */
    private function isValidPdfFile($file): bool
    {
        $validation = [
            'file_exists' => $file ? 'yes' : 'no',
            'file_type' => $file ? get_class($file) : 'null',
            'is_valid' => $file ? $file->isValid() : false,
            'mime_type' => $file ? $file->getMimeType() : 'unknown',
            'size' => $file ? $file->getSize() : 0,
            'max_size' => 20971520, // 20MB in bytes
            'size_ok' => $file ? ($file->getSize() <= 20971520) : false,
            'mime_ok' => $file ? ($file->getMimeType() === 'application/pdf') : false,
        ];

        $isValid = $file && 
                   $file->isValid() && 
                   $file->getMimeType() === 'application/pdf' &&
                   $file->getSize() <= 20971520; // 20MB in bytes

        Log::info('isValidPdfFile - Validation result', [
            'is_valid' => $isValid,
            'validation_details' => $validation,
        ]);

        return $isValid;
    }

    /**
     * Helper method to save PDF file
     */
    private function savePdfFile(int $todoId, $pdfFile): ?TodoPdf
    {
        try {
            $originalFileName = $pdfFile->getClientOriginalName();
            $fileName = Str::uuid() . '_' . $originalFileName;
            
            // Store the file
            $pdfPath = $pdfFile->storeAs('pdfs', $fileName, 'public');
            
            if (!$pdfPath) {
                throw new \Exception('Failed to store PDF file');
            }

            // Create database record
            $todoPdf = TodoPdf::create([
                'todo_id' => $todoId,
                'pdf_path' => $pdfPath,
                'original_name' => $originalFileName,
                'file_size' => $pdfFile->getSize(),
                'mime_type' => $pdfFile->getMimeType(),
            ]);

            return $todoPdf;

        } catch (\Exception $e) {
            Log::error("Failed to save PDF file", [
                'todo_id' => $todoId,
                'original_name' => $pdfFile->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}