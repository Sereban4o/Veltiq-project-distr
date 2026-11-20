@push('styles')
<style>
    .permission-matrix fieldset {
        border: 1px solid #DFE1E6;
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
    }
    .permission-matrix legend {
        font-weight: 600;
        color: #42526E;
        padding: 0 0.5rem;
    }
    .perm-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 0.5rem 1.5rem;
    }
    .perm-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
    }
    .perm-item code { color: #6B778C; font-size: 0.75rem; }
</style>
@endpush

@php
    $permissionIds = $permissions->pluck('id', 'name');
    $assigned = $assigned ?? [];
@endphp

<div class="permission-matrix">
    @foreach(\App\Support\Permissions\PermissionCatalogue::grouped() as $group)
        <fieldset>
            <legend>{{ $group['label'] }}</legend>
            <div class="perm-grid">
                @foreach($group['permissions'] as $name => $label)
                    <label class="perm-item">
                        <input type="checkbox" name="permission_ids[]" value="{{ $permissionIds[$name] ?? '' }}"
                               @checked(in_array($name, $assigned, true))>
                        <span>{{ $label }}</span>
                        <code>{{ $name }}</code>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endforeach
</div>
