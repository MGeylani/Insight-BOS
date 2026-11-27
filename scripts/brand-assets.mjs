// Generates derived brand files from the master logos (run automatically before `npm run build`).
import { readFileSync, writeFileSync } from 'node:fs';

const logo = readFileSync('public/images/logo.svg', 'utf8');
writeFileSync('public/images/logo-white.svg', logo.replaceAll('#00027C', '#FFFFFF'));
writeFileSync('public/favicon.svg', readFileSync('public/images/logo-mark.svg', 'utf8'));
console.log('Brand assets generated.');
