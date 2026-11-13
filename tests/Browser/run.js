import { execSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { getRegisteredTests } from './support/test.js';
import { closeBrowser, closeAllSessions } from './support/session.js';

const dir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(dir, '../..');

console.log('Seeding browser test users...');
execSync('/usr/bin/php8.4 artisan test:seed-browser-users', { cwd: projectRoot, stdio: 'inherit' });

const only = process.argv[2];
const files = readdirSync(dir)
    .filter((f) => f.endsWith('.test.js'))
    .filter((f) => !only || f.includes(only))
    .sort();

console.log(`\nLoading ${files.length} test file(s): ${files.join(', ')}\n`);

for (const file of files) {
    await import(`./${file}`);
}

const tests = getRegisteredTests();
let passed = 0;
let failed = 0;
const failures = [];

for (const { name, fn } of tests) {
    const start = Date.now();
    try {
        await fn();
        const ms = Date.now() - start;
        console.log(`  \x1b[32m✓\x1b[0m ${name} (${ms}ms)`);
        passed++;
    } catch (error) {
        const ms = Date.now() - start;
        console.log(`  \x1b[31m✗\x1b[0m ${name} (${ms}ms)`);
        console.log(`    ${error.message}`);
        failures.push({ name, error });
        failed++;
    } finally {
        // A thrown assertion mid-test must not leak its browser
        // contexts into the next test.
        await closeAllSessions();
    }
}

await closeBrowser();

console.log(`\n${passed} passed, ${failed} failed, ${tests.length} total\n`);

if (failed > 0) {
    console.log('Failures:');
    failures.forEach(({ name, error }) => console.log(`  - ${name}: ${error.message}`));
    process.exit(1);
}
