<?php

namespace App\Services\Platform;

use App\Models\PlatformSettingModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;
use RuntimeException;

class PlatformSettingsService
{
    public const KEY_SITE_NAME = 'site_name';
    public const KEY_TAGLINE = 'tagline';
    public const KEY_SEO_DESCRIPTION = 'seo_description';
    public const KEY_LOGO_PATH = 'logo_path';
    public const KEY_FAVICON_PATH = 'favicon_path';
    public const KEY_OG_IMAGE_PATH = 'og_image_path';

    private const DEFAULTS = [
        self::KEY_SITE_NAME        => 'Edugame',
        self::KEY_TAGLINE          => 'Platform game kuis untuk kelas yang hidup',
        self::KEY_SEO_DESCRIPTION  => 'Platform game edukatif untuk kelas: bank soal guru, tim siswa, proyektor, dan laporan hasil bermain.',
        self::KEY_LOGO_PATH        => '/assets/brand/logo.svg',
        self::KEY_FAVICON_PATH     => '/assets/brand/favicon.svg',
        self::KEY_OG_IMAGE_PATH    => '/assets/brand/og-default.png',
    ];

    private const LOGO_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
    private const OG_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public function branding(bool $refresh = false): array
    {
        if ($refresh || self::$cache === null) {
            $rows = (new PlatformSettingModel())->findAll();
            $stored = [];
            foreach ($rows as $row) {
                $stored[(string) $row['key']] = (string) ($row['value'] ?? '');
            }

            $branding = [];
            foreach (self::DEFAULTS as $key => $default) {
                $value = trim((string) ($stored[$key] ?? ''));
                $branding[$key] = $value !== '' ? $value : $default;
            }
            self::$cache = $branding;
        }

        return self::$cache;
    }

    public function get(string $key, ?string $default = null): string
    {
        $branding = $this->branding();
        if (array_key_exists($key, $branding)) {
            return $branding[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public function faviconMimeType(?string $path = null): string
    {
        $path = strtolower((string) ($path ?? $this->get(self::KEY_FAVICON_PATH)));
        if (str_ends_with($path, '.svg')) {
            return 'image/svg+xml';
        }
        if (str_ends_with($path, '.webp')) {
            return 'image/webp';
        }
        if (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg')) {
            return 'image/jpeg';
        }
        if (str_ends_with($path, '.ico')) {
            return 'image/x-icon';
        }

        return 'image/png';
    }

    public function set(string $key, ?string $value): void
    {
        $model = new PlatformSettingModel();
        $existing = $model->where('key', $key)->first();
        $payload = [
            'key'        => $key,
            'value'      => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $model->insert($payload);
        } else {
            $model->update($existing['id'], $payload);
        }

        self::$cache = null;
    }

    /**
     * @param array{site_name?:string,tagline?:string,seo_description?:string} $fields
     */
    public function updateTextSettings(array $fields): void
    {
        foreach ([self::KEY_SITE_NAME, self::KEY_TAGLINE, self::KEY_SEO_DESCRIPTION] as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }
            $value = trim((string) $fields[$key]);
            if ($key === self::KEY_SITE_NAME && $value === '') {
                throw new DomainException('Nama situs wajib diisi.');
            }
            if (mb_strlen($value) > 190) {
                throw new DomainException('Nilai terlalu panjang untuk ' . $key);
            }
            $this->set($key, $value === '' ? null : $value);
        }
    }

    public function storeBrandUpload(?UploadedFile $file, string $settingKey): ?string
    {
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return null;
        }

        $allowed = $settingKey === self::KEY_OG_IMAGE_PATH ? self::OG_MIMES : self::LOGO_MIMES;
        $maxBytes = $settingKey === self::KEY_OG_IMAGE_PATH ? 3 * 1024 * 1024 : 2 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new DomainException('Ukuran file melebihi batas.');
        }

        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $allowed, true)) {
            throw new DomainException('Tipe file tidak didukung: ' . $mime);
        }

        $ext = strtolower((string) $file->getExtension());
        if ($ext === '' || ! preg_match('/^[a-z0-9]+$/', $ext)) {
            $map = [
                'image/png'     => 'png',
                'image/jpeg'    => 'jpg',
                'image/webp'    => 'webp',
                'image/svg+xml' => 'svg',
            ];
            $ext = $map[$mime] ?? 'bin';
        }

        $dir = FCPATH . 'uploads/brand';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Gagal membuat folder upload brand.');
        }

        $filename = $settingKey . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $file->move($dir, $filename, true);
        $publicPath = '/uploads/brand/' . $filename;

        $previous = $this->raw($settingKey);
        $this->set($settingKey, $publicPath);
        $this->deleteManagedUpload($previous);

        return $publicPath;
    }

    public function raw(string $key): ?string
    {
        $row = (new PlatformSettingModel())->where('key', $key)->first();
        if ($row === null) {
            return null;
        }
        $value = trim((string) ($row['value'] ?? ''));

        return $value === '' ? null : $value;
    }

    private function deleteManagedUpload(?string $publicPath): void
    {
        if ($publicPath === null || $publicPath === '') {
            return;
        }
        if (! str_starts_with($publicPath, '/uploads/brand/')) {
            return;
        }
        $absolute = FCPATH . ltrim($publicPath, '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
