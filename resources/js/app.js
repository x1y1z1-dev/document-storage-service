import 'bootstrap';
import $ from 'jquery';

$(function () {
console.log(1234);
    // -----------------------------------------------------------------------
    // Global AJAX setup — attach CSRF token to every request (Req 11.9)
    // -----------------------------------------------------------------------
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // -----------------------------------------------------------------------
    // Helper: render a dismissible Bootstrap alert above the table
    // -----------------------------------------------------------------------
    function showAlert(message, type) {
        // type is a Bootstrap colour: 'success' | 'danger' | 'warning' | 'info'
        var html =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                '<span>' + $('<div>').text(message).html() + '</span>' +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '</div>';
        $('#alertArea').html(html);
    }

    // -----------------------------------------------------------------------
    // Helper: build a table row HTML from a FileRecord JSON object
    // (mirrors the Blade template row structure for consistency)
    // -----------------------------------------------------------------------
    function buildRow(record) {
        var fileType = '';
        if (record.mime_type === 'application/pdf') {
            fileType = 'PDF';
        } else if (record.mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            fileType = 'DOCX';
        } else {
            fileType = record.mime_type.toUpperCase();
        }

        var formattedSize = formatFileSize(record.file_size_bytes);

        // Convert ISO 8601 timestamps to "YYYY-MM-DD HH:MM:SS UTC"
        var uploadedAt = isoToUtcDisplay(record.upload_timestamp);
        var expiresAt  = isoToUtcDisplay(record.expiration_timestamp);

        var downloadUrl = '/files/' + record.id + '/download';

        return (
            '<tr data-id="' + record.id + '">' +
                '<td>' + $('<div>').text(record.original_filename).html() + '</td>' +
                '<td>' + fileType + '</td>' +
                '<td>' + formattedSize + '</td>' +
                '<td>' + uploadedAt + '</td>' +
                '<td>' + expiresAt + '</td>' +
                '<td class="text-center text-nowrap">' +
                    '<a href="' + downloadUrl + '" class="btn btn-sm btn-outline-primary me-1">Download</a>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="' + record.id + '">Delete</button>' +
                '</td>' +
            '</tr>'
        );
    }

    // -----------------------------------------------------------------------
    // Helper: JavaScript equivalent of formatFileSize() (mirrors PHP helper)
    // -----------------------------------------------------------------------
    function formatFileSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1048576) {
            return roundTo2(bytes / 1024) + ' KB';
        }
        return roundTo2(bytes / 1048576) + ' MB';
    }

    function roundTo2(n) {
        return Math.round(n * 100) / 100;
    }

    // -----------------------------------------------------------------------
    // Helper: convert ISO 8601 string to "YYYY-MM-DD HH:MM:SS UTC"
    // -----------------------------------------------------------------------
    function isoToUtcDisplay(iso) {
        try {
            var d = new Date(iso);
            var pad = function (n) { return String(n).padStart(2, '0'); };
            return d.getUTCFullYear() + '-' +
                   pad(d.getUTCMonth() + 1) + '-' +
                   pad(d.getUTCDate()) + ' ' +
                   pad(d.getUTCHours()) + ':' +
                   pad(d.getUTCMinutes()) + ':' +
                   pad(d.getUTCSeconds()) + ' UTC';
        } catch (e) {
            return iso;
        }
    }

    // -----------------------------------------------------------------------
    // jQuery async file upload (Requirements: 1.1, 1.2, 1.7)
    // -----------------------------------------------------------------------
    $('#fileInput').on('change', function () {
        var file = this.files[0];
        if (!file) {
            return;
        }

        var formData = new FormData();
        formData.append('file', file);

        // Reset the input so the same file can be re-selected if needed
        $(this).val('');

        $.ajax({
            url: '/files',
            method: 'POST',
            data: formData,
            processData: false,   // prevent jQuery from serialising FormData
            contentType: false,   // let the browser set the multipart boundary
            success: function (data, status, xhr) {
                if (xhr.status === 201) {
                    // Remove empty-state row if present
                    $('#emptyState').remove();

                    // Prepend new row to table body
                    $('#filesTableBody').prepend(buildRow(data));

                    showAlert('File "' + data.original_filename + '" uploaded successfully.', 'success');
                }
            },
            error: function (xhr) {
                var msg = 'An unexpected error occurred. Please try again.';

                if (xhr.status === 422) {
                    try {
                        var body = xhr.responseJSON;
                        if (body && body.error) {
                            if (body.error.details) {
                                var details = [];
                                $.each(body.error.details, function (field, messages) {
                                    $.each(messages, function (i, m) {
                                        details.push(m);
                                    });
                                });
                                msg = details.join(' ');
                            } else if (body.error.message) {
                                msg = body.error.message;
                            }
                        }
                    } catch (e) {
                        msg = 'Validation failed. Please check the file and try again.';
                    }
                    showAlert(msg, 'danger');

                } else if (xhr.status === 429) {
                    showAlert('Too many upload requests. Please wait a moment before trying again.', 'warning');

                } else {
                    showAlert('A server error occurred while uploading the file. Please try again later.', 'danger');
                }
            }
        });
    });

    // -----------------------------------------------------------------------
    // jQuery async delete handler (Requirements: 3.2, 3.5, 3.8)
    // Event delegation so dynamically added rows also get the handler.
    // -----------------------------------------------------------------------
    $('#filesTableBody').on('click', '.btn-delete', function () {
        var $button = $(this);
        var fileId  = $button.data('id');
        var $row    = $button.closest('tr');

        // Disable button to prevent double-click during request
        $button.prop('disabled', true);

        $.ajax({
            url: '/files/' + fileId,
            method: 'DELETE',
            success: function (data, status, xhr) {
                if (xhr.status === 200) {
                    $row.fadeOut(300, function () {
                        $(this).remove();

                        // Show empty-state if the table body is now empty
                        if ($('#filesTableBody tr').length === 0) {
                            $('#filesTableBody').append(
                                '<tr id="emptyState">' +
                                    '<td colspan="6" class="text-center text-muted py-4">' +
                                        'No files have been uploaded yet.' +
                                    '</td>' +
                                '</tr>'
                            );
                        }
                    });
                }
            },
            error: function (xhr) {
                $button.prop('disabled', false);

                var msg = 'Failed to delete the file. Please try again.';

                try {
                    var body = xhr.responseJSON;
                    if (body && body.error && body.error.message) {
                        msg = body.error.message;
                    }
                } catch (e) { /* use default msg */ }

                showAlert(msg, 'danger');
            }
        });
    });

}); // end document ready
