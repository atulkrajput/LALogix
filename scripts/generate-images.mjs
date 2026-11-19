/**
 * Generates site images with sharp (run: `node scripts/generate-images.mjs`).
 *
 *  - public/images/og/*.jpg            1200×630 Open Graph images, one per page / product
 *  - public/images/products/*-N.webp   1200×750 screenshot PLACEHOLDERS
 *                                      (TODO: replace with real product screenshots, same file names)
 *  - public/images/logo.webp           small header logo
 *
 * Existing placeholder files are overwritten; real screenshots should keep the
 * same names, so do not re-run the script after adding them (or pass --og-only).
 */
import sharp from 'sharp';
import { mkdirSync } from 'node:fs';

const OG_ONLY = process.argv.includes('--og-only');
const FONT = "'Segoe UI', 'Helvetica Neue', Arial, sans-serif";
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// Keep in sync with config/lalogix.php (names and promises).
const products = {
  vivk: ['VIVK', 'AI chat', 'Your AI assistant: ask anything, get answers in seconds.'],
  amritix: ['AMRITIX', 'Dairy management', 'Your whole dairy hisab on your phone.'],
  'school-management': ['School Automation', 'School management', 'Run admissions, attendance and fees without registers.'],
  'inventory-pos': ['Inventory & POS', 'Billing and stock', 'Billing and stock that stay in sync.'],
  aisho: ['AISHO', 'aisho.live', 'A live product from LALogix Enterprises.'],
  ayurvedadhara: ['AyurvedaDhara', 'Ayurveda store · Coming soon', "Authentic Ayurvedic products, with a doctor's guidance."],
  doonportal: ['DoonPortal', 'Dehradun directory', "Dehradun's local directory: find or list a business free."],
};

const pages = {
  home: ['Ready-to-use software', 'for schools, dairies and shops', 'Live today, not someday.'],
  products: ['All LALogix products', 'AI chat · Dairy · School · Billing', 'Try any product free.'],
  pricing: ['Pricing', 'Rupee pricing with GST invoices', 'One team, one bill.'],
  industries: ['Software by industry', 'Schools · Dairies · Shops', 'Pick the product that fits.'],
  about: ['About LALogix', 'Built in Dehradun', '15+ years building software.'],
  insights: ['Insights', 'Guides for schools, dairies and shops', 'From the LALogix team.'],
  contact: ['Contact LALogix', 'WhatsApp · Phone · E-mail', 'Dehradun, Uttarakhand, India'],
  'book-demo': ['Book a free demo', 'See your business on LALogix', 'In 15 minutes.'],
  legal: ['Policies', 'Privacy · Terms · Refunds · Delivery', 'LALogix Enterprises'],
};

function wrap(text, max) {
  const words = text.split(' ');
  const lines = [];
  let line = '';
  for (const w of words) {
    if ((line + ' ' + w).trim().length > max) {
      lines.push(line.trim());
      line = w;
    } else line += ' ' + w;
  }
  if (line.trim()) lines.push(line.trim());
  return lines;
}

function ogSvg(title, kicker, line) {
  const titleLines = wrap(title, 22);
  const tspans = titleLines.map((l, i) => `<tspan x="80" dy="${i === 0 ? 0 : 84}">${esc(l)}</tspan>`).join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#111318"/><stop offset="1" stop-color="#2a2114"/></linearGradient>
  </defs>
  <rect width="1200" height="630" fill="url(#g)"/>
  <rect x="80" y="80" width="64" height="6" rx="3" fill="#C89B3C"/>
  <text x="80" y="150" font-family="${FONT}" font-size="30" fill="#E2B95E" font-weight="600">${esc(kicker)}</text>
  <text x="80" y="250" font-family="${FONT}" font-size="76" fill="#ffffff" font-weight="700">${tspans}</text>
  <text x="80" y="${250 + titleLines.length * 84 + 20}" font-family="${FONT}" font-size="34" fill="#d1d5db">${esc(line)}</text>
  <text x="80" y="592" font-family="${FONT}" font-size="30" fill="#ffffff" font-weight="700">LALogix</text>
  <text x="210" y="592" font-family="${FONT}" font-size="26" fill="#9CA3AF">lalogix.com · Built in Dehradun</text>
</svg>`;
}

function screenshotSvg(name, label, n) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="750">
  <rect width="1200" height="750" fill="#F7F5F0"/>
  <rect x="60" y="50" width="1080" height="650" rx="18" fill="#ffffff" stroke="#E5E7EB" stroke-width="2"/>
  <rect x="60" y="50" width="1080" height="52" rx="18" fill="#111318"/>
  <rect x="60" y="84" width="1080" height="18" fill="#111318"/>
  <circle cx="96" cy="76" r="8" fill="#ef4444"/><circle cx="122" cy="76" r="8" fill="#f59e0b"/><circle cx="148" cy="76" r="8" fill="#22c55e"/>
  <rect x="60" y="102" width="220" height="598" fill="#F3F4F6"/>
  ${[0, 1, 2, 3, 4].map((i) => `<rect x="84" y="${136 + i * 52}" width="${150 - i * 10}" height="16" rx="8" fill="#D1D5DB"/>`).join('')}
  ${[0, 1, 2].map((i) => `<rect x="${310 + i * 270}" y="136" width="246" height="120" rx="12" fill="#F1E8D2"/>`).join('')}
  <rect x="310" y="284" width="786" height="380" rx="12" fill="#F7F5F0"/>
  <text x="703" y="440" text-anchor="middle" font-family="${FONT}" font-size="54" font-weight="700" fill="#111318">${esc(name)}</text>
  <text x="703" y="500" text-anchor="middle" font-family="${FONT}" font-size="30" fill="#5F6673">${esc(label)} · screen ${n}</text>
  <text x="703" y="560" text-anchor="middle" font-family="${FONT}" font-size="24" fill="#9A6F20">Screenshot coming soon</text>
</svg>`;
}

mkdirSync('public/images/og', { recursive: true });
mkdirSync('public/images/products', { recursive: true });

for (const [key, [a, b, c]] of Object.entries(pages)) {
  await sharp(Buffer.from(ogSvg(a + ' ' + b, 'LALogix Enterprises', c))).jpeg({ quality: 82, mozjpeg: true }).toFile(`public/images/og/${key}.jpg`);
}
for (const [slug, [name, label, promise]] of Object.entries(products)) {
  await sharp(Buffer.from(ogSvg(name, label, promise.length > 58 ? promise.slice(0, 55) + '…' : promise))).jpeg({ quality: 82, mozjpeg: true }).toFile(`public/images/og/${slug}.jpg`);
  if (!OG_ONLY) {
    for (let n = 1; n <= 4; n++) {
      await sharp(Buffer.from(screenshotSvg(name, label, n))).webp({ quality: 78 }).toFile(`public/images/products/${slug}-${n}.webp`);
    }
  }
}

await sharp('public/logo.png').resize({ height: 132 }).webp({ quality: 90 }).toFile('public/images/logo.webp');
console.log('Images generated.');
