<?php
/**
 * Filename: index.php
 * Purpose: Render the Holidays workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="holidaysApp"
    data-api="<?= APP_BASE_URL ?>/modules/holidays/api.php"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">PARAMETER</div>
            <h2>Holidays</h2>
            <p>One calendar for the whole organization. Keep working-day targets consistent across daily logs and team monitoring.</p>
        </div><span class="badge badge-blue">Organization-wide</span>
    </section>
    <div class="ws-note">Working days are Monday to Friday, 8 hours per day, excluding the dates below. Holidays are entered explicitly each year. Changing a holiday recalculates required hours; existing logs and weekly approvals are retained.</div>
    <div
        class="ws-error"
        id="holidayError"
        role="alert"
        tabindex="-1"
        hidden
    ></div>

    <?php if (Permissions::IsAdmin()): ?>
    <section class="ws-card holiday-editor">
        <div class="ws-card-heading">
            <div>
                <h3 id="holidayEditorTitle">Add holiday</h3>
                <p>Dates apply to every section. A weekend holiday does not reduce weekday targets.</p>
            </div>
        </div>
        <form id="holidayForm"><input type="hidden" name="holiday_id"><input type="hidden" name="version">
            <div class="form-grid">
                <div class="form-row"><label for="holidayDate">Date</label><input
                        class="wf-date"
                        type="date"
                        id="holidayDate"
                        name="holiday_date"
                        min="1900-01-01"
                        max="2100-12-31"
                        required
                    ></div>
                <div class="form-row"><label for="holidayName">Holiday name</label><input
                        type="text"
                        id="holidayName"
                        name="holiday_name"
                        maxlength="200"
                        required
                    ></div>
            </div>
            <div class="ws-actions"><button
                    type="submit"
                    class="btn btn-primary"
                    id="holidaySave"
                >Save Holiday</button><button
                    type="button"
                    class="btn"
                    id="holidayReset"
                >Clear</button></div>
        </form>
    </section>

    <?php else: ?>
    <p class="wf-muted">Administrators maintain this calendar. Contact an administrator to add or correct a holiday.</p>

    <?php endif; ?>
    <section class="ws-card">
        <form id="holidayFilters" class="wf-filters">
            <div class="form-row"><label for="holidayYear">Year</label><input
                    type="number"
                    id="holidayYear"
                    min="1900"
                    max="2100"
                    value="<?= (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y') ?>"
                    required
                ></div><button
                type="submit"
                class="btn"
                id="holidayRefresh"
            >Refresh</button>
        </form>
        <div class="ws-card-heading">
            <div>
                <h3>Organization holidays</h3>
                <p>Required hours are zero on these dates.</p>
            </div><span
                id="holidayCount"
                role="status"
                aria-live="polite"
            ></span>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Holiday</th>
                        <th>Working-day impact</th>

                        <?php if (Permissions::IsAdmin()): ?>
                        <th>Actions</th>

                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="holidayRows">
                    <tr>
                        <td colspan="4">
                            <div class="ws-state">Loading holidays&hellip;</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
