/*
 | T-72 — photographs the screens the training guides are built from, and
 | prints the finished guides to PDF.
 |
 | Two modes:
 |   node training/capture.mjs shots <manifest.json> <out-dir>
 |   node training/capture.mjs pdf   <file.html> <out.pdf>
 |
 | It signs in once per role and visits that role's screens in order, so every
 | picture is the screen that role really gets. An admin screenshot in a
 | delegate's guide would teach the delegate something they do not have.
 |
 | Playwright is not a dependency of this project; it is a tool run beside it.
 | Point NODE_PATH at an installation that has it.
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

// require, not import: CommonJS resolution consults NODE_PATH, so Playwright
// can live beside the project rather than in it.
const { chromium } = createRequire(import.meta.url)('playwright');

const BASE = process.env.TRAINING_BASE_URL || 'http://localhost:8000';
const PASSWORD = process.env.SEED_DEMO_PASSWORD || 'password';
const TOTP_SECRET = process.env.SEED_DEMO_TOTP || '';

/*
 | The browser. Playwright's own default asks for a headless shell it may not
 | have been given; a Chromium already on the machine is named explicitly
 | through CHROMIUM_PATH so the tour runs without downloading anything.
 */
const LAUNCH = process.env.CHROMIUM_PATH
  ? { executablePath: process.env.CHROMIUM_PATH }
  : {};

/** RFC 6238, the six digits for now. Same algorithm the panel verifies with. */
function totp(base32Secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';

  for (const char of base32Secret.replace(/=+$/, '').toUpperCase()) {
    const index = alphabet.indexOf(char);

    if (index < 0) continue;

    bits += index.toString(2).padStart(5, '0');
  }

  const bytes = Buffer.from(
    (bits.match(/.{8}/g) || []).map((byte) => parseInt(byte, 2))
  );

  const counter = Buffer.alloc(8);
  counter.writeUInt32BE(Math.floor(Date.now() / 1000 / 30), 4);

  const digest = crypto.createHmac('sha1', bytes).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const code = digest.readUInt32BE(offset) & 0x7fffffff;

  return String(code % 1_000_000).padStart(6, '0');
}

async function signIn(page, panel, email, twoFactor) {
  await page.goto(`${BASE}/${panel}/login`, { waitUntil: 'networkidle' });

  // Filament binds these through Livewire, so the value has to be typed
  // rather than assigned, or the component never hears about it.
  await page.locator('#data\\.email').fill(email);
  await page.locator('#data\\.password').fill(PASSWORD);
  await page.getByRole('button', { name: /تسجيل الدخول|sign in/i }).click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(600);

  if (!twoFactor) return true;

  if (!TOTP_SECRET) {
    console.log(`  skipped: ${email} needs a second factor and SEED_DEMO_TOTP is not set`);

    return false;
  }

  const code = page.locator('input[inputmode="numeric"], #data\\.code').first();

  if (await code.count()) {
    await code.fill(totp(TOTP_SECRET));
    await page.getByRole('button', { name: /تأكيد|متابعة|confirm|verify/i }).first().click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
  }

  return true;
}

async function shots(manifestPath, outDir) {
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const browser = await chromium.launch(LAUNCH);
  const taken = [];
  const missed = [];

  for (const role of manifest) {
    console.log(`${role.key} (${role.steps.length} screens)`);

    const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
      deviceScaleFactor: 2,
      locale: 'ar',
    });
    const page = await context.newPage();

    let signedIn = true;

    if (role.account) {
      signedIn = await signIn(page, role.panel || 'admin', role.account, role.two_factor);
    }

    if (signedIn) {
      for (const step of role.steps) {
        try {
          const response = await page.goto(BASE + step.path, { waitUntil: 'networkidle', timeout: 30000 });
          await page.waitForTimeout(900);

          if (response && response.status() >= 400) {
            missed.push(`${step.key} → HTTP ${response.status()} at ${step.path}`);

            continue;
          }

          const file = path.join(outDir, `${step.key}.png`);
          await page.screenshot({ path: file, fullPage: Boolean(step.full) });
          taken.push(step.key);
        } catch (error) {
          missed.push(`${step.key} → ${error.message.split('\n')[0]}`);
        }
      }
    } else {
      role.steps.forEach((step) => missed.push(`${step.key} → not signed in`));
    }

    await context.close();
  }

  await browser.close();

  fs.writeFileSync(
    path.join(outDir, 'capture-result.json'),
    JSON.stringify({ taken, missed }, null, 1)
  );

  console.log(`${taken.length} taken, ${missed.length} missed`);
  missed.forEach((miss) => console.log(`  MISSED ${miss}`));
}

async function pdf(htmlPath, outPath) {
  const browser = await chromium.launch(LAUNCH);
  const page = await browser.newPage();

  await page.goto('file://' + path.resolve(htmlPath), { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await page.pdf({
    path: outPath,
    format: 'A4',
    printBackground: true,
    margin: { top: '14mm', bottom: '14mm', left: '12mm', right: '12mm' },
  });

  await browser.close();
}

const [mode, first, second] = process.argv.slice(2);

if (mode === 'shots') {
  await shots(first, second);
} else if (mode === 'pdf') {
  await pdf(first, second);
} else {
  console.error('usage: capture.mjs shots <manifest.json> <out-dir> | pdf <in.html> <out.pdf>');
  process.exit(1);
}
