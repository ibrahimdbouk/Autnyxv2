<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Upload a Data File</x-slot>
        <x-slot name="description">
            Select the data type and upload your CSV or Excel file. Our AI will automatically map your columns to the system.
        </x-slot>

        <form wire:submit="upload" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button
                    type="submit"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove>Upload & Map Columns</span>
                    <span wire:loading>Analysing file…</span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
