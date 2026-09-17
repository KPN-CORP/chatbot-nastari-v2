@extends('layouts.admin')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
@endpush

@section('header-action')
<a href="{{ route('admin.callcenter.export') }}" class="btn btn-success btn-sm">
    <i class="fas fa-file-excel"></i> Export Excel
</a>
@endsection

@section('content')
<div class="card rounded-0 border-0 ">
    <div class="card-body">
        <div class="table-responsive">
            <table id="tableCallCenter" class="table table-striped small align-middle w-100">
                <thead>
                    <tr>
                        <th width="5%" class="text-center">No</th>
                        <th width="15%">Employee ID</th>
                        <th width="20%">Employee Name</th>
                        <th width="15%">Designation</th>
                        <th width="15%">Date</th>
                        <th width="20%">Issue</th>
                        <th width="10%" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data as $item)
                    <tr>
                        <td class="text-center">{{ $loop->iteration }}</td>
                        <td>{{ $item->employee_id }}</td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="fw-bold">{{ $item->name ?? $item->employee->name ?? '-' }}</span>
                                <small class="text-muted"><i class="fab fa-whatsapp text-success"></i> {{ $item->mobile }}</small>
                            </div>
                        </td>
                        <td>{{ $item->employee->designation_name ?? '-' }}</td>
                        <td>{{ \Carbon\Carbon::parse($item->ticket_date)->format('d M Y H:i') }}</td>
                        <td>
                            <div class="text-wrap" style="max-width: 300px;">
                                {{ \Illuminate\Support\Str::limit($item->complaint, 80) }}
                            </div>
                        </td>
                        <td class="text-center">
                            @if($item->image_path)
                                <button type="button" 
                                        class="btn btn-sm btn-info text-white" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#detailModal"
                                        onclick="showDetail(
                                            '{{ addslashes($item->name ?? $item->employee->name ?? '-') }}',
                                            '{{ $item->employee_id }}',
                                            '{{ $item->employee->designation_name ?? '-' }}',
                                            '{{ $item->business_unit }}',
                                            '{{ \Carbon\Carbon::parse($item->ticket_date)->format('d M Y H:i') }}',
                                            `{{ $item->complaint }}`,
                                            '{{ route('admin.callcenter.download', $item->id) }}'
                                        )">
                                    View
                                </button>
                            @else
                                <span class="text-muted text-xs">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-white border-bottom py-3">
                <h6 class="modal-title fw-bold text-dark"><i class="fas fa-ticket-alt me-2 text-primary"></i>Ticket Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <div class="row g-0">
                    <div class="col-md-7 pe-md-4 border-end">
                        <h6 class="text-uppercase text-muted fw-bold mb-4" style="font-size: 0.7rem; letter-spacing: 1px;">Information</h6>
                        
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Employee ID</label>
                                <span class="fs-6 fw-bold text-dark" id="modalNik">-</span>
                            </div>
                            <div class="col-6">
                                <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Employee Name</label>
                                <span class="fs-6 fw-bold text-dark" id="modalName">-</span>
                            </div>
                            
                            <div class="col-6">
                                <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Designation</label>
                                <span class="text-dark" id="modalDesignation">-</span>
                            </div>
                            <div class="col-6">
                                <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Business Unit</label>
                                <span class="text-dark" id="modalUnit">-</span>
                            </div>
                        </div>

                        <div class="row border-top pt-3">
                            <div class="col-md-12">
                                <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Date</label>
                                <span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1" id="modalDate">-</span>
                            </div>
                            <div class="col-md-12 mt-3">
                                <label class="small text-muted fw-bold d-block text-uppercase mb-2" style="font-size: 0.65rem;">Issue </label>
                                <div class="p-3 bg-light rounded-2 border border-light-subtle">
                                    <p class="mb-0 text-dark" id="modalComplaint" style="white-space: pre-wrap; font-size: 0.9rem; line-height: 1.5;"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5 ps-md-4">
                        <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.7rem; letter-spacing: 1px;">Photo Evidence</h6>
                        <div class="bg-light rounded-3 p-2 d-flex justify-content-center align-items-center border border-dashed" style="height: 100%; min-height: 350px;">
                            <img id="modalImage" src="" class="img-fluid rounded shadow-sm" style="max-height: 400px; max-width: 100%; object-fit: contain;" alt="No Attachment">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer bg-light py-2 border-top-0">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#tableCallCenter').DataTable({
            "language": {
                "search": "Search:",
                "lengthMenu": "Show _MENU_ entries",
                "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                "paginate": {
                    "previous": "Previous",
                    "next": "Next"
                }
            },
            "pageLength": 10,
            "ordering": true
        });
    });

    function showDetail(name, nik, designation, unit, date, complaint, imageUrl) {
        document.getElementById('modalName').textContent = name;
        document.getElementById('modalNik').textContent = nik;
        document.getElementById('modalDesignation').textContent = designation;
        document.getElementById('modalUnit').textContent = unit;
        document.getElementById('modalDate').textContent = date;
        document.getElementById('modalComplaint').textContent = complaint;
        document.getElementById('modalImage').src = imageUrl; // Ini akan mengisi URL gambar yang benar
    }
</script>
@endpush
@endsection