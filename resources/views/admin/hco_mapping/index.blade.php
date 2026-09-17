@extends('layouts.admin')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .select2-container--bootstrap-5 .select2-selection { font-size: 0.875rem; }
</style>
@endpush

@section('content')
<div class="container-fluid p-0">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <form action="{{ route('admin.hco_mapping.store') }}" method="POST" class="row g-3">
                @csrf
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Business Unit</label>
                    <select name="group_company" class="form-select select2" required>
                        <option value="">Choose Business Unit</option>
                        @foreach($businessUnits as $bu)
                            <option value="{{ $bu->group_company }}">
                                {{ $bu->group_company }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Assigned HCO PIC</label>
                    <select name="hco_employee_id" class="form-select select2" required>
                        <option value="">Select PIC</option>
                        @foreach($admins as $emp)
                            <option value="{{ $emp->employee_id }}">{{ $emp->fullname }} ({{ $emp->employee_id }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 shadow-sm">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table id="hcoMappingTable" class="table small table-striped align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>Business Unit</th>
                            <th>PIC Name</th>
                            <th>Employee ID</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mappings as $m)
                        <tr>
                            <td class="fw-bold">{{ $m->group_company }}</td>
                            <td class="fw-semibold text-primary">{{ $m->hco_name }}</td>
                            <td class="text-muted">{{ $m->hco_employee_id }}</td>
                            <td class="text-center">
                                <form action="{{ route('admin.hco_mapping.destroy', $m->id) }}" method="POST" onsubmit="return confirm('Delete this mapping?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0">
                                        <i class="bi bi-trash"></i>
                                    </button>
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
@endsection

@push('scripts')
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });

        $('#hcoMappingTable').DataTable({});
    });
</script>
@endpush