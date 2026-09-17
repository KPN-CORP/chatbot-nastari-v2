@extends('layouts.admin')

@section('content')
<div class="container-fluid p-0">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="bi bi-cloud-upload-fill me-2"></i> Upload Document
            </button>
            <button id="btn-refresh" class="btn btn-secondary btn-sm" onclick="manualRefresh()">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh Status
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table small table-hover align-middle" id="kbTable">
                    <thead class="bg-light align-middle">
                        <tr>
                            <th>Document Name</th>
                            <th>Business Unit</th>
                            <th>File Size</th>
                            <th>Upload by</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr id="row-{{ $file->id }}">
                                <td class="fw-bold">{{ $file->filename }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $file->business_unit }}</span></td>
                                <td class="text-muted">{{ $file->file_size }} KB</td>
                                <td>{{ $file->upload_by ?? 'System' }}</td>
                                <td class="text-muted small">{{ $file->created_at->format('d M Y, H:i') }}</td>
                                <td class="text-center status-cell" data-id="{{ $file->id }}">
                                    @php $st = strtolower($file->status); @endphp
                                    @if($st === 'ready' || $st === 'done')
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">
                                            <i class="bi bi-check-circle-fill me-1"></i> Ready
                                        </span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill">
                                            <span class="spinner-border spinner-border-sm me-1" style="width: 0.6rem; height: 0.6rem;"></span> Syncing
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end pe-3">
                                    <form action="{{ route('hear.kb.delete') }}" method="POST" onsubmit="return confirm('Delete this document?');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="filename" value="{{ $file->path }}">
                                        <button type="submit" class="btn btn-sm btn-light text-danger border shadow-sm rounded-circle" style="width: 32px; height: 32px;"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Upload Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form action="{{ route('hear.kb.upload') }}" method="post" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Target Business Unit</label>
                        @if($isSuperAdmin)
                            <select class="form-select" name="scope" required>
                                <option value="" selected disabled>Select Business Unit</option>
                                <option value="KPN Corporation">KPN Corporation</option>
                                <option value="Cement">Cement</option>
                                <option value="Downstream">Downstream</option>
                                <option value="Plantations">Plantations</option>
                                <option value="Property">Property</option>
                            </select>
                        @else
                            <input type="text" class="form-control bg-light" value="{{ $userBu }}" readonly>
                            <input type="hidden" name="scope" value="{{ $userBu }}">
                        @endif
                    </div>
                    <div class="mb-4 text-center p-4 border border-2 border-dashed rounded bg-light position-relative" id="drop-zone">
                        <input type="file" class="form-control position-absolute top-0 start-0 w-100 h-100 opacity-0" name="document" accept=".pdf,.txt,.docx" required style="cursor: pointer;" id="fileInput">
                        <i class="bi bi-cloud-upload fs-1 text-primary" id="uploadIcon"></i>
                        <p class="mb-0 fw-bold mt-2 text-truncate px-3" id="fileNameDisplay">Click or Drag file here</p>
                        <small class="text-muted">PDF, TXT, DOCX (Max 5MB)</small>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Upload Now</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#kbTable').DataTable({
            order: [[ 4, "desc" ]],
            columnDefs: [{ orderable: false, targets: 6 }]
        });
        $('#fileInput').on('change', function() {
            let fileName = $(this).val().split('\\').pop();
            if (fileName) {
                $('#fileNameDisplay').text(fileName).addClass('text-primary');
                $('#uploadIcon').removeClass('bi-cloud-upload').addClass('bi-file-earmark-check-fill');
                $('#drop-zone').addClass('border-primary bg-white').removeClass('bg-light');
            }
        });
        setInterval(checkStatusNow, 10000);
    });

    function manualRefresh() {
        let btn = $('#btn-refresh');
        let originalContent = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Checking...');
        checkStatusNow(function() {
            setTimeout(() => { btn.prop('disabled', false).html(originalContent); }, 500);
        });
    }

    function checkStatusNow(callback = null) {
        $.ajax({
            url: "{{ route('hear.kb.statuses', [], false) }}", 
            method: "GET",
            success: function(data) {
                data.forEach(function(item) {
                    let statusCell = $(`.status-cell[data-id="${item.id}"]`);
                    if (statusCell.length > 0) {
                        let s = item.status.toLowerCase();
                        if (s === 'ready' || s === 'done') {
                            statusCell.html('<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill"><i class="bi bi-check-circle-fill me-1"></i> Ready</span>');
                        }
                    }
                });
                if(callback) callback();
            }
        });
    }
</script>
@endpush