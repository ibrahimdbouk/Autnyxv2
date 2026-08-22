<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Upload a Data File</x-slot>
        <x-slot name="description">
            Select the data type and upload your CSV or Excel file. Our AI will automatically map your columns to the system.
        </x-slot>

        <div class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button
                    wire:click="startImport"
                    wire:loading.attr="disabled"
                    wire:target="startImport"
                >
                    <span wire:loading.remove wire:target="startImport">Upload &amp; Map Columns</span>
                    <span wire:loading wire:target="startImport">Analysing file…</span>
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
