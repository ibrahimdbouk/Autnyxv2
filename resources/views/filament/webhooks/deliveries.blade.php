<div class="ax-stack-2">
    @forelse($deliveries as $d)
        <div class="ax-between ax-wrap ax-text-sm" style="border-bottom:1px solid rgba(0,0,0,.06);padding:.4rem 0">
            <span>
                <strong>{{ $d->event }}</strong>
                <span class="ax-faint ax-text-xs"> · {{ $d->created_at?->diffForHumans() }} · {{ $d->attempts }} attempt(s)</span>
            </span>
            <span>
                <x-ui.badge :color="$d->status === 'delivered' ? 'success' : ($d->status === 'failed' ? 'danger' : 'warning')">{{ $d->status }}{{ $d->response_status ? ' · ' . $d->response_status : '' }}</x-ui.badge>
            </span>
            @if($d->status !== 'delivered' && $d->response_excerpt)
                <p class="ax-faint ax-text-xs" style="width:100%;margin-top:.2rem">{{ \Illuminate\Support\Str::limit($d->response_excerpt, 200) }}</p>
            @endif
        </div>
    @empty
        <p class="ax-muted ax-text-sm">Nothing sent yet.</p>
    @endforelse
</div>
