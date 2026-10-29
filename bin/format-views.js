/**
 * Filename: format-views.js
 * Purpose: Align first-party PHP view tags without modifying installed dependencies.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Add repeatable view formatting with PHP token verification.
 */
const FS = require('node:fs');
const PATH = require('node:path');
const CHILD_PROCESS = require('node:child_process');
const BEAUTIFY = require('js-beautify').html;
const ROOT = PATH.resolve(__dirname, '..');
const IS_CHECK = process.argv.includes('--check');
const OPTIONS =
{
    indent_size: 4,
    indent_char: ' ',
    indent_inner_html: true,
    preserve_newlines: true,
    max_preserve_newlines: 1,
    wrap_line_length: 0,
    wrap_attributes: 'force-expand-multiline',
    wrap_attributes_min_attrs: 3,
    end_with_newline: true,
    templating: ['php'],
    content_unformatted: ['pre', 'textarea', 'script', 'style'],
    extra_liners: []
};
function Tokens(source)
{
    return JSON.parse(CHILD_PROCESS.execFileSync(process.env.DEVATLAS_PHP || 'php',
        [PATH.join(__dirname, 'view-tokens.php')],
        {
            input: source,
            encoding: 'utf8',
            windowsHide: true,
            maxBuffer: 8 * 1024 * 1024
        }));
}
function Files(directory)
{
    return FS.readdirSync(directory,
    {
        withFileTypes: true
    }).flatMap(entry =>
    {
        const FILE = PATH.join(directory, entry.name);
        return entry.isDirectory() ? Files(FILE) : entry.name.endsWith('.php') ? [FILE] : [];
    });
}
let changed = 0;
const VIEWS = Files(PATH.join(ROOT, 'app', 'Views'));
for (const FILE of VIEWS)
{
    const SOURCE = FS.readFileSync(FILE, 'utf8');
    const BEFORE = Tokens(SOURCE);
    const FIRST = BEAUTIFY(BEFORE.source, OPTIONS).trimStart();
    const FORMATTED = BEAUTIFY(Tokens(FIRST).source, OPTIONS).trimStart();
    CHILD_PROCESS.execFileSync(process.env.DEVATLAS_PHP || 'php', ['-l'],
    {
        input: FORMATTED,
        windowsHide: true,
        stdio: ['pipe', 'pipe', 'pipe']
    });
    if (Tokens(FORMATTED).fingerprint !== BEFORE.fingerprint)
    {
        throw new Error('View formatting changed executable PHP tokens: ' + FILE);
    }
    if (FORMATTED !== SOURCE)
    {
        changed++;
        if (IS_CHECK)
        {
            process.stderr.write(PATH.relative(ROOT, FILE) + '\n');
        }
        else
        {
            FS.writeFileSync(FILE, FORMATTED);
        }
    }
}
process.stdout.write(VIEWS.length + ' first-party views checked; ' + changed + (IS_CHECK ? ' require formatting.\n' : ' formatted.\n'));
if (IS_CHECK && changed)
{
    process.exitCode = 1;
}
