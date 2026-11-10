<?php
declare(strict_types=1);

namespace App\Core;

final class UploadService
{
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

    public static function store(array $file, int $answerId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \RuntimeException('图片上传失败。');
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new \RuntimeException('上传来源不合法。');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > (int) Config::get('upload.max_bytes')) throw new \RuntimeException('图片大小超出限制。');

        $original = (string) ($file['name'] ?? 'image');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) throw new \RuntimeException('仅允许 JPG、PNG 和 GIF 图片。');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::MIME_EXT[$mime])) throw new \RuntimeException('文件内容不是允许的图片类型。');
        $normalizedExt = self::MIME_EXT[$mime];
        if (!(($normalizedExt === 'jpg' && in_array($ext, ['jpg', 'jpeg'], true)) || $ext === $normalizedExt)) {
            throw new \RuntimeException('文件扩展名与实际图片类型不一致。');
        }
        $info = @getimagesize($tmp);
        if (!$info || ($info[0] * $info[1]) > (int) Config::get('upload.max_pixels')) throw new \RuntimeException('图片结构无效或像素尺寸过大。');
        $resource = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/gif' => @imagecreatefromgif($tmp),
            default => false,
        };
        if (!$resource) throw new \RuntimeException('图片无法安全解码。');

        $relativeDir = gmdate('Y/m');
        $base = rtrim((string) Config::get('upload.dir'), '/') . '/' . $relativeDir;
        if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) throw new \RuntimeException('上传目录不可用。');
        $filename = bin2hex(random_bytes(20)) . ($mime === 'image/gif' ? '.png' : '.' . $normalizedExt);
        $path = $base . '/' . $filename;
        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($resource, $path, 90),
            'image/png', 'image/gif' => imagepng($resource, $path, 7),
            default => false,
        };
        imagedestroy($resource);
        if (!$saved) throw new \RuntimeException('图片保存失败。');
        chmod($path, 0640);
        return [
            'original_name' => mb_substr($original, 0, 180),
            'storage_path' => $relativeDir . '/' . $filename,
            'mime_type' => $mime === 'image/gif' ? 'image/png' : $mime,
            'size_bytes' => filesize($path),
            'sha256' => hash_file('sha256', $path),
            'width' => (int) $info[0], 'height' => (int) $info[1],
        ];
    }
}

