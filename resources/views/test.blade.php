@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Create Employee</h2>

    <form action="{{ route('employees.store') }}" method="POST">
        @csrf

  

        <!-- Permissions Checkboxes -->
        <div class="mb-3">
            <label>Permissions</label>
            <div class="form-check">
                @foreach($permissions as $permission)
                    <div>
                        <input class="form-check-input" type="checkbox" 
                            name="permissions[]" 
                            value="{{ $permission->id }}" 
                            id="permission_{{ $permission->id }}"
                            
                            {{in_array($permission->id,$permissionIds)? 'checked':''}}
                            >

                        <label class="form-check-label" for="permission_{{ $permission->id }}">
                            {{ $permission->name }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Create Employee</button>
    </form>
</div>
@endsection
