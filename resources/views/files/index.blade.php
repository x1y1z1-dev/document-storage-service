<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- CSRF token consumed by $.ajaxSetup below (Requirements: 11.9) --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>File Storage</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            padding-top: 2.5rem;
            padding-bottom: 4rem;
            background-color: #f8f9fa;
        }
        .table-wrapper { overflow-x: auto; }
        .page-card {
            background: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            padding: 1.75rem 2rem;
        }
        .table thead th { font-size: .8125rem; letter-spacing: .04em; text-transform: uppercase; }
        .btn-upload {
            min-width: 130px;
        }
    </style>
</head>
<body>

<div class="container" style="max-width: 1100px;">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Page header                                                         --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h4 mb-0 fw-semibold">📁 File Storage</h1>
            <p class="text-muted small mb-0 mt-1">Upload and manage PDF / DOCX files. Files expire after 24 hours.</p>
        </div>

        <label for="fileInput" class="btn btn-primary btn-upload mb-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor"
                 class="bi bi-upload me-1" viewBox="0 0 16 16">
                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/>
                <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708z"/>
            </svg>
            Upload File
            <input id="fileInput" type="file" accept=".pdf,.docx" class="visually-hidden">
        </label>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Alert area                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div id="alertArea" class="mb-3"></div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- File table                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="page-card">
        <div class="table-wrapper">
            <table class="table table-hover align-middle mb-0" id="filesTable">
                <thead class="table-dark">
                    <tr>
                        <th>Filename</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Uploaded (UTC)</th>
                        <th>Expires (UTC)</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="filesTableBody">

                    @forelse ($files as $file)
                        @php
                            $fileType = match ($file->mime_type) {
                                'application/pdf' => 'PDF',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
                                default => strtoupper($file->mime_type),
                            };
                            $badgeClass = $fileType === 'PDF' ? 'danger' : 'primary';
                        @endphp
                        <tr data-id="{{ $file->id }}">
                            <td class="fw-medium">{{ $file->original_filename }}</td>
                            <td>
                                <span class="badge bg-{{ $badgeClass }}-subtle text-{{ $badgeClass }}-emphasis border border-{{ $badgeClass }}-subtle">
                                    {{ $fileType }}
                                </span>
                            </td>
                            <td class="text-muted">{{ formatFileSize($file->file_size_bytes) }}</td>
                            <td class="text-muted small">{{ $file->upload_timestamp->utc()->format('Y-m-d H:i:s') }} UTC</td>
                            <td class="text-muted small">{{ $file->expiration_timestamp->utc()->format('Y-m-d H:i:s') }} UTC</td>
                            <td class="text-center text-nowrap">
                                <a href="{{ route('files.download', $file->id) }}"
                                   class="btn btn-sm btn-outline-primary me-1">
                                    Download
                                </a>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger btn-delete"
                                        data-id="{{ $file->id }}">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr id="emptyState">
                            <td colspan="6" class="text-center text-muted py-5">
                                <div class="mb-2" style="font-size:2rem;">📂</div>
                                No files have been uploaded yet.
                            </td>
                        </tr>
                    @endforelse

                </tbody>
            </table>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Pagination                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($files->total() > $files->perPage())
        <div class="d-flex justify-content-center mt-4">
            {{ $files->links('pagination::bootstrap-5') }}
        </div>
    @endif

</div>

</body>
</html>
