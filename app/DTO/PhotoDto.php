<?php

namespace App\DTO;

/**
 * Copilot prompt:
 * Build an immutable PhotoDto value object with:
 * - string $path, string $filename, ?string $mime, ?int $width, ?int $height, ?float $ratio, ?\DateTimeImmutable $takenAt
 * - a static constructor: fromArray(array $data): self
 * - helpers: isPortrait(), isLandscape(), isSquare()
 * - ensure ratio = width/height when both are present (float with 4 decimals)
 */
final class PhotoDto
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly ?string $mime = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $ratio = null,
    public readonly ?\DateTimeImmutable $takenAt = null,
    public readonly ?string $etag = null,
    public readonly ?int $fileSize = null,
    public readonly ?float $qualityScore = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            $d['path'],
            $d['filename'] ?? basename($d['path']),
            $d['mime'] ?? null,
            $d['width'] ?? null,
            $d['height'] ?? null,
            $d['ratio'] ?? (isset($d['width'], $d['height']) && $d['height'] ? round($d['width']/$d['height'], 4) : null),
            $d['takenAt'] ?? null,
            $d['etag'] ?? null,
            $d['fileSize'] ?? null,
            $d['qualityScore'] ?? null,
        );
    }

    public function isPortrait(): bool  { return $this->ratio !== null && $this->ratio < 0.9; }
    public function isLandscape(): bool { return $this->ratio !== null && $this->ratio > 1.1; }
    public function isSquare(): bool    { return $this->ratio !== null && $this->ratio >= 0.9 && $this->ratio <= 1.1; }
}