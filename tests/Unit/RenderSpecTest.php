<?php

namespace Tests\Unit;

use App\DTO\RenderSpec;
use PHPUnit\Framework\TestCase;

class RenderSpecTest extends TestCase
{
    public function test_neutral_factory(): void
    {
        $spec = RenderSpec::neutral('photos/a.jpg', 'slot-1');

        $this->assertSame('photos/a.jpg', $spec->photoPath);
        $this->assertSame('slot-1', $spec->slotId);
        $this->assertSame('cover', $spec->fit);
        $this->assertSame(0.0, $spec->alignX);
        $this->assertSame(0.0, $spec->alignY);
        $this->assertSame(1.0, $spec->zoom);
        $this->assertSame(0.0, $spec->rotation);
        $this->assertTrue($spec->auto);
    }

    public function test_from_suggested_crop(): void
    {
        $spec = RenderSpec::fromSuggestedCrop('photos/b.jpg', 'slot-2', [
            'align' => ['x' => -0.12, 'y' => 0.08],
            'zoom'  => 1.15,
        ]);

        $this->assertSame('cover', $spec->fit);
        $this->assertEqualsWithDelta(-0.12, $spec->alignX, 1e-9);
        $this->assertEqualsWithDelta(0.08, $spec->alignY, 1e-9);
        $this->assertEqualsWithDelta(1.15, $spec->zoom, 1e-9);
        $this->assertSame(0.0, $spec->rotation);
        $this->assertTrue($spec->auto);
    }

    public function test_from_array_roundtrip(): void
    {
        $original = new RenderSpec(
            photoPath: 'x.jpg',
            slotId:    's1',
            fit:       'contain',
            alignX:    0.5,
            alignY:    -0.5,
            zoom:      1.2,
            rotation:  90.0,
            auto:      false,
        );

        $restored = RenderSpec::fromArray($original->toArray());

        $this->assertSame($original->photoPath, $restored->photoPath);
        $this->assertSame($original->fit, $restored->fit);
        $this->assertEqualsWithDelta($original->alignX, $restored->alignX, 1e-9);
        $this->assertEqualsWithDelta($original->alignY, $restored->alignY, 1e-9);
        $this->assertEqualsWithDelta($original->zoom, $restored->zoom, 1e-9);
        $this->assertEqualsWithDelta($original->rotation, $restored->rotation, 1e-9);
        $this->assertFalse($restored->auto);
    }

    public function test_from_array_defaults(): void
    {
        $spec = RenderSpec::fromArray([]);

        $this->assertSame('', $spec->photoPath);
        $this->assertSame('cover', $spec->fit);
        $this->assertSame(0.0, $spec->alignX);
        $this->assertSame(1.0, $spec->zoom);
        $this->assertTrue($spec->auto);
    }

    public function test_missing_crop_align_falls_back_to_zero(): void
    {
        $spec = RenderSpec::fromSuggestedCrop('p.jpg', 's', ['zoom' => 1.05]);

        $this->assertSame(0.0, $spec->alignX);
        $this->assertSame(0.0, $spec->alignY);
        $this->assertEqualsWithDelta(1.05, $spec->zoom, 1e-9);
    }
}
