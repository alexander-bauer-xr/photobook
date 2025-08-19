import React from 'react';

export default function ZoomSlider({ value, onChange, min = 0.05, max = 6 }: { value: number; onChange: (v: number) => void; min?: number; max?: number }) {
  return (
    <input
      type="range"
      min={min}
      max={max}
      step={0.01}
      value={value}
      onChange={e => onChange(Number(e.target.value))}
      className="w-28 mx-2"
      aria-label="Zoom"
      aria-valuemin={min}
      aria-valuemax={max}
      aria-valuenow={value}
      title="Zoom"
    />
  );
}
