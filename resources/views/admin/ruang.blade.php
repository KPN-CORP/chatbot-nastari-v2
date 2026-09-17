@extends('layouts.admin')

@section('content')
<div class="container-fluid p-0" style="height: 80vh;">
    <div class="row g-0 h-100 shadow-sm border rounded overflow-hidden">
        
        <div class="col-md-4 col-lg-3 border-end bg-white d-flex flex-column h-100">
            
            <div class="flex-shrink-0 p-3 border-bottom bg-light">
                <form action="{{ route('admin.ruang') }}" method="GET">
                    <div class="row g-2">
                        <div class="col-6">
                            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                                @for($y = date('Y'); $y >= 2024; $y--)
                                    <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-6">
                            <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ str_pad($m, 2, '0', STR_PAD_LEFT) }}" {{ $month == $m ? 'selected' : '' }}>
                                        {{ date('F', mktime(0, 0, 0, $m, 10)) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-12">
                            <select name="bu" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">-- All Business Unit --</option>
                                @foreach($businessUnits as $bu)
                                    <option value="{{ $bu }}" {{ $filterBu == $bu ? 'selected' : '' }}>{{ $bu }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flex-grow-1 overflow-auto">
                @if($chatList->count() > 0)
                    <div class="list-group list-group-flush">
                        @foreach($chatList as $item)
                            <a href="{{ route('admin.ruang', ['year' => $year, 'month' => $month, 'bu' => $filterBu, 'id' => $item->id]) }}" 
                               class="list-group-item list-group-item-action border-0 py-3 {{ $selectedId == $item->id ? 'bg-primary bg-opacity-10 border-start border-4 border-primary' : '' }}">
                                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 text-truncate fw-bold text-dark" style="max-width: 70%;">{{ $item->employee_name }}</h6>
                                    <small class="text-muted" style="font-size: 10px;">{{ $item->time }}</small>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <p class="mb-0 text-muted small text-truncate pe-2" style="max-width: 85%;">
                                        {{ $item->preview }}
                                    </p>
                                    @if($selectedId == $item->id)
                                        <i class="bi bi-chevron-right text-primary small"></i>
                                    @endif
                                </div>
                                <div class="mt-1">
                                    <span class="badge bg-light text-secondary border">{{ $item->business_unit }}</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="text-center p-5 text-muted d-flex flex-column align-items-center justify-content-center h-100">
                        <i class="bi bi-chat-square-text fs-1 mb-3 d-block opacity-25"></i>
                        <small>No chat history found.</small>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-md-8 col-lg-9 bg-light d-flex flex-column h-100">
            @if($activeChat)
                
                <div class="flex-shrink-0 p-3 bg-white border-bottom d-flex justify-content-between align-items-center shadow-sm" style="z-index: 10;">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px; font-size: 12px;">
                            {{ substr($activeChat['meta_user']['name'], 0, 1) }}
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">{{ $activeChat['meta_user']['name'] }}</h6>
                            <small class="text-muted">
                                {{ $activeChat['meta_user']['employee_id'] }} | {{ $activeChat['meta_user']['bu'] }}
                            </small>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-success"><i class="bi bi-whatsapp me-1"></i> {{ $activeChat['meta_user']['phone'] }}</span>
                    </div>
                </div>

                <div class="flex-grow-1 overflow-auto p-4" style="background-color: #f0f2f5;">
                    <div class="d-flex flex-column gap-3">
                        @php $lastDate = null; @endphp
                        
                        @foreach($activeChat['history_chat'] as $chat)
    @php
        // Ambil timestamp, jika tidak ada, gunakan 'original_date' dari controller, atau waktu saat ini sebagai jalan terakhir
        $rawTimestamp = $chat['timestamp'] ?? $chat['original_date'] ?? now();
        $currentDate = \Carbon\Carbon::parse($rawTimestamp)->format('d M Y');
        $chatTime = \Carbon\Carbon::parse($rawTimestamp)->format('H:i');
    @endphp

    @if($lastDate != $currentDate)
        <div class="text-center my-3 sticky-top" style="top: 10px; z-index: 5;">
            <span class="badge bg-white text-secondary border shadow-sm px-3 py-2 rounded-pill fw-normal">
                {{ $currentDate }}
            </span>
        </div>
        @php $lastDate = $currentDate; @endphp
    @endif

    <div class="d-flex {{ ($chat['sender'] ?? '') == 'User' ? 'justify-content-start' : 'justify-content-end' }} mb-3">
        <div class="card border-0 shadow-sm" style="max-width: 75%; font-size:12px;border-radius: 12px; {{ ($chat['sender'] ?? '') == 'AI' ? 'background-color: #dcf8c6; border-top-right-radius: 0;' : 'background-color: #fff; border-top-left-radius: 0;' }}">
            <div class="card-body py-2 px-3">
                @if(($chat['sender'] ?? '') == 'AI')
                    <div class="d-flex align-items-center mb-1 text-success fw-bold small">
                        <i class="bi bi-robot me-1"></i> Nastari
                    </div>
                @endif
                
                {{-- Gunakan ?? '' agar tidak error jika key text/message kosong --}}
                <p class="mb-1 text-dark" style="white-space: pre-line;">{{ $chat['text'] ?? $chat['message'] ?? '' }}</p>
                
                <div class="text-end">
                    <small class="text-muted" style="font-size: 12px;">
                        {{ $chatTime }}
                    </small>
                </div>
            </div>
        </div>
    </div>
@endforeach
                    </div>
                </div>
            @else
                <div class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-muted h-100">
                    <img src="https://img.freepik.com/free-vector/flat-conversation-concept-background_23-2148169720.jpg?w=740" alt="Select Chat" style="width: 250px; opacity: 0.7; mix-blend-mode: multiply;">
                    <h5 class="mt-4 fw-light">Select a conversation to view details</h5>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection