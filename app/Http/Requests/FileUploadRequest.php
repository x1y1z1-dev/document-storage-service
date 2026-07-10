<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class FileUploadRequest extends FormRequest
{
    /**
     * All upload requests are allowed (authentication is not in scope).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules driven by config('filestorage.*').
     *
     * The 'max' rule expects kilobytes, so we convert max_upload_bytes → KB.
     */
    public function rules(): array
    {
        $maxKb = (int) ceil(config('filestorage.max_upload_bytes') / 1024);

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimes:pdf,docx',
            ],
        ];
    }

    /**
     * Custom human-readable messages for MIME and size violations.
     */
    public function messages(): array
    {
        $maxBytes = config('filestorage.max_upload_bytes');
        $maxMb    = round($maxBytes / 1_048_576, 2);

        return [
            'file.required' => 'No file was provided. Please select a PDF or DOCX file to upload.',
            'file.file'     => 'The uploaded value is not a valid file.',
            'file.mimes'    => 'Only PDF and DOCX files are accepted (application/pdf or application/vnd.openxmlformats-officedocument.wordprocessingml.document).',
            'file.max'      => "The file exceeds the maximum allowed size of {$maxMb} MB.",
        ];
    }

    /**
     * Override failedValidation() to emit a WARNING log before throwing,
     * including the filename, detected MIME or size, and violated constraint.
     *
     * Requirements: 9.5
     */
    protected function failedValidation(Validator $validator): void
    {
        $uploadedFile = $this->file('file');
        $errors       = $validator->errors();

        $filename     = $uploadedFile?->getClientOriginalName() ?? '(no file)';
        $detectedMime = $uploadedFile?->getMimeType() ?? '(unknown)';
        $sizeBytes    = $uploadedFile?->getSize() ?? 0;

        // Resolve failed rule names from the validator's failed() map.
        // $validator->failed() returns ['field' => ['RuleName' => [params], ...], ...]
        $failedRuleNames = array_keys($validator->failed()['file'] ?? []);

        // Build a context-rich log entry per Requirement 9.5
        $context = [
            'filename'          => $filename,
            'detected_mime'     => $detectedMime,
            'size_bytes'        => $sizeBytes,
            'violated_rules'    => $failedRuleNames,
            'validation_errors' => $errors->toArray(),
        ];

        // Describe the primary violated constraint in the log message
        $failedLower = array_map('strtolower', $failedRuleNames);

        if (in_array('mimes', $failedLower, true)) {
            $constraint = "MIME type '{$detectedMime}' is not allowed";
        } elseif (in_array('max', $failedLower, true)) {
            $maxBytes   = config('filestorage.max_upload_bytes');
            $constraint = "file size {$sizeBytes} bytes exceeds maximum {$maxBytes} bytes";
        } else {
            $constraint = implode(', ', $failedRuleNames) ?: 'unknown constraint';
        }

        Log::warning("File upload validation rejected: {$constraint}", $context);

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
