<div>
<x-tallui-card title="Account Access" subtitle="Login user linked to this record." icon="o-key" :shadow="true">
    @if ($linkedUser)
        <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-50 p-4">
            <div class="min-w-0">
                <div class="truncate font-medium">{{ $linkedUser->name }}</div>
                <div class="truncate text-sm text-base-content/60">{{ $linkedUser->email }}</div>
            </div>
            <x-tallui-button
                label="Unlink"
                icon="o-link-slash"
                class="btn-ghost btn-sm text-error shrink-0"
                wire:click="unlinkUser"
                wire:confirm="Unlink this user from the record? The login account itself will not be deleted."
            />
        </div>
    @else
        <p class="mb-3 text-sm text-base-content/50">No login user linked yet.</p>
        <div class="flex flex-wrap gap-2">
            <x-tallui-button
                label="Create Login User"
                icon="o-user-plus"
                class="btn-primary btn-sm"
                wire:click="openCreateModal"
            />
            <x-tallui-button
                label="Link Existing User"
                icon="o-link"
                class="btn-ghost btn-sm"
                wire:click="openLinkModal"
            />
        </div>
    @endif
</x-tallui-card>

{{-- Create login user --}}
<x-tallui-modal id="create-login-user" title="Create Login User" icon="o-user-plus" size="sm">
    @if ($resolvedEmail)
        <p class="mb-3 text-base-content/60">
            A new login user will be created with email <strong>{{ $resolvedEmail }}</strong> and linked to this record.
        </p>
        <div class="space-y-3">
            <x-tallui-form-group label="Password" :error="$errors->first('newPassword')">
                <x-tallui-password-input name="newPassword" wire:model="newPassword" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Confirm Password" :error="$errors->first('newPasswordConfirmation')">
                <x-tallui-password-input name="newPasswordConfirmation" wire:model="newPasswordConfirmation" />
            </x-tallui-form-group>
        </div>
    @else
        <x-tallui-alert type="warning">
            This record has no email on file. Add one on the edit form, then come back to create a login user.
        </x-tallui-alert>
    @endif

    <x-slot:footer>
        <x-tallui-button label="Cancel" class="btn-ghost btn-sm" wire:click="$dispatch('close-modal', 'create-login-user')" />
        @if ($resolvedEmail)
            <x-tallui-button label="Create & Link" class="btn-primary btn-sm" wire:click="createUser" :spinner="'createUser'" />
        @endif
    </x-slot:footer>
</x-tallui-modal>

{{-- Link existing user --}}
<x-tallui-modal id="link-existing-user" title="Link Existing User" icon="o-link" size="sm">
    <x-tallui-input
        name="userSearch"
        label="Search by name or email"
        wire:model.live.debounce.400ms="userSearch"
        placeholder="Start typing..."
    />

    <div class="mt-3 max-h-64 divide-y divide-base-200 overflow-y-auto">
        @forelse ($searchResults as $candidate)
            <div class="flex items-center justify-between gap-3 py-2">
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $candidate->name }}</div>
                    <div class="truncate text-base-content/60">{{ $candidate->email }}</div>
                </div>
                <x-tallui-button label="Link" class="btn-primary btn-xs shrink-0" wire:click="linkUser({{ $candidate->id }})" />
            </div>
        @empty
            @if (trim($userSearch) !== '')
                <p class="py-3 text-base-content/50">No matching users found.</p>
            @endif
        @endforelse
    </div>

    <x-slot:footer>
        <x-tallui-button label="Close" class="btn-ghost btn-sm" wire:click="$dispatch('close-modal', 'link-existing-user')" />
    </x-slot:footer>
</x-tallui-modal>
</div>
