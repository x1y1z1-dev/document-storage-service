<?php

declare(strict_types=1);

use App\Services\FileManager;
use App\Services\NotificationService;

// ---------------------------------------------------------------------------
// FileManager — sanitizeFilename() and buildStoragePath() (via reflection)
// ---------------------------------------------------------------------------

function makeFileManager(): FileManager
{
    $notification = Mockery::mock(NotificationService::class);
    return new FileManager($notification);
}

function callPrivate(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
}

// ---------------------------------------------------------------------------
// sanitizeFilename
// ---------------------------------------------------------------------------

test('sanitizeFilename passes through a clean filename unchanged', function () {
    $fm     = makeFileManager();
    $result = callPrivate($fm, 'sanitizeFilename', ['report.pdf']);

    expect($result)->toBe('report.pdf');
});

test('sanitizeFilename strips forward slashes', function () {
    $fm     = makeFileManager();
    $result = callPrivate($fm, 'sanitizeFilename', ['../../etc/passwd']);

    expect($result)->not->toContain('/');
    expect($result)->not->toContain('\\');
});

test('sanitizeFilename strips backslashes', function () {
    $fm     = makeFileManager();
    $result = callPrivate($fm, 'sanitizeFilename', ['path\\to\\file.pdf']);

    expect($result)->not->toContain('\\');
});

test('sanitizeFilename strips double-dot traversal sequences', function () {
    $fm     = makeFileManager();
    $result = callPrivate($fm, 'sanitizeFilename', ['../dangerous.pdf']);

    expect($result)->not->toContain('..');
});

test('sanitizeFilename strips null bytes', function () {
    $fm     = makeFileManager();
    $input  = "file\x00name.pdf";
    $result = callPrivate($fm, 'sanitizeFilename', [$input]);

    expect($result)->not->toContain("\x00");
});

test('sanitizeFilename strips ASCII control characters 0-31', function () {
    $fm = makeFileManager();

    for ($i = 0; $i < 32; $i++) {
        $input  = 'file' . chr($i) . '.pdf';
        $result = callPrivate($fm, 'sanitizeFilename', [$input]);

        expect($result)->not->toContain(chr($i),
            "Control char 0x" . dechex($i) . " was not stripped");
    }
});

test('sanitizeFilename strips ASCII DEL character (0x7F)', function () {
    $fm     = makeFileManager();
    $input  = "file\x7fname.pdf";
    $result = callPrivate($fm, 'sanitizeFilename', [$input]);

    expect($result)->not->toContain("\x7f");
});

test('sanitizeFilename trims surrounding whitespace', function () {
    $fm     = makeFileManager();
    $result = callPrivate($fm, 'sanitizeFilename', ['  report.pdf  ']);

    expect($result)->toBe('report.pdf');
});

test('sanitizeFilename returns "unnamed" for an empty string after stripping', function () {
    $fm     = makeFileManager();
    $result = callPrivate($fm, 'sanitizeFilename', ['']);

    expect($result)->toBe('unnamed');
});

test('sanitizeFilename returns "unnamed" for a string that becomes empty after stripping dangerous chars', function () {
    $fm     = makeFileManager();
    // Only slashes and null bytes → after strip becomes empty
    $result = callPrivate($fm, 'sanitizeFilename', ["/\\\x00"]);

    expect($result)->toBe('unnamed');
});

// ---------------------------------------------------------------------------
// buildStoragePath
// ---------------------------------------------------------------------------

test('buildStoragePath returns a path matching uploads/{UUID}.{ext}', function () {
    $fm   = makeFileManager();
    $file = \Illuminate\Http\UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    $path = callPrivate($fm, 'buildStoragePath', [$file]);

    // Pattern: uploads/{uuid}.pdf
    expect($path)->toMatch('/^uploads\/[0-9a-f\-]{36}\.(pdf|docx)$/i');
});

test('buildStoragePath does not contain the original filename', function () {
    $fm   = makeFileManager();
    $file = \Illuminate\Http\UploadedFile::fake()->create('my-secret-filename.pdf', 100, 'application/pdf');

    $path = callPrivate($fm, 'buildStoragePath', [$file]);

    expect($path)->not->toContain('my-secret-filename');
    expect($path)->not->toContain('secret');
});

test('buildStoragePath starts with uploads/', function () {
    $fm   = makeFileManager();
    $file = \Illuminate\Http\UploadedFile::fake()->create('test.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $path = callPrivate($fm, 'buildStoragePath', [$file]);

    expect($path)->toStartWith('uploads/');
});

test('buildStoragePath lowercases the file extension', function () {
    $fm   = makeFileManager();
    $file = \Illuminate\Http\UploadedFile::fake()->create('REPORT.PDF', 100, 'application/pdf');

    $path = callPrivate($fm, 'buildStoragePath', [$file]);

    expect($path)->toEndWith('.pdf');
    expect($path)->not->toEndWith('.PDF');
});

test('buildStoragePath produces unique paths on successive calls', function () {
    $fm    = makeFileManager();
    $file  = \Illuminate\Http\UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
    $paths = [];

    for ($i = 0; $i < 10; $i++) {
        $paths[] = callPrivate($fm, 'buildStoragePath', [$file]);
    }

    // All 10 paths should be distinct
    expect(array_unique($paths))->toHaveCount(10);
});
