<x-filament-panels::page>
    @php
        $u = auth()->user();
    @endphp

    <div style="max-width:640px">
        <div style="background:var(--ax-surface,#fff);border:1px solid var(--ax-border,#e5e7eb);border-radius:.75rem;padding:1.5rem">
            <div style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--ax-muted,#6b7280);margin-bottom:1rem">
                Your details
            </div>

            <dl style="display:grid;grid-template-columns:9rem 1fr;row-gap:.85rem;column-gap:1rem;margin:0">
                <dt style="color:var(--ax-muted,#6b7280);font-size:.9rem">Name</dt>
                <dd style="margin:0;font-weight:600;color:var(--ax-ink,#111827)">{{ $u?->name ?? '—' }}</dd>

                <dt style="color:var(--ax-muted,#6b7280);font-size:.9rem">Email</dt>
                <dd style="margin:0;font-weight:600;color:var(--ax-ink,#111827)">{{ $u?->email ?? '—' }}</dd>

                <dt style="color:var(--ax-muted,#6b7280);font-size:.9rem">Role</dt>
                <dd style="margin:0;font-weight:600;color:var(--ax-ink,#111827)">{{ $u?->roleLabel() ?? '—' }}</dd>
            </dl>

            <p style="margin:1.25rem 0 0;font-size:.85rem;color:var(--ax-muted,#6b7280)">
                Use <strong>Edit profile</strong> to change your name or email, or
                <strong>Change password</strong> to set a new password. Changing your
                password asks for your current one first.
            </p>
        </div>
    </div>
</x-filament-panels::page>
