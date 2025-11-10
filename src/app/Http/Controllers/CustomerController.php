<?php

namespace App\Http\Controllers;

use App\Jobs\ExportCustomersJob;
use App\Jobs\MergeExportFilesJob;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $query = Customer::latest();

        if ($request->has('search')) {
            $searchTerm = $request->get('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('first_name', 'like', "%{$searchTerm}%")
                    ->orWhere('last_name', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%");
            });
        }

        $customers = $query->paginate($perPage);

        return view('customers.index', compact('customers'));
    }

    public function export()
    {
        $user = auth()->user();
        $timestamp = now()->timestamp;
        $tempDir = "exports/temp_{$timestamp}";
        $finalFileName = "customers_{$user->id}_{$timestamp}.xlsx";
        $finalFilePath = "exports/{$finalFileName}";

        try {
            $totalCustomers = Customer::count();
            $chunkSize = 1000;
            $jobs = [];

            Log::info("=== EXPORT STARTED ===", [
                'user_id' => $user->id,
                'export_id' => $timestamp,
                'total_customers' => $totalCustomers,
                'chunk_size' => $chunkSize,
                'temp_dir' => $tempDir,
                'final_path' => $finalFilePath
            ]);

            // Create jobs for each chunk
            for ($offset = 0; $offset < $totalCustomers; $offset += $chunkSize) {
                $chunkIndex = $offset / $chunkSize;
                $chunkFilePath = "{$tempDir}/chunk_{$chunkIndex}.csv";
                $jobs[] = new ExportCustomersJob($chunkFilePath, $offset, $chunkSize);

                Log::info("Chunk job created", [
                    'export_id' => $timestamp,
                    'chunk_index' => $chunkIndex,
                    'offset' => $offset,
                    'chunk_path' => $chunkFilePath
                ]);
            }

            Log::info("Total chunk jobs created", [
                'export_id' => $timestamp,
                'job_count' => count($jobs)
            ]);

            // FIXED: Use Bus::chain() to ensure merge job runs after batch completes
            $batch = Bus::batch($jobs)
                ->catch(function (Throwable $e) use ($timestamp) {
                    Log::error("Export batch failed", [
                        'export_id' => $timestamp,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    Cache::put("export_{$timestamp}", [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'failed_at' => now()->toDateTimeString(),
                    ], now()->addHours(24));
                })
                ->finally(function ($batch) use ($tempDir, $finalFilePath, $user, $timestamp) {
                    Log::info("Batch finally callback triggered", [
                        'export_id' => $timestamp,
                        'batch_id' => $batch->id,
                        'finished' => $batch->finished(),
                        'has_failures' => $batch->hasFailures(),
                        'failed_jobs' => $batch->failedJobs,
                        'total_jobs' => $batch->totalJobs,
                        'processed_jobs' => $batch->processedJobs()
                    ]);

                    // Only dispatch merge if batch completed successfully
                    if ($batch->finished() && !$batch->hasFailures()) {
                        Log::info("Dispatching merge job", [
                            'export_id' => $timestamp,
                            'temp_dir' => $tempDir,
                            'final_path' => $finalFilePath
                        ]);

                        // Dispatch merge job to the same queue
                        MergeExportFilesJob::dispatch($tempDir, $finalFilePath, $user->id, $timestamp)
                            ->onQueue('default');
                    } else {
                        Log::error("Batch had failures, not dispatching merge job", [
                            'export_id' => $timestamp,
                            'failed_jobs' => $batch->failedJobs
                        ]);

                        Cache::put("export_{$timestamp}", [
                            'status' => 'failed',
                            'error_message' => 'Some export chunks failed',
                            'failed_at' => now()->toDateTimeString(),
                        ], now()->addHours(24));
                    }
                })
                ->name("Customer Export - User {$user->id}")
                ->dispatch();

            // Store batch info in cache
            $cacheData = [
                'batch_id' => $batch->id,
                'user_id' => $user->id,
                'file_path' => $finalFilePath,
                'file_name' => $finalFileName,
                'status' => 'processing',
                'progress' => 0,
                'started_at' => now()->toDateTimeString(),
                'temp_dir' => $tempDir,
                'total_customers' => $totalCustomers,
            ];

            Cache::put("export_{$timestamp}", $cacheData, now()->addHours(24));

            Log::info("Export cache initialized", [
                'export_id' => $timestamp,
                'cache_data' => $cacheData
            ]);

            return redirect()->route('customers.index')
                ->with('success', 'Export started successfully.')
                ->with('export_id', $timestamp);

        } catch (\Throwable $e) {
            Log::error('Export initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Export failed: ' . $e->getMessage());
        }
    }

    /**
     * Check export status
     */
    public function exportStatus($exportId)
    {
        $exportData = Cache::get("export_{$exportId}");

        if (!$exportData) {
            Log::warning('Export status check - not found', ['export_id' => $exportId]);
            return response()->json(['status' => 'not_found'], 404);
        }

        Log::info('Export status checked', [
            'export_id' => $exportId,
            'current_status' => $exportData['status'],
            'progress' => $exportData['progress'] ?? 0
        ]);

        // If status is already completed or failed, return cached status
        if (in_array($exportData['status'], ['completed', 'failed'])) {
            return response()->json([
                'status' => $exportData['status'],
                'progress' => $exportData['progress'] ?? 100,
                'finished' => true,
                'failed' => $exportData['status'] === 'failed',
                'error_message' => $exportData['error_message'] ?? null,
            ]);
        }

        // Check batch progress
        $batch = Bus::findBatch($exportData['batch_id']);

        if (!$batch) {
            Log::error('Batch not found', [
                'export_id' => $exportId,
                'batch_id' => $exportData['batch_id']
            ]);
            return response()->json(['status' => 'not_found'], 404);
        }

        $progress = $batch->progress();
        $isFinished = $batch->finished();
        $hasFailed = $batch->hasFailures();

        Log::info('Batch status', [
            'export_id' => $exportId,
            'batch_id' => $batch->id,
            'progress' => $progress,
            'finished' => $isFinished,
            'failed' => $hasFailed,
            'total_jobs' => $batch->totalJobs,
            'processed' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs
        ]);

        // Update cache with current progress
        $exportData['progress'] = $progress;
        Cache::put("export_{$exportId}", $exportData, now()->addHours(24));

        return response()->json([
            'status' => $isFinished ? ($hasFailed ? 'failed' : 'processing') : 'processing',
            'progress' => $progress,
            'finished' => $isFinished,
            'failed' => $hasFailed,
        ]);
    }

    /**
     * Download the exported file
     */
    public function downloadExport(Request $request)
    {
        $exportId = $request->get('export_id');

        if (!$exportId) {
            Log::warning('Download attempted without export_id');
            return redirect()
                ->route('customers.index')
                ->with('error', 'Export ID is required.');
        }

        $exportData = Cache::get("export_{$exportId}");

        if (!$exportData) {
            Log::warning('Download attempted for non-existent export', ['export_id' => $exportId]);
            return redirect()
                ->route('customers.index')
                ->with('error', 'Export not found. It may have expired.');
        }

        Log::info('Download attempt', [
            'export_id' => $exportId,
            'status' => $exportData['status'],
            'user_id' => auth()->id(),
            'owner_id' => $exportData['user_id']
        ]);

        if ($exportData['status'] !== 'completed') {
            Log::warning('Download attempted for incomplete export', [
                'export_id' => $exportId,
                'status' => $exportData['status']
            ]);
            return redirect()
                ->route('customers.index')
                ->with('error', 'Export is not ready yet. Current status: ' . $exportData['status']);
        }

        // Verify user owns this export
        if ($exportData['user_id'] !== auth()->id()) {
            Log::warning('Unauthorized download attempt', [
                'export_id' => $exportId,
                'owner_id' => $exportData['user_id'],
                'requester_id' => auth()->id()
            ]);
            return redirect()
                ->route('customers.index')
                ->with('error', 'Unauthorized access to this export.');
        }

        $filePath = $exportData['file_path'];

        if (!Storage::disk('public')->exists($filePath)) {
            Log::error('Export file not found on disk', [
                'export_id' => $exportId,
                'file_path' => $filePath,
                'full_path' => Storage::disk('public')->path($filePath)
            ]);
            return redirect()
                ->route('customers.index')
                ->with('error', 'Export file not found on server.');
        }

        Log::info('Export downloaded', [
            'export_id' => $exportId,
            'user_id' => auth()->id(),
            'file_path' => $filePath
        ]);

        // Download the file
        return Storage::disk('public')->download(
            $filePath, 
            'customers_export_' . now()->format('Y-m-d_His') . '.xlsx'
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:customers,email|max:150',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'zip_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
            'status' => 'required|integer|in:0,1',
            'customer_type' => 'required|integer|in:1,2,3',
            'registration_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $customer = Customer::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $customer = Customer::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $customer
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:customers,email,' . $id,
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'zip_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
            'status' => 'required|integer|in:0,1',
            'customer_type' => 'required|integer|in:1,2,3',
            'registration_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $customer->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully'
        ]);
    }
}