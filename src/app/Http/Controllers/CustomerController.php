<?php

namespace App\Http\Controllers;

use App\Jobs\ExportCustomersJob;
use App\Jobs\MarkExportCompletedJob;
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

            // Create jobs for each chunk with separate file paths
            for ($offset = 0; $offset < $totalCustomers; $offset += $chunkSize) {
                $chunkIndex = $offset / $chunkSize;
                $chunkFilePath = "{$tempDir}/chunk_{$chunkIndex}.xlsx";
                $jobs[] = new ExportCustomersJob($chunkFilePath, $offset, $chunkSize);
            }

            // Dispatch batch
            $batch = Bus::batch($jobs)
                ->then(function () use ($tempDir, $finalFilePath, $user, $timestamp) {
                    // Dispatch job to merge all chunks
                    dispatch(new MergeExportFilesJob($tempDir, $finalFilePath, $user->id, $timestamp))->delay(now()->addSeconds(5));
                })
                ->catch(function (Throwable $e) use ($timestamp) {
                    Log::error("Export failed", [
                        'timestamp' => $timestamp,
                        'error' => $e->getMessage()
                    ]);
                    
                    Cache::put("export_{$timestamp}", [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ], now()->addHours(24));
                })
                ->name("Customer Export - User {$user->id}")
                ->dispatch();

            // Store batch info in cache using timestamp as key
            Cache::put("export_{$timestamp}", [
                'batch_id' => $batch->id,
                'user_id' => $user->id,
                'file_path' => $finalFilePath,
                'file_name' => $finalFileName,
                'status' => 'processing',
                'progress' => 0,
                'started_at' => now()->toDateTimeString(),
            ], now()->addHours(24));

            return redirect()->route('customers.index')
                ->with('success', 'Export started successfully.')
                ->with('export_id', $timestamp); // Use timestamp as export ID
        } catch (\Throwable $e) {
            Log::error('Export failed', ['error' => $e->getMessage()]);
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
            return response()->json(['status' => 'not_found'], 404);
        }

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
            return response()->json(['status' => 'not_found'], 404);
        }

        $progress = $batch->progress();
        $isFinished = $batch->finished();
        $hasFailed = $batch->hasFailures();

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

        // Don't clean up cache immediately - let it expire naturally
        // This allows users to download multiple times

        // Download the file
        return Storage::disk('public')->download(
            $filePath, 
            'customers_export_' . now()->format('Y-m-d_His') . '.xlsx'
        );
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('customers.create');
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
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $customer = Customer::findOrFail($id);
        return view('customers.edit', compact('customer'));
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