const { test, expect } = require('@playwright/test');
const { execFileSync, spawnSync } = require('child_process');
const path = require('path');

/**
 * Characterization of the OWA CLI INSTALLER (php cli.php cmd=core-install), the
 * headless bring-up path used for scripted/embedded installs.
 *
 * Unlike the web wizard, the CLI installer does NOT write owa-config.php -- it
 * REQUIRES the config file to already exist and aborts otherwise. The admin's
 * password is generated and printed once, or read from standard input with
 * --password-stdin; password= on the command line is refused. So this spec has
 * no browser interaction: it drives the harness to write a scratch config
 * (pointing at SCRATCH_DB_CLI), runs the CLI installer, and asserts the
 * resulting schema/admin/site/install_complete directly from SQL. A second run
 * on the installed system checks and repairs, and changes nothing.
 *
 * ISOLATION: runs after install-web.spec.js under playwright.install.config.js.
 * The harness `prepare-cli` step first removes the web-wizard-written config and
 * writes the CLI scratch config; the live config remains safely stashed the whole
 * time and is restored (with both scratch DBs dropped) by globalTeardown.
 *
 * There is no @playwright browser use here, but it stays a .spec.js so the
 * install config runs it in sequence after the web wizard.
 */

const HARNESS = path.join(__dirname, 'install_harness.php');

function php(cmd) {
    return JSON.parse(execFileSync('php', [HARNESS, cmd], { encoding: 'utf8' }));
}

/** The harness's answer when it exits non-zero: assert-cli writes it to stderr before an install. */
function phpAnswer(cmd) {
    try {
        return php(cmd);
    } catch (e) {
        return JSON.parse(e.stderr);
    }
}

const INFO = php('info');

test.describe('install: CLI installer (cli.php cmd=core-install into a scratch DB)', () => {

    test('installs schema + admin + site + install_complete via cli.php', async () => {
        // 1. Swap the web-wizard config for a CLI scratch config (points at
        //    SCRATCH_DB_CLI). The live config stays in the backup.
        const prepared = php('prepare-cli');
        expect(prepared.db_name).toBe(INFO.scratch_db_cli);

        // 2. Run the real CLI installer. cli.php parses key=value args and maps
        //    cmd=core-install -> base.coreInstallCli.
        const repoRoot = path.join(__dirname, '..', '..');
        // Its exit code and what it printed. cli.php exits 0 when the command
        // did what it was asked, 1 when it failed, 2 when the arguments are wrong.
        const run = (args, input) => {
            const r = spawnSync('php', ['cli.php', 'cmd=core-install', ...args],
                { cwd: repoRoot, encoding: 'utf8', input: input ?? '' });
            return { code: r.status, out: `${r.stdout}${r.stderr}` };
        };
        const setup = [
            `user_id=${INFO.installcli_admin_id}`,
            `email_address=${INFO.installcli_admin_id}`,
            `domain=${INFO.installcli_domain}`,
        ];

        // A password on the command line is refused, and nothing is installed.
        const argued = run([...setup, 'password=owa-cli-admin-pass']);
        expect(argued.code).toBe(2);
        expect(argued.out).toMatch(/password= is not taken/);
        expect(argued.out).not.toMatch(/Install Completed/i);
        expect(phpAnswer('assert-cli').checks.install_complete).toBe(false);

        // A piped password too short is refused the same way.
        const short = run([...setup, '--password-stdin'], 'short\n');
        expect(short.code).toBe(2);
        expect(short.out).toMatch(/--password-stdin: The password must be at least 6 characters/);

        // A release named before the install is refused.
        const early = run(['--version=2.0.1']);
        expect(early.code).toBe(1);
        expect(early.out).toMatch(/OWA is not installed yet/);

        const installed = run([...setup, '--password-stdin'], 'owa-cli-admin-pass\n');
        const out = installed.out;
        expect(installed.code, out).toBe(0);
        // The installer logs "Install Completed." on success.
        expect(out).toMatch(/Install Completed|Installation complete/i);
        // ...and the cron line, without which no report shows anything.
        expect(out).toMatch(/\* \* \* \* \* cd .+ && php cli\.php cmd=schedule-run/);
        // A piped password is not printed.
        expect(out).not.toMatch(/Admin password for/);
        expect(out).not.toContain('owa-cli-admin-pass');

        // 3. Assert the scratch DB really got a working install.
        const result = php('assert-cli');
        expect(result.status).toBe('installed');
        expect(result.checks.install_complete).toBe(true);
        expect(result.checks.admin_user).toBe(true);
        expect(result.checks.has_site).toBe(true);
        // A fresh install must be partitioned from its first row, with a lead
        // of future periods. Nothing else here would notice its absence: the
        // install succeeds either way, and the loss only shows up much later,
        // when there is no cheap way to drop old data.
        expect(result.checks.raw_partitioned).toBe(true);
        expect(result.checks.raw_has_lead).toBe(true);

        // 4. Run again on the installed system: check and repair, nothing else.
        const again = run(setup);
        expect(again.code, again.out).toBe(0);
        expect(again.out).toMatch(/Schema: already installed, tables checked/);
        expect(again.out).toContain(`Admin "${INFO.installcli_admin_id}": already exists, not changed`);
        expect(again.out).toMatch(/Site: already exists, not changed/);
        expect(again.out).toMatch(/so these arguments are ignored: user_id, email_address, domain/);
        expect(again.out).not.toMatch(/Admin password for/);

        // cmd=install is gone, and says what it is now.
        const renamed = spawnSync('php', ['cli.php', 'cmd=install'], { cwd: repoRoot, encoding: 'utf8' });
        expect(renamed.status).toBe(2);
        expect(renamed.stderr).toMatch(/cmd=install is now cmd=core-install\./);
    });
});
