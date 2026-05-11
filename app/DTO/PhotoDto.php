<?php

namespace App\DTO;

final class PhotoDto
{
    public function __construct(
        public readonly string             $path,
        public readonly string             $filename,
        public readonly ?string            $mime        = null,
        public readonly ?int               $width       = null,
        public readonly ?int               $height      = null,
        public readonly ?float             $ratio       = null,
        public readonly ?\DateTimeImmutable $takenAt    = null,
        public readonly ?string            $etag        = null,
        public readonly ?int               $fileSize    = null,
        public readonly ?float             $qualityScore = null,
        public readonly ?bool              $isCollage   = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            path:         $d['path'],
            filename:     $d['filename']    ?? basename($d['path']),
            mime:         $d['mime']        ?? null,
            width:        $d['width']       ?? null,
            height:       $d['height']      ?? null,
            ratio:        $d['ratio']       ?? (isset($d['width'], $d['height']) && $d['height']
                              ? round($d['width'] / $d['height'], 4)
                              : null),
            takenAt:      $d['takenAt']     ?? null,
            etag:         $d['etag']        ?? null,
            fileSize:     $d['fileSize']    ?? null,
            qualityScore: $d['qualityScore'] ?? null,
            isCollage:    $d['isCollage']   ?? null,
        );
    }

    public function isPortrait(): bool  { return $this->ratio !== null && $this->ratio < 0.9; }
    public function isLandscape(): bool { return $this->ratio !== null && $this->ratio > 1.1; }
    public function isSquare(): bool    { return $this->ratio !== null && $this->ratio >= 0.9 && $this->ratio <= 1.1; }
}
