<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Profile photo uploads.
 *
 * Anything a stranger can put on your web server deserves suspicion, so an
 * upload has to clear several gates before it is kept:
 *
 *   1. it arrived through a real HTTP upload (is_uploaded_file)
 *   2. it is under the size cap
 *   3. getimagesize() recognises it as one of four raster formats, and the
 *      dimensions are sane
 *   4. it is re-encoded through GD, which drops EXIF, colour profiles, and
 *      anything hiding in the file that is not pixels
 *   5. it is written with a generated name and a fixed extension, so the
 *      original filename never reaches the filesystem
 *
 * The directory it lands in also has PHP execution switched off, so even a
 * file that somehow got through all of that cannot be run.
 */
final class Uploads
{
    public const MAX_BYTES     = 3 * 1024 * 1024;   // 3MB before re-encoding
    public const MAX_DIMENSION = 512;               // avatars are never bigger
    public const SUBDIR        = 'uploads/avatars';

    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** Absolute path of the directory uploads are written to. */
    public static function dir(): string
    {
        // app/ normally sits beside public_html; fall back to a flat layout.
        $candidates = [
            dirname(__DIR__) . '/public_html/' . self::SUBDIR,
            dirname(__DIR__) . '/' . self::SUBDIR,
        ];

        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $target = $candidates[0];
        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new ApiError(500, 'upload_dir', 'The upload directory is missing and could not be created.');
        }

        return $target;
    }

    /** Stored filename -> the URL the browser loads it from. */
    public static function publicUrl(string $filename): string
    {
        return '/' . self::SUBDIR . '/' . $filename;
    }

    /**
     * Validate and store one uploaded avatar.
     *
     * @param string $field the multipart field name
     * @return string the stored filename, to put in avatar_path
     */
    public static function acceptAvatar(string $field = 'photo'): string
    {
        self::assertPostSurvived();

        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'], $file['error'])) {
            throw ApiError::badRequest('No photo was uploaded.', 'no_file');
        }

        self::assertUploadOk((int) $file['error']);

        $tmp = (string) $file['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw ApiError::badRequest('That was not a valid upload.', 'no_file');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw ApiError::tooLarge('Photos must be 3MB or smaller.');
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset($info[2]) || !isset(self::ALLOWED[$info[2]])) {
            throw ApiError::badRequest('That file is not a JPEG, PNG, GIF or WebP image.', 'bad_image');
        }

        [$width, $height] = $info;
        if ($width < 8 || $height < 8 || $width > 8000 || $height > 8000) {
            throw ApiError::badRequest('That image has unusable dimensions.', 'bad_image');
        }

        $filename = Ids::ulid() . '.jpg';
        $target   = self::dir() . '/' . $filename;

        if (!self::reencode($tmp, $target, $info[2])) {
            throw new ApiError(500, 'image_failed', 'That image could not be processed.');
        }

        @chmod($target, 0644);

        return $filename;
    }

    /**
     * Square-crop, downscale and re-encode as JPEG. Everything that is not
     * pixel data is discarded in the process, which is the point.
     */
    private static function reencode(string $source, string $target, int $type): bool
    {
        if (!extension_loaded('gd')) {
            // Without GD we cannot strip anything, so we do not accept the file
            // rather than store an unexamined one.
            throw new ApiError(
                503,
                'gd_missing',
                'This server cannot process images. Enable the GD extension in hPanel.'
            );
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default        => false,
        };

        if (!$src instanceof \GdImage) {
            return false;
        }

        try {
            $w = imagesx($src);
            $h = imagesy($src);

            // Centre square crop, so avatars never arrive stretched.
            $side = min($w, $h);
            $sx   = intdiv($w - $side, 2);
            $sy   = intdiv($h - $side, 2);
            $size = min($side, self::MAX_DIMENSION);

            $dst = imagecreatetruecolor($size, $size);
            if (!$dst instanceof \GdImage) {
                return false;
            }

            // Flatten transparency onto the app background rather than black.
            $bg = imagecolorallocate($dst, 0x14, 0x17, 0x0F);
            imagefilledrectangle($dst, 0, 0, $size, $size, $bg);

            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

            $ok = imagejpeg($dst, $target, 86);
            imagedestroy($dst);

            return $ok;
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * A POST larger than post_max_size arrives with $_FILES and $_POST both
     * empty and no error code to read - the classic silent PHP upload failure.
     * Catch it explicitly so the user gets a real message.
     */
    private static function assertPostSurvived(): void
    {
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limit    = self::bytesFromIni((string) ini_get('post_max_size'));

        if ($limit > 0 && $declared > $limit && $_FILES === []) {
            throw ApiError::tooLarge(
                'That photo is larger than this server accepts (' . ini_get('post_max_size') . ').'
            );
        }
    }

    private static function assertUploadOk(int $code): void
    {
        match ($code) {
            UPLOAD_ERR_OK         => null,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => throw ApiError::tooLarge('That photo is too large.'),
            UPLOAD_ERR_PARTIAL    => throw ApiError::badRequest('The upload did not finish.', 'upload_partial'),
            UPLOAD_ERR_NO_FILE    => throw ApiError::badRequest('No photo was uploaded.', 'no_file'),
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => throw new ApiError(500, 'upload_failed', 'The server could not store the photo.'),
            UPLOAD_ERR_EXTENSION  => throw new ApiError(500, 'upload_failed', 'The upload was blocked by the server.'),
            default               => throw new ApiError(500, 'upload_failed', 'The upload failed.'),
        };
    }

    private static function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $num  = (int) $value;

        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    /**
     * Delete a stored avatar. Called when one is replaced or cleared, so old
     * files do not accumulate. Never touches anything outside the upload
     * directory, whatever the database happens to contain.
     */
    public static function forget(?string $filename): void
    {
        if (!is_string($filename) || $filename === '') {
            return;
        }

        // Only ever a bare generated name: no separators, no traversal.
        if (preg_match('/^[0-9A-Z]{26}\.(jpg|png|gif|webp)$/', $filename) !== 1) {
            return;
        }

        $path = self::dir() . '/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
