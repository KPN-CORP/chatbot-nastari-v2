@extends('layouts.admin')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
@endpush

@section('content')
<div class="card rounded-0 border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table id="tableLetters" class="table table-striped small w-100">
                <thead class="align-middle">
                    <tr>
                        <th class="text-center" width="5%">No</th>
                        <th width="15%">Request Date</th>
                        <th width="20%">Reference Number</th>
                        <th width="25%">Employee Information</th>
                        <th width="15%">Document Type</th>
                        <th width="10%">Purpose</th>
                        <th class="text-center" width="10%">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                    <tr>
                        <td class="text-center">{{ $loop->iteration }}</td>
                        <td>{{ $log->created_at->format('d M Y, H:i') }}</td>
                        <td class="text-primary fw-bold">{{ $log->nomor_surat }}</td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="fw-bold text-dark">{{ $log->nama_karyawan }}</span>
                                <small class="text-muted">{{ $log->nik }}</small>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                {{ $log->jenis_surat }}
                            </span>
                        </td>
                        <td>{{ $log->kebutuhan }}</td>
                        <td class="text-center">
                            <a href="{{ route('admin.letter_logs.view', $log->id) }}" target="_blank" class="btn btn-sm btn-info text-white">
                                <i class="bi bi-eye-fill"></i> View
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#tableLetters').DataTable({});
    });
</script>
@endpush
@endsection