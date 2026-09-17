@extends('layouts.admin')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
@endpush

@section('content')
<div class="card rounded-0 border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table id="tableUsers" class="table table-striped small w-100">
                <thead class="align-middle">
                    <tr>
                        <th class="text-center">No</th>
                        <th>Employee ID</th>
                        <th>Employee Name</th>
                        <th>Phone</th>
                        <th>Business Unit</th>
                        <th>Designation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                    <tr>
                        <td class="text-center">{{ $loop->iteration }}</td>
                        <td>{{ $user['employee_id'] }}</td>
                        <td class="fw-bold">{{ $user['name'] }}</td>
                        <td>{{ $user['phone'] }}</td>
                        <td>{{ $user['bu'] }}</td>
                        <td>{{ $user['position'] }}</td>
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
        $('#tableUsers').DataTable({});
    });
</script>
@endpush
@endsection