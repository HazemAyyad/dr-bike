@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Edit Project</h2>

    <form action="{{ route('projects.update', $project->id) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <!-- Name -->
        <div class="mb-3">
            <label for="name" class="form-label">Project Name</label>
            <input type="text" name="name" id="name" class="form-control"
                 >
        </div>

        <!-- Project Cost -->
        <div class="mb-3">
            <label for="project_cost" class="form-label">Project Cost</label>
            <input type="number" name="project_cost" id="project_cost" class="form-control"
                value="{{ old('project_cost', $project->project_cost) }}" min="0" required>
        </div>

        <!-- Images -->
        <!-- <div class="mb-3">
            <label for="images" class="form-label">Project Images</label>
            <input type="file" name="images[]" id="images" class="form-control" multiple>

            @if(is_array($project->images))
                <div class="mt-2">
                    <p>Current Images:</p>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($project->images as $image)
                            <img src="{{ asset('storage/' . $image) }}" alt="Project Image" width="100" class="img-thumbnail">
                        @endforeach
                    </div>
                </div>
            @endif
        </div> -->

        <!-- Payment Method -->
        <div class="mb-3">
            <label for="payment_method" class="form-label">Payment Method</label>
            <input type="text" name="payment_method" id="payment_method" class="form-control"
                value="{{ old('payment_method', $project->payment_method) }}" required>
        </div>

        <!-- Notes -->
        <div class="mb-3">
            <label for="notes" class="form-label">Notes</label>
            <textarea name="notes" id="notes" rows="4" class="form-control">{{ old('notes', $project->notes) }}</textarea>
        </div>

        <!-- Partnership Papers -->
        <div class="mb-3">
            <label for="partnership_papers" class="form-label">Partnership Papers</label>
            <input type="text" name="partnership_papers" id="partnership_papers" class="form-control"
                value="{{ old('partnership_papers', $project->partnership_papers) }}" required>
        </div>

        <!-- Achievement Percentage -->
        <div class="mb-3">
            <label for="achievement_percentage" class="form-label">Achievement %</label>
            <input type="number" name="achievement_percentage" id="achievement_percentage" class="form-control"
                value="{{ old('achievement_percentage', $project->achievement_percentage) }}" min="0" max="100">
        </div>

        <!-- Status -->
        <div class="mb-3">
            <label for="status" class="form-label">Status</label>
            <select name="status" id="status" class="form-control">
                <option value="pending" {{ old('status', $project->status) === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="in_progress" {{ old('status', $project->status) === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="completed" {{ old('status', $project->status) === 'completed' ? 'selected' : '' }}>Completed</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Update Project</button>
    </form>
</div>
@endsection
