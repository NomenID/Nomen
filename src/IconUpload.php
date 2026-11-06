<?php
namespace Nomen\Src;

class IconUpload
{
    /**
     * 上传并裁剪成 135×135，返回文件名；失败返回 null。
     */
    public static function store(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            error_log('Icon upload error: file error code ' . (int)($file['error'] ?? -1));
            return null;
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!function_exists('finfo_open')) {
            error_log('Icon upload error: fileinfo extension missing');
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            error_log('Icon upload error: invalid mime type ' . $mimeType);
            return null;
        }

        if ((int)$file['size'] > 2 * 1024 * 1024) {
            error_log('Icon upload error: file too large ' . (int)$file['size']);
            return null;
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            error_log('Icon upload error: GD library not available or missing webp support');
            return null;
        }

        $src = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/gif'  => @imagecreatefromgif($file['tmp_name']),
            'image/webp' => @imagecreatefromwebp($file['tmp_name']),
            default      => null,
        };

        if (!$src) {
            error_log('Icon upload error: failed to create image from ' . $mimeType);
            return null;
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        $targetSize = 135;

        if ($srcWidth > $srcHeight) {
            $cropSize = $srcHeight;
            $cropX = (int)(($srcWidth - $cropSize) / 2);
            $cropY = 0;
        } else {
            $cropSize = $srcWidth;
            $cropX = 0;
            $cropY = (int)(($srcHeight - $cropSize) / 2);
        }

        $dst = imagecreatetruecolor($targetSize, $targetSize);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetSize, $targetSize, $cropSize, $cropSize);

        $base = time() . '-' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $uploadDir = dirname(__DIR__) . '/public/assets/develops/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            error_log('Icon upload error: failed to create directory ' . $uploadDir);
            imagedestroy($src);
            imagedestroy($dst);
            return null;
        }

        $savePng = $mimeType !== 'image/webp';
        $filename = $base . ($savePng ? '.png' : '.webp');
        $destPath = $uploadDir . $filename;
        $result = $savePng ? @imagepng($dst, $destPath, 9) : @imagewebp($dst, $destPath, 85);

        if ($result && $savePng && filesize($destPath) > 80 * 1024) {
            $webpPath = $uploadDir . $base . '.webp';
            if (@imagewebp($dst, $webpPath, 85)) {
                @unlink($destPath);
                $filename = $base . '.webp';
            }
        }

        imagedestroy($src);
        imagedestroy($dst);

        if (!$result) {
            error_log('Icon upload error: failed to save image ' . $destPath);
            return null;
        }

        return $filename;
    }

    public static function url(?string $icon): string
    {
        return $icon ? '/assets/develops/' . $icon : '';
    }
}
