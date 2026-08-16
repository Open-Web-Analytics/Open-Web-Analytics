const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

/**
 * Characterization of the OWA CLI INSTALLER (php cli.php cmd=install), the
 * headless bring-up path used for scripted/embedded installs.
 *
 * Unlike the web wizard, the CLI installer does NOT write owa-config.php -- it
 * REQUIRES the config file to already exist and aborts otherwise (installCli.php
 * isConfigFilePresent guard). It also sets the admin password = OWA_DB_PASSWORD.
 * So this spec has no browser interaction: it drives the harness to write a
 * scratch config (pointing at SCRATCH_DB_CLI), runs the CLI installer, and
 * asserts the resulting schema/admin/site/install_complete directly from SQL.
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

const INFO = php('info');

test.describe('install: CLI installer (cli.php cmd=install into a scratch DB)', () => {

    test('installs schema + admin + site + install_complete via cli.php', async () => {
        // 1. Swap the web-wizard config for a CLI scratch config (points at
        //    SCRATCH_DB_CLI). The live config stays in the backup.
        const prepared = php('prepare-cli');
        expect(prepared.db_name).toBe(INFO.scratch_db_cli);

        // 2. Run the real CLI installer. cli.php parses key=value args and maps
        //    cmd=install -> base.installCli. The admin password becomes the
        //    config's OWA_DB_PASSWORD (so we assert the USER exists, not a pass).
        const repoRoot = path.join(__dirname, '..', '..');
        const out = execFileSync(
            'php',
            [
                'cli.php',
                'cmd=install',
                `user_id=${INFO.installcli_admin_id}`,
                `email_address=${INFO.installcli_admin_id}`,
                `domain=${INFO.installcli_domain}`,
            ],
            { cwd: repoRoot, encoding: 'utf8' }
        );
        // The installer logs "Install Completed." on success.
        expect(out).toMatch(/Install Completed|Installation complete/i);

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
        expect(result.checks.request_partitioned).toBe(true);
        expect(result.checks.request_has_lead).toBe(true);
    });
});
