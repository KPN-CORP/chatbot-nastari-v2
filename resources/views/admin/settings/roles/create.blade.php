@extends('layouts.admin')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .select2-container--bootstrap-5 .select2-selection {
        min-height: 38px;
    }
    .card-header-tabs .nav-link {
        color: #6c757d;
        font-weight: 500;
        border: none;
        border-bottom: 3px solid transparent;
        padding: 10px 20px;
    }
    .card-header-tabs .nav-link.active {
        color: #0d6efd;
        background: none;
        border-bottom: 3px solid #0d6efd;
        font-weight: 600;
    }
    .card-header-tabs .nav-link:hover {
        color: #0d6efd;
        border-color: transparent;
    }
</style>
@endpush

@section('content')
<div class="card rounded-0 border-0 shadow-sm">
    <div class="card-header bg-white border-bottom px-3 pt-3 pb-0">
        <ul class="nav nav-tabs card-header-tabs" id="roleTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="create-tab" data-bs-toggle="tab" data-bs-target="#create" type="button" role="tab">
                    Create New Role
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="manage-tab" data-bs-toggle="tab" data-bs-target="#manage" type="button" role="tab">
                    Manage Roles
                </button>
            </li>
        </ul>
    </div>

    <div class="card-body p-3">
        <div class="tab-content" id="roleTabsContent">
            
            <div class="tab-pane fade show active" id="create" role="tabpanel">
                <form action="{{ route('admin.roles.store') }}" method="POST">
                    @csrf
                    <div class="row justify-content-center">
                        <div class="col-lg-12">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Role Name</label>
                                <input type="text" name="name" class="form-control" required placeholder="Ex: Admin Cement">
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted mb-2">Access Scope</label>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <select name="scope_bu[]" id="create_scope_bu" class="form-select scope-filter-create" multiple="multiple">
                                            @foreach($businessUnits as $bu)
                                                <option value="{{ $bu }}">{{ $bu }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="scope_company[]" id="create_scope_company" class="form-select scope-filter-create" multiple="multiple">
                                            @foreach($companies as $comp)
                                                <option value="{{ $comp }}">{{ $comp }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="scope_location[]" id="create_scope_location" class="form-select scope-filter-create" multiple="multiple">
                                            @foreach($locations as $loc)
                                                <option value="{{ $loc->work_area_code }}">{{ $loc->office_area }} ({{ $loc->work_area_code }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text small"><i class="bi bi-info-circle"></i> Leave empty for Global access.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Assign Users</label>
                                <select name="users[]" class="form-select" id="createUserSelect" multiple="multiple">
                                    @foreach($users as $user)
                                        <option value="{{ $user->employee_id }}">
                                            {{ $user->employee_id }} - {{ $user->fullname }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                                <button type="reset" class="btn btn-sm btn-light border px-3">Reset</button>
                                <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold">Save Role</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="tab-pane fade" id="manage" role="tabpanel">
                <div class="row justify-content-center">
                    <div class="col-lg-12">
                        <div class="mb-3">
                            <select id="roleSelector" class="form-select">
                                <option value="">Select Role to Manage...</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div id="editFormContainer" style="display: none;" class="border rounded p-3 bg-light">
                            <form id="editRoleForm" method="POST">
                                @csrf
                                @method('PUT')
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Role Name</label>
                                    <input type="text" name="name" id="edit_name" class="form-control" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Access Scope</label>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <select name="scope_bu[]" id="edit_scope_bu" class="form-select scope-filter-edit" multiple="multiple">
                                                @foreach($businessUnits as $bu)
                                                    <option value="{{ $bu }}">{{ $bu }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <select name="scope_company[]" id="edit_scope_company" class="form-select scope-filter-edit" multiple="multiple">
                                                @foreach($companies as $comp)
                                                    <option value="{{ $comp }}">{{ $comp }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <select name="scope_location[]" id="edit_scope_location" class="form-select scope-filter-edit" multiple="multiple">
                                                @foreach($locations as $loc)
                                                    <option value="{{ $loc->work_area_code }}">{{ $loc->office_area }} ({{ $loc->work_area_code }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Assign Users</label>
                                    <select name="users[]" class="form-select" id="editUserSelect" multiple="multiple">
                                        @foreach($users as $user)
                                            <option value="{{ $user->employee_id }}">
                                                {{ $user->employee_id }} - {{ $user->fullname }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="d-flex justify-content-between mt-3 pt-3 border-top">
                                    <button type="button" id="btnDeleteRole" class="btn btn-sm btn-outline-danger px-3">Delete</button>
                                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-bold">Update Role</button>
                                </div>
                            </form>
                            
                            <form id="deleteRoleForm" method="POST" style="display: none;">
                                @csrf
                                @method('DELETE')
                            </form>
                        </div>
                        
                        <div id="emptyState" class="text-center py-4 text-muted small">
                            Please select a role above to edit.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    
    var rolesData = @json($roles);

    $('#roleSelector').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: "Search & Select Role..."
    });

    $('#createUserSelect, #editUserSelect').select2({
        theme: 'bootstrap-5',
        placeholder: "Search user by Name or NIK...",
        allowClear: true,
        width: '100%',
        closeOnSelect: false
    });

    $('.scope-filter-create, .scope-filter-edit').select2({
        theme: 'bootstrap-5',
        width: '100%',
        allowClear: true,
        placeholder: "-- Select Multiple (Optional) --",
        closeOnSelect: false
    });

    function parseScopeData(data) {
        if (!data) return [];
        if (Array.isArray(data)) return data;
        try {
            return JSON.parse(data);
        } catch (e) {
            return [data];
        }
    }

    $('#roleSelector').change(function() {
        var roleId = $(this).val();
        
        if (roleId) {
            $('#emptyState').hide();
            $('#editFormContainer').fadeIn();

            var role = rolesData.find(r => r.id == roleId);
            
            if (role) {
                var updateUrl = "{{ route('admin.roles.update', ':id') }}".replace(':id', roleId);
                var deleteUrl = "{{ route('admin.roles.destroy', ':id') }}".replace(':id', roleId);
                
                $('#editRoleForm').attr('action', updateUrl);
                $('#deleteRoleForm').attr('action', deleteUrl);

                $('#edit_name').val(role.name);
                
                var scopeBu = parseScopeData(role.scope_bu);
                var scopeComp = parseScopeData(role.scope_company);
                var scopeLoc = parseScopeData(role.scope_location);

                $('#edit_scope_bu').val(scopeBu).trigger('change');
                $('#edit_scope_company').val(scopeComp).trigger('change');
                $('#edit_scope_location').val(scopeLoc).trigger('change');

                var assignedUsers = (role.assigned_users || []).map(String);
                
                $('#editUserSelect').val(null).trigger('change');
                $('#editUserSelect').val(assignedUsers).trigger('change');
            }
        } else {
            $('#editFormContainer').hide();
            $('#emptyState').show();
        }
    });

    $('#btnDeleteRole').click(function() {
        if(confirm('Are you sure you want to delete this role? This action cannot be undone.')) {
            $('#deleteRoleForm').submit();
        }
    });

    $('.scope-filter-create').change(function() {
        filterUsers('#createUserSelect', '#create_scope_bu', '#create_scope_company', '#create_scope_location');
    });

    function filterUsers(targetSelect, buId, compId, locId) {
        let bu = $(buId).val(); 
        let company = $(compId).val();
        let location = $(locId).val();
        let target = $(targetSelect);
        
        $.ajax({
            url: "{{ route('admin.roles.get_users') }}",
            type: "GET",
            data: { bu: bu, company: company, location: location },
            success: function(response) {
                let currentSelection = target.val();
                
                target.empty();
                
                if (response.length > 0) {
                    $.each(response, function(key, user) {
                        let newOption = new Option(user.text, user.id, false, false);
                        target.append(newOption);
                    });
                }
                
                if(currentSelection) {
                    target.val(currentSelection);
                }
                
                target.trigger('change'); 
            },
            error: function() {
                console.error("Failed loading users");
            }
        });
    }
});
</script>
@endpush
@endsection