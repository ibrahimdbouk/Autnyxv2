{{-- W9: data checks (semantic / cross-dataset findings) and the feed registry. --}}
@php($findings = $this->getFindings())
@php($feeds = $this->getFeeds())
@php($sev = ['critical' => ['var(--ax-danger-fg)', 'var(--ax-danger-soft)', 'CRITICAL'], 'warning' => ['var(--ax-warning-fg)', 'var(--ax-warning-soft)', 'WARNING'], 'info' => ['var(--ax-neutral-fg)', 'var(--ax-neutral-soft)', 'SUGGESTION']])

<section id="checks" class="dh-section" style="margin-bottom:1.5rem">
    <h2 class="dh-section-title">Data checks</h2>
    <div class="dh-muted" style="margin:.25rem 0 .75rem;max-width:75ch">
        Data that is well-formed but wrong in a way that would mislead detection — a store that stopped sending sales,
        a half-loaded day, future dates, impossible prices. Run after every import and every night.
    </div>
    @if($findings->isEmpty())
        <div style="color:var(--ax-muted);padding:1rem 1.25rem;border:1px dashed var(--ax-line);border-radius:.75rem">No open findings.</div>
    @else
        <div style="display:flex;flex-direction:column;gap:.6rem">
            @foreach($findings as $f)
                @php($t = $sev[$f->severity] ?? $sev['info'])
                <div style="border:1px solid var(--ax-line);border-radius:.75rem;padding:.8rem 1rem;background:var(--ax-bg)">
                    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                        <span style="font-size:.66rem;font-weight:800;letter-spacing:.05em;color:{{ $t[0] }};background:{{ $t[1] }};padding:.15rem .45rem;border-radius:.35rem">{{ $t[2] }}</span>
                        <strong style="color:var(--ax-ink)">{{ \App\Services\DataQuality\DataQualityChecks::LABELS[$f->check] ?? $f->check }}</strong>
                        @if($f->subject)<span class="dh-muted">· {{ $f->subject }}</span>@endif
                        <span class="dh-muted" style="margin-left:auto;font-size:.75rem">since {{ $f->first_seen_at?->diffForHumans() }}@if($f->occurrences > 1) · seen {{ $f->occurrences }}×@endif</span>
                    </div>
                    <div style="font-size:.85rem;color:var(--ax-muted);margin-top:.35rem;line-height:1.45">{{ $f->message }}</div>
                    @if($f->check === 'alias_suggestions' && \App\Filament\Resources\QuarantinedRowResource::canAccess())
                        <a href="{{ \App\Filament\Resources\QuarantinedRowResource::getUrl('index') }}" style="font-size:.8rem;color:var(--ax-primary,#4f46e5)">Review on the Quarantine page →</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>

<section id="feeds" class="dh-section" style="margin-bottom:1.5rem">
    <h2 class="dh-section-title">Feeds</h2>
    <div class="dh-muted" style="margin:.25rem 0 .75rem;max-width:75ch">
        Every source that delivers data, and what it normally sends — learned from its own history. A batch far outside
        its usual size or with changed columns is flagged; an automated feed that stops arriving is reported late.
    </div>
    @if($feeds->isEmpty())
        <div style="color:var(--ax-muted);padding:1rem 1.25rem;border:1px dashed var(--ax-line);border-radius:.75rem">No feeds yet — the first batch registers its feed.</div>
    @else
        <style>
            @media (max-width: 640px) {
                .dh-feeds thead { display:none; }
                .dh-feeds tr { display:block; padding:.5rem 0; }
                .dh-feeds td { display:block; padding:.15rem .25rem !important; }
                .dh-feeds td[data-k]::before { content: attr(data-k) ': '; color: var(--ax-muted); font-size:.75rem; }
            }
        </style>
        <div style="overflow-x:auto">
            <table class="dh-feeds" style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead>
                    <tr style="text-align:left;color:var(--ax-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">
                        <th style="padding:.4rem .5rem">Feed</th><th style="padding:.4rem .5rem">Last batch</th>
                        <th style="padding:.4rem .5rem">Usually</th><th style="padding:.4rem .5rem">Rows (usual band)</th><th style="padding:.4rem .5rem">Status</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($feeds as $c)
                    @php($st = ['ok' => ['var(--ax-success-fg)', 'OK'], 'warning' => ['var(--ax-warning-fg)', 'CHECK'], 'late' => ['var(--ax-danger-fg)', 'LATE']][$c->status] ?? ['var(--ax-muted)', strtoupper((string) $c->status)])
                    <tr style="border-top:1px solid var(--ax-line)">
                        <td style="padding:.5rem">
                            <div style="font-weight:600;color:var(--ax-ink)">{{ $c->label() }}</div>
                            @if($c->owner_email)<div class="dh-muted" style="font-size:.75rem">owner: {{ $c->owner_email }}</div>@endif
                        </td>
                        <td data-k="Last batch" style="padding:.5rem">{{ $c->last_batch_at ? \App\Support\Tenancy\TenantClock::display($c->last_batch_at)?->format('M j, H:i') . ' (' . $c->last_batch_at->diffForHumans() . ')' : '—' }}</td>
                        <td data-k="Usually" style="padding:.5rem">
                            @if($c->freshness_sla_hours) within {{ $c->freshness_sla_hours }}h (set)
                            @elseif($c->expected_every_hours) every ~{{ $c->expected_every_hours < 48 ? $c->expected_every_hours . 'h' : round($c->expected_every_hours / 24) . ' days' }}
                            @else — @endif
                        </td>
                        <td data-k="Rows" style="padding:.5rem">
                            {{ $c->last_rows !== null ? number_format($c->last_rows) : '—' }}
                            @if($c->rows_median)<span class="dh-muted">({{ number_format($c->rows_low) }}–{{ number_format($c->rows_high) }})</span>@endif
                            @if($c->batches_seen < \App\Services\DataQuality\FeedMonitor::minHistory())<div class="dh-muted" style="font-size:.72rem">learning — {{ $c->batches_seen }} batch(es) so far</div>@endif
                        </td>
                        <td style="padding:.5rem">
                            <span style="font-size:.68rem;font-weight:800;color:{{ $st[0] }}">{{ $st[1] }}</span>
                            @if($c->status_detail)<div class="dh-muted" style="font-size:.75rem;max-width:40ch">{{ $c->status_detail }}</div>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
