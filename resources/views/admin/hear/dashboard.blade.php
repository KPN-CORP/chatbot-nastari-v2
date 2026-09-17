@extends('layouts.admin')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .card { border-radius: 10px; }
    .bg-danger-subtle { background-color: #fee2e2 !important; }
    .bg-success-subtle { background-color: #dcfce7 !important; }
    .bg-primary-subtle { background-color: #e0f2fe !important; }
</style>
@endpush

@section('content')
<div class="container-fluid p-0">
    <div id="alert-placeholder" class="position-fixed top-0 end-0 p-3" style="z-index: 1100"></div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="rounded-3 bg-danger-subtle text-danger d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px;">
                        <i class="bi bi-exclamation-triangle-fill fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-semibold mb-1 text-uppercase small">Action Required</h6>
                        <h2 class="fw-bold mb-0 text-dark">{{ count($escalationTickets) }}</h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="rounded-3 bg-success-subtle text-success d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px;">
                        <i class="bi bi-check-circle-fill fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-semibold mb-1 text-uppercase small">Tickets Solved</h6>
                        <h2 class="fw-bold mb-0 text-dark">{{ $doneTicketsCount }}</h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="rounded-3 bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px;">
                        <i class="bi bi-ticket-detailed-fill fs-3"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-semibold mb-1 text-uppercase small">Total Tickets</h6>
                        <h2 class="fw-bold mb-0 text-dark">{{ $totalTickets }}</h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white mt-3 border-0">
                    <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-list-task me-2 text-primary"></i> Tickets Requiring Action
                    </h6>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table id="escalationTable" class="table small table-striped table-bordered" style="width:100%">
                            <thead class="table-light align-middle">
                                <tr>
                                    <th>Ticket ID</th>
                                    <th>Request Date</th>
                                    <th>Age</th>
                                    <th>Requester</th>
                                    <th>Question</th>
                                    <th>PIC</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($escalationTickets as $ticket)
                                <tr>
                                    <td class="fw-bold text-primary">#{{ $ticket['id'] }}</td>
                                    <td>{{ $ticket['date'] }}</td>
                                    <td>
                                        <span class="badge {{ (int)$ticket['age_int'] > 2 ? 'bg-danger' : 'bg-warning text-dark' }}">
                                            {{ $ticket['age'] }}
                                        </span>
                                    </td>
                                    <td>{{ $ticket['nama_penanya'] }}</td>
                                    <td title="{{ $ticket['question'] }}">{{ $ticket['question'] }}</td>
                                    <td>{{ $ticket['pic'] }}</td>
                                    <td class="text-center">
                                        <a href="{{ route('hear.ticket.show', $ticket['token']) }}" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                                            <i class="bi bi-chat-left-dots-fill me-1"></i> Answer
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-lightbulb me-2 text-info"></i> Potential KB Topics
                    </h6>
                </div>
                <div class="card-body">
                    @if (empty($top5PotentialTopics))
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-check2-all fs-1 d-block mb-2 opacity-25"></i>
                            No new suggestions.
                        </div>
                    @else
                        <div class="accordion accordion-flush" id="potentialTopicsAccordion">
                            @php $i=0; @endphp
                            @foreach($top5PotentialTopics as $topic => $questions)
                            <div class="accordion-item border mb-2 rounded-2 overflow-hidden" id="topic-item-{{ $i }}">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed py-2 px-3 bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#pot-{{ $i }}">
                                        <div class="d-flex w-100 justify-content-between align-items-center me-2">
                                            <span class="small">{{ $topic }}</span>
                                            <span class="badge bg-danger rounded-pill">{{ count($questions) }}</span>
                                        </div>
                                    </button>
                                </h2>
                                <div id="pot-{{ $i }}" class="accordion-collapse collapse" data-bs-parent="#potentialTopicsAccordion">
                                    <div class="accordion-body bg-light small p-3">
                                        <ul class="list-unstyled mb-3">
                                            @foreach(array_unique($questions) as $q)
                                                @if(isset($questionAnswerMap[$q]))
                                                <li class="mb-2 pb-2 border-bottom">
                                                    <div class="fw-bold mb-1 text-dark">Q: {{ $q }}</div>
                                                    <div class="text-muted italic">A: {{ Str::limit($questionAnswerMap[$q], 100) }}</div>
                                                </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                        <div class="d-grid">
                                            <button class="btn btn-primary btn-sm" 
                                                data-topic="{{ $topic }}" 
                                                data-content="@php
                                                    $content = '';
                                                    foreach(array_unique($questions) as $q) {
                                                        if(isset($questionAnswerMap[$q])) {
                                                            $content .= "Question: $q\nAnswer: " . $questionAnswerMap[$q] . "\n\n";
                                                        }
                                                    }
                                                    echo trim($content);
                                                @endphp" 
                                                data-bs-toggle="modal" data-bs-target="#kbModal"
                                                data-target-item="#topic-item-{{ $i }}">
                                                Add to KB
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @php $i++; @endphp
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-bar-chart-line me-2 text-success"></i> Most Frequent Topics
                    </h6>
                </div>
                <div class="card-body">
                    @if (empty($top5AllTopics))
                        <div class="text-center text-muted py-5">No data.</div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($top5AllTopics as $topic => $questions)
                            <div class="list-group-item border-0 px-0 py-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-dark small">{{ $topic }}</span>
                                    <span class="badge bg-secondary rounded-pill">{{ count($questions) }}</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: {{ min(count($questions)*10, 100) }}%"></div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="kbModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Review KB Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="kbForm">
                    <input type="hidden" id="kbTopic" name="topic">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Topic</label>
                        <input type="text" class="form-control" id="kbTopicDisplay" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="kbContent" class="form-label small fw-bold">Content</label>
                        <textarea class="form-control" id="kbContent" name="content" rows="10"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4" id="saveToKbBtn">Save to KB</button>
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
    if ($.fn.DataTable.isDataTable('#escalationTable')) {
        $('#escalationTable').DataTable().destroy();
    }

    $('#escalationTable').DataTable({});

    const kbModalElement = document.getElementById('kbModal');
    const kbModal = new bootstrap.Modal(kbModalElement);
    let targetItem = null;

    kbModalElement.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        targetItem = $(button.getAttribute('data-target-item'));
        const topic = button.getAttribute('data-topic');
        const content = button.getAttribute('data-content');
        
        document.getElementById('kbTopic').value = topic;
        document.getElementById('kbTopicDisplay').value = topic;
        document.getElementById('kbContent').value = content;
    });

    $('#saveToKbBtn').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).text('Saving...');
        $.ajax({
            type: 'POST',
            url: "{{ secure_url('hear/kb/add') }}",
            data: $('#kbForm').serialize() + '&_token={{ csrf_token() }}',
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    showAlert('Success!', 'success');
                    kbModal.hide();
                    if(targetItem) targetItem.fadeOut();
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Save to KB');
            }
        });
    });

    function showAlert(msg, type) {
        const wrapper = $(`<div class="alert alert-${type} shadow position-fixed top-0 end-0 m-3" style="z-index: 9999">${msg}</div>`);
        $('#alert-placeholder').append(wrapper);
        setTimeout(() => wrapper.fadeOut(function() { $(this).remove(); }), 3000);
    }
});
</script>
@endpush