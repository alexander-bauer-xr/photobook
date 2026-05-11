<?php

namespace App\DTO;

final class RenderSpec
{
    public function __construct(
        public readonly string $photoPath,
        public readonly string $slotId,
        public readonly string $fit,       // 'cover' | 'contain'
        public readonly float  $alignX,    // -1.0 .. 1.0
        public readonly float  $alignY,    // -1.0 .. 1.0
        public readonly float  $zoom,      // 1.0 = no zoom
        public readonly float  $rotation,  // degrees
        public readonly bool   $auto,      // false = user override
    ) {}

    public function toArray(): array
    {
        return [
            'photoPath' => $this->photoPath,
            'slotId'    => $this->slotId,
            'fit'       => $this->fit,
            'alignX'    => $this->alignX,
            'alignY'    => $this->alignY,
            'zoom'      => $this->zoom,
            'rotation'  => $this->rotation,
            'auto'      => $this->auto,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            photoPath: (string) ($d['photoPath'] ?? ''),
            slotId:    (string) ($d['slotId']    ?? ''),
            fit:       (string) ($d['fit']       ?? 'cover'),
            alignX:    (float)  ($d['alignX']    ?? 0.0),
            alignY:    (float)  ($d['alignY']    ?? 0.0),
            zoom:      (float)  ($d['zoom']      ?? 1.0),
            rotation:  (float)  ($d['rotation']  ?? 0.0),
            auto:      (bool)   ($d['auto']      ?? true),
        );
    }

    /**
     * Build from a suggested_crop feature value:
     * { "align": { "x": -0.12, "y": -0.08 }, "zoom": 1.08 }
     */
    public static function fromSuggestedCrop(string $photoPath, string $slotId, array $crop): self
    {
        return new self(
            photoPath: $photoPath,
            slotId:    $slotId,
            fit:       'cover',
            alignX:    (float) ($crop['align']['x'] ?? 0.0),
            alignY:    (float) ($crop['align']['y'] ?? 0.0),
            zoom:      (float) ($crop['zoom']       ?? 1.0),
            rotation:  0.0,
            auto:      true,
        );
    }

    /** Neutral placement — centered, no zoom, no rotation. */
    public static function neutral(string $photoPath, string $slotId): self
    {
        return new self(
            photoPath: $photoPath,
            slotId:    $slotId,
            fit:       'cover',
            alignX:    0.0,
            alignY:    0.0,
            zoom:      1.0,
            rotation:  0.0,
            auto:      true,
        );
    }
}
