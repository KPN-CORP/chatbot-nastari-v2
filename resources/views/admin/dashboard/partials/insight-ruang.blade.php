{{--
    Insight mingguan Ruang per unit bisnis.

    Dipertahankan apa adanya dari dashboard sebelumnya supaya tidak ada yang
    hilang saat halaman ini menggantikannya. Isinya dihasilkan oleh
    `chat:analyze-weekly` (Sabtu 06:00) dan disimpan di tabel chat_insights;
    daftar unit bisnisnya mengikuti scope role, sama seperti dulu.
--}}
<div id="insight-ruang" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-robot text-primary me-2"></i>Insight Mingguan Ruang</h6>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3">
            @if($allowedBUs === [])
                <p class="text-muted small mb-0">
                    Belum ada unit bisnis dalam scope role Anda yang punya pengguna terdaftar.
                </p>
            @else
                @if(count($allowedBUs) > 1)
                    <ul class="nav nav-pills mb-3 bg-light p-1 rounded-3" role="tablist">
                        @foreach($allowedBUs as $unit)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link {{ $loop->first ? 'active' : '' }} small py-1"
                                        data-bs-toggle="pill"
                                        data-bs-target="#insight-{{ \Illuminate\Support\Str::slug($unit) }}"
                                        type="button" role="tab">{{ $unit }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="tab-content">
                    @foreach($allowedBUs as $unit)
                        @php $insight = $buInsights[$unit] ?? null; @endphp
                        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                             id="insight-{{ \Illuminate\Support\Str::slug($unit) }}" role="tabpanel">
                            @if($insight)
                                <div class="alert bg-primary bg-opacity-10 border-0 mb-3">
                                    <h6 class="fw-bold text-primary mb-2 small text-uppercase">Ringkasan {{ $unit }}</h6>
                                    <p class="text-dark mb-0 lh-base small">{{ $insight->summary }}</p>
                                </div>

                                @if(!empty($insight->top_topics))
                                    <div class="accordion accordion-flush border rounded overflow-hidden"
                                         id="acc-{{ \Illuminate\Support\Str::slug($unit) }}">
                                        @foreach($insight->top_topics as $index => $issue)
                                            @php $accId = \Illuminate\Support\Str::slug($unit) . $index; @endphp
                                            <div class="accordion-item">
                                                <h2 class="accordion-header">
                                                    <button class="accordion-button collapsed fw-semibold text-dark small"
                                                            type="button" data-bs-toggle="collapse"
                                                            data-bs-target="#topic-{{ $accId }}">
                                                        {{ $issue['topic'] ?? 'Topik' }}
                                                    </button>
                                                </h2>
                                                <div id="topic-{{ $accId }}" class="accordion-collapse collapse"
                                                     data-bs-parent="#acc-{{ \Illuminate\Support\Str::slug($unit) }}">
                                                    <div class="accordion-body bg-light bg-opacity-25">
                                                        <p class="text-secondary small mb-2">{{ $issue['analysis'] ?? 'Tidak ada analisis.' }}</p>

                                                        @if(!empty($issue['evidence']))
                                                            <ul class="list-group list-group-flush">
                                                                @foreach($issue['evidence'] as $chat)
                                                                    <li class="list-group-item bg-white small text-muted fst-italic py-2">
                                                                        <i class="bi bi-quote text-primary me-2 opacity-50"></i>{{ $chat }}
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @else
                                <div class="p-4 text-center text-muted border rounded bg-light">
                                    <i class="bi bi-clipboard-x fs-3 d-block mb-2 opacity-50"></i>
                                    <p class="small mb-0">Belum ada insight untuk unit {{ $unit }}.</p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
