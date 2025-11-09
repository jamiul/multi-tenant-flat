<?php

namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
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

    /**
     * Start the export process
     */
    public function export()
    {
        $user = auth()->user();
        $exportId = uniqid('export_', true);
        $fileName = "exports/customers_{$user->id}_" . now()->timestamp . '.xlsx';

        // Test Redis connection before proceeding
        try {
            Cache::put('test_redis_connection', 'test_value', 10);
            $testValue = Cache::get('test_redis_connection');
            
            if ($testValue !== 'test_value') {
                Log::error('Redis test failed - value mismatch');
                return back()->with('error', 'Cache system error. Please contact administrator.');
            }
            
            Cache::forget('test_redis_connection');
        } catch (\Exception $e) {
            Log::error('Redis connection test failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Cache system unavailable. Please try again later.');
        }

        // Store initial export metadata in cache for 24 hours
        $initialData = [
            'user_id' => $user->id,
            'file_name' => $fileName,
            'status' => 'processing',
            'progress' => 0,
            'started_at' => now()->toDateTimeString(),
        ];
        
        $saved = Cache::put("export_{$exportId}", $initialData, now()->addHours(24));
        
        if (!$saved) {
            Log::error('Failed to save initial export data to cache', [
                'export_id' => $exportId,
                'user_id' => $user->id
            ]);
            return back()->with('error', 'Failed to initialize export. Please try again.');
        }

        // Store latest export ID for this user (helps with recovery)
        Cache::put("user_{$user->id}_latest_export", $exportId, now()->addHours(24));

        Log::info("Export initiated", [
            'export_id' => $exportId,
            'user_id' => $user->id,
            'file_name' => $fileName,
            'cache_saved' => $saved
        ]);

        // Dispatch the export job
        \App\Jobs\ExportCustomersJob::dispatch($user, $fileName, $exportId);

        // Redirect to customers page with export_id in URL (most reliable method)
        return redirect()->route('customers.index', ['export_id' => $exportId])
            ->with('success', 'Export has been started. Please wait while we process your request.');
    }

    /**
     * Check export status
     */
    public function exportStatus($exportId)
    {
        $exportData = Cache::get("export_{$exportId}");

        Log::info("Export status checked", [
            'export_id' => $exportId,
            'data_exists' => !is_null($exportData),
            'status' => $exportData['status'] ?? 'unknown'
        ]);

        if (!$exportData) {
            return response()->json([
                'error' => 'Export not found',
                'status' => 'not_found',
                'message' => 'Export session not found. It may have expired.'
            ], 404);
        }

        $isCompleted = isset($exportData['status']) && $exportData['status'] === 'completed';
        $isFailed = isset($exportData['status']) && $exportData['status'] === 'failed';

        return response()->json([
            'status' => $exportData['status'] ?? 'unknown',
            'progress' => $exportData['progress'] ?? 0,
            'finished' => $isCompleted,
            'failed' => $isFailed,
            'file_name' => $exportData['file_name'] ?? null,
            'error_message' => $exportData['error_message'] ?? null,
            'export_id' => $exportId,
            'started_at' => $exportData['started_at'] ?? null,
            'completed_at' => $exportData['completed_at'] ?? null,
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

        $fileName = $exportData['file_name'];

        if (!Storage::disk('public')->exists($fileName)) {
            Log::error('Export file not found on disk', [
                'export_id' => $exportId,
                'file_name' => $fileName,
                'full_path' => Storage::disk('public')->path($fileName)
            ]);
            return redirect()
                ->route('customers.index')
                ->with('error', 'Export file not found on server.');
        }

        Log::info('Export downloaded', [
            'export_id' => $exportId,
            'user_id' => auth()->id(),
            'file_name' => $fileName
        ]);

        // Clean up cache after successful download
        Cache::forget("export_{$exportId}");
        
        // Optional: Clean up the user's latest export reference
        if (Cache::get("user_" . auth()->id() . "_latest_export") === $exportId) {
            Cache::forget("user_" . auth()->id() . "_latest_export");
        }

        // Download the file
        return Storage::disk('public')->download($fileName, 'customers_export_' . now()->format('Y-m-d_His') . '.xlsx');
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