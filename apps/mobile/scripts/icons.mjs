// Renders packages/ui/assets/logo-mark.svg into the 1024 px PNGs that
// @capacitor/assets turns into every Android and iOS icon and splash size.
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const svg = fileURLToPath(new URL('../../../packages/ui/assets/logo-mark.svg', import.meta.url));
const out = fileURLToPath(new URL('../assets/', import.meta.url));
mkdirSync(out, { recursive: true });

await sharp(svg, { density: 1536 }).resize(1024, 1024).png().toFile(`${out}icon-only.png`);
// Adaptive icon (Android): the house on its own, the blue as background.
await sharp(svg, { density: 1536 }).resize(1024, 1024).png().toFile(`${out}icon-foreground.png`);
await sharp({ create: { width: 1024, height: 1024, channels: 4, background: '#1D4ED8' } }).png().toFile(`${out}icon-background.png`);
await sharp({ create: { width: 2732, height: 2732, channels: 4, background: '#1D4ED8' } })
  .composite([{ input: await sharp(svg, { density: 1536 }).resize(640, 640).png().toBuffer(), gravity: 'center' }])
  .png()
  .toFile(`${out}splash.png`);
await sharp({ create: { width: 2732, height: 2732, channels: 4, background: '#0B1220' } })
  .composite([{ input: await sharp(svg, { density: 1536 }).resize(640, 640).png().toBuffer(), gravity: 'center' }])
  .png()
  .toFile(`${out}splash-dark.png`);
console.log('Icons rendered into apps/mobile/assets/');
