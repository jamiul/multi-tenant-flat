<?php

namespace App\Http\Controllers;

use Throwable;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Exports\CustomersExport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Jobs\ExportCustomersToExcel;
use Maatwebsite\Excel\Facades\Excel;
use App\Jobs\NotifyUserOfCompletedExport;
use Illuminate\Support\Facades\Validator;

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
        $fileName = "exports/customers_{$user->id}_" . now()->timestamp . '.xlsx';

        // Chain the export and notification jobs within a batch
        $batch = Bus::batch([
            // Dispatch our new dedicated export job here
            new ExportCustomersToExcel($fileName),
        ])->then(function ($batch) use ($user, $fileName) {
            // All jobs completed successfully...
             Bus::dispatch(new NotifyUserOfCompletedExport($user, $fileName));
        })->catch(function ($batch, $exception) {
            // A job failed within the batch...
            Log::error("Customer export batch failed: " . $exception->getMessage(), ['batch_id' => $batch->id]);
            // You might want to notify the user of a failure here
            // e.g., $user->notify(new ExportFailed($fileName));
        })->finally(function ($batch) {
            // The batch has finished executing.
            // Any final cleanup or logging can go here.
        })->dispatch();

        session(['export_batch_id' => $batch->id]);
        session(['export_file_name' => $fileName]);

        return back()->with('success', 'Export has been started and you will be notified upon completion.');
    }

    public function exportStatus($batchId)
    {
        $batch = Bus::findBatch($batchId);

        if (!$batch) {
            return response()->json([
                'finished' => true, // Treat as finished if batch not found
                'failed'   => true, // And indicate failure
                'progress' => 100,
                'message'  => 'Export batch not found.',
            ], 404);
        }

        return response()->json([
            'finished' => $batch->finished(),
            'failed'   => $batch->hasFailures(),
            'progress' => $batch->progress(),
        ]);
    }

    public function downloadExport()
    {
        $fileName = session('export_file_name');

        if (!$fileName || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($fileName)) {
            // Check if the batch failed or if the file simply doesn't exist yet (still processing)
            $batchId = session('export_batch_id');
            if ($batchId) {
                $batch = Bus::findBatch($batchId);
                if ($batch && !$batch->finished()) {
                    return redirect()
                        ->route('customers.index')
                        ->with('error', 'Export is still in progress. Please wait for completion notification.');
                }
            }
            return redirect()
                ->route('customers.index')
                ->with('error', 'File not found or export failed.');
        }

        session()->forget(['export_file_name', 'export_batch_id']); // Clear after download

        return \Illuminate\Support\Facades\Storage::disk('public')->download($fileName);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Return view for creating customer
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

        // Return view for editing customer
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
