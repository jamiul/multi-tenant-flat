<x-layouts.app>

    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Customers') }}</h1>
                <p class="text-gray-600 dark:text-gray-400 mt-1">{{ __('Manage your customers') }}</p>
            </div>
            <div>
                <a href="#" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg transition duration-150 ease-in-out">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    {{ __('Add Customer') }}
                </a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg bg-green-100 px-6 py-5 text-base text-green-700 dark:bg-green-900 dark:text-green-200" role="alert">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-100 px-6 py-5 text-base text-red-700 dark:bg-red-900 dark:text-red-200" role="alert">
            {{ session('error') }}
        </div>
    @endif

    @if (session('export_id'))
    <div id="export-status" class="mb-4 rounded-lg bg-blue-100 px-6 py-5 text-base text-blue-700 dark:bg-blue-900 dark:text-blue-200" role="alert">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <svg class="animate-spin h-5 w-5 text-blue-700 dark:text-blue-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <div>
                    <span class="font-semibold">Exporting customers...</span>
                    <div class="mt-1">
                        <div class="w-64 bg-blue-200 dark:bg-blue-800 rounded-full h-2.5">
                            <div id="progress-bar" class="bg-blue-600 dark:bg-blue-400 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                        <span id="export-progress" class="text-sm mt-1 inline-block">0%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const exportId = '{{ session('export_id') }}';
            const statusDiv = document.getElementById('export-status');
            const progressSpan = document.getElementById('export-progress');
            const progressBar = document.getElementById('progress-bar');
            
            let pollCount = 0;
            const maxPolls = 300; // Stop after 10 minutes (300 * 2s = 600s)

            const interval = setInterval(function () {
                pollCount++;
                
                // Safety check to prevent infinite polling
                if (pollCount > maxPolls) {
                    clearInterval(interval);
                    updateStatusUI('error', 'Export is taking longer than expected. Please refresh the page to check status.');
                    return;
                }

                fetch(`/customers/export/status/${exportId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Failed to check export status');
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Export status:', data); // Debug log
                        
                        // Update progress
                        if (data.progress !== undefined) {
                            const progressValue = Math.round(data.progress);
                            progressSpan.textContent = progressValue + '%';
                            progressBar.style.width = progressValue + '%';
                        }

                        // Check if export is finished (completed or failed)
                        if (data.status === 'completed') {
                            clearInterval(interval);
                            updateStatusUI('success', 'Export complete!', exportId);
                        } else if (data.status === 'failed' || data.failed === true) {
                            clearInterval(interval);
                            const errorMsg = data.error_message || 'Unknown error occurred. Please try again.';
                            updateStatusUI('error', `Export failed: ${errorMsg}`);
                        }
                    })
                    .catch(error => {
                        console.error('Error checking export status:', error);
                        clearInterval(interval);
                        updateStatusUI('error', 'Failed to check export status. Please refresh the page.');
                    });
            }, 2000); // Poll every 2 seconds

            function updateStatusUI(type, message, exportId = null) {
                // Remove all color classes
                statusDiv.classList.remove(
                    'bg-blue-100', 'text-blue-700', 'dark:bg-blue-900', 'dark:text-blue-200',
                    'bg-green-100', 'text-green-700', 'dark:bg-green-900', 'dark:text-green-200',
                    'bg-red-100', 'text-red-700', 'dark:bg-red-900', 'dark:text-red-200'
                );

                if (type === 'success') {
                    statusDiv.classList.add('bg-green-100', 'text-green-700', 'dark:bg-green-900', 'dark:text-green-200');
                    statusDiv.innerHTML = `
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span class="font-semibold">${message}</span>
                            </div>
                            <a href="{{ route('customers.export.download') }}?export_id=${exportId}" 
                               class="ml-4 bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded inline-flex items-center transition duration-150">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Download Excel
                            </a>
                        </div>
                    `;
                } else if (type === 'error') {
                    statusDiv.classList.add('bg-red-100', 'text-red-700', 'dark:bg-red-900', 'dark:text-red-200');
                    statusDiv.innerHTML = `
                        <div class="flex items-center space-x-3">
                            <svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="font-semibold">${message}</span>
                        </div>
                    `;
                }
            }
        });
    </script>
@endif

    <!-- Search and Export -->
    <div class="mb-4 flex items-center justify-between">
        <form action="{{ route('customers.index') }}" method="GET" class="w-full max-w-md">
            <div class="flex items-center border-b-2 border-blue-500 py-2">
                <input class="appearance-none bg-transparent border-none w-full text-gray-700 dark:text-gray-300 mr-3 py-1 px-2 leading-tight focus:outline-none" type="text" name="search" placeholder="Search customers..." value="{{ request('search') }}">
                <button class="flex-shrink-0 bg-blue-500 hover:bg-blue-700 border-blue-500 hover:border-blue-700 text-sm border-4 text-white py-1 px-2 rounded" type="submit">
                    Search
                </button>
            </div>
        </form>
        <a href="{{ route('customers.export') }}" id="export-button" class="bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2 rounded-lg transition duration-150 ease-in-out">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span id="export-button-text">{{ __('Export to Excel') }}</span>
        </a>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const exportButton = document.getElementById('export-button');
                const exportButtonText = document.getElementById('export-button-text');

                exportButton.addEventListener('click', function (e) {
                    exportButton.classList.add('opacity-50', 'cursor-not-allowed');
                    exportButton.style.pointerEvents = 'none';
                    exportButtonText.textContent = 'Processing...';
                });
            });
        </script>
    </div>

    <!-- Customers Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('All Customers') }}</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Name') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Email') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Phone') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Type') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Registration Date') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($customers ?? [] as $customer)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10">
                                    <div class="h-10 w-10 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ substr($customer->first_name, 0, 1) }}{{ substr($customer->last_name, 0, 1) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $customer->full_name }}
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $customer->city }}, {{ $customer->country }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            {{ $customer->email }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            {{ $customer->phone }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                @if($customer->customer_type == 1) bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                                @elseif($customer->customer_type == 2) bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                @elseif($customer->customer_type == 3) bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200
                                @else bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200 @endif">
                                {{ $customer->customer_type_name }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                @if($customer->status == 1) bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                @else bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200 @endif">
                                {{ $customer->status == 1 ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            {{ $customer->registration_date?->format('M d, Y') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="#" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </a>
                                <a href="#" class="text-yellow-600 hover:text-yellow-900 dark:text-yellow-400 dark:hover:text-yellow-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </a>
                                <form method="POST" action="#" class="inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this customer?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-1">{{ __('No customers found') }}</h3>
                                <p class="text-gray-500 dark:text-gray-400 mb-4">{{ __('Get started by adding your first customer.') }}</p>
                                <a href="#" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg transition duration-150 ease-in-out">
                                    {{ __('Add Customer') }}
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($customers) && $customers->hasPages())
        <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700">
            {{ $customers->links() }}
        </div>
        @endif
    </div>

</x-layouts.app>