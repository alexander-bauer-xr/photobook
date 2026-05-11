// Backward-compatible alias to the consolidated photobook hook
// This ensures components importing { usePages } get the stateful version with overrides hydration.
export { usePhotobook as usePages } from './usePhotobook';
