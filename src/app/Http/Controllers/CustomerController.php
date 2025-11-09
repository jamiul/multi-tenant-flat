<?php

namespace App\Http\Controllers;

use App\Jobs\ExportCustomersJob;
use App\Jobs\MarkExportCompletedJob;
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

    public function export()
    {
        $user = auth()->user();
        $exportId = uniqid('export_', true);
        $fileName = "customers_{$user->id}_" . now()->timestamp . '.xlsx';
        $filePath = "exports/{$fileName}";

        try {
            // Initialize cache entry
            Cache::put("export_{$exportId}", [
                'user_id' => $user->id,
                'file_path' => $filePath,
                'status' => 'processing',
                'progress' => 0,
                'started_at' => now()->toDateTimeString(),
            ], now()->addHours(24));

            // Dispatch batch export job
            $totalCustomers = Customer::count();
            $chunkSize = 1000;
            $jobs = [];

            for ($offset = 0; $offset < $totalCustomers; $offset += $chunkSize) {
                $jobs[] = new ExportCustomersJob($filePath, $offset, $chunkSize);
            }

            $batch = Bus::batch($jobs)->then(function () use ($exportId, $filePath, $user) {
                // This will be handled in MarkExportCompletedJob
                new MarkExportCompletedJob($exportId, $filePath, $user->id);
            })->catch(function (Throwable $e) use ($exportId) {
                Log::error("Export failed", [
                    'export_id' => $exportId,
                    'error' => $e->getMessage()
                ]);
                Cache::put("export_{$exportId}", [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ], now()->addHours(24));
            })->dispatch();
            // \Maatwebsite\Excel\Facades\Excel::queue(
            //     new \App\Exports\CustomersExport,
            //     $filePath,
            //     'public'
            // )->chain([
            //     new \App\Jobs\MarkExportCompletedJob($exportId, $filePath, $user->id)
            // ]);

            return redirect()->route('customers.index')
                ->with('success', 'Export started successfully.')
                ->with('export_id', $exportId);
        } catch (\Throwable $e) {
            \Log::error('Export failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Export failed: ' . $e->getMessage());
        }
    }


    /**
     * Check export status
     */
    public function exportStatus($exportId)
    {
        $batch = Bus::findBatch($exportId);

        if (!$batch) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json([
            'status' => $batch->finished() ? 'completed' : 'processing',
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
            'failed' => $batch->hasFailures(),
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
