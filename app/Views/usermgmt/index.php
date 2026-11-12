<?php
/**
 * Filename: index.php
 * Purpose: Render the Usermgmt workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="usersApp"
    data-api="<?= APP_BASE_URL ?>/modules/usermgmt/api.php"
    data-org-api="<?= APP_BASE_URL ?>/modules/organization/api.php"
    data-current-user="<?= (int)$currentUser['user_id'] ?>"
    data-base="<?= APP_BASE_URL ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">ADMINISTRATION / USERS</div>
            <h2>Manage people and access.</h2>
            <p>Find an account, keep organization assignments current, and help people get back into their workspace.</p>
        </div>
        <div class="ws-actions"><a
                class="btn"
                href="<?= H(RouteUrls::Url(APP_BASE_URL . '/auth_email_preview.php')) ?>"
                target="_blank"
                rel="noopener noreferrer"
            >Email details <?= UiIcon('arrow-up-right') ?></a><button
                class="btn btn-primary"
                type="button"
                id="userAdd"
            ><?= UiIcon('plus') ?> New user</button></div>
    </section>
    <section class="ws-summary" aria-label="User totals">

        <?php foreach (['all' => ['All accounts','users'],'active' => ['Enabled & unlocked','check'],'locked' => ['Locked accounts','shield'],'password' => ['Password change required','clock']] as $key => [$label,$icon]): ?>
        <button
            class="ws-stat"
            type="button"
            data-status="<?= $key ?>"
            aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"
        ><span class="ws-stat-icon" data-icon="<?= $icon ?>"></span><span><strong id="userCount_<?= $key ?>">—</strong><small><?= $label ?></small></span></button>

        <?php endforeach; ?>
    </section>
    <section class="ws-card" aria-labelledby="usersHeading">
        <div class="ws-card-heading">
            <div>
                <h3 id="usersHeading">User accounts</h3>
                <p id="userListStatus" role="status">Loading accounts…</p>
            </div><button
                class="btn btn-sm"
                type="button"
                id="usersRefresh"
            >Refresh</button>
        </div>
        <div class="ws-toolbar">
            <div class="form-row ws-search"><label for="userSearch">Search people</label>
                <div class="ws-search-wrap"><span data-icon="search"></span><input
                        type="search"
                        id="userSearch"
                        placeholder="Name, employee ID or email…"
                        maxlength="200"
                        autocomplete="off"
                    ></div>
            </div>
            <div class="form-row"><label for="userStatus">Account status</label><select id="userStatus">
                    <option value="all">All accounts</option>
                    <option value="active">Enabled &amp; unlocked</option>
                    <option value="disabled">Disabled</option>
                    <option value="locked">Locked</option>
                    <option value="password">Password change required</option>
                </select></div>
            <div class="form-row"><label for="userDepartment">Department</label><select id="userDepartment">
                    <option value="">All departments</option>
                </select></div>
            <div class="form-row users-sort"><label for="userSort">Sort by</label><select id="userSort">
                    <option value="name">Name</option>
                    <option value="id">Employee ID</option>
                    <option value="department">Department</option>
                </select></div>
        </div>
        <div
            id="usersLoadError"
            class="ws-state"
            hidden
        ><strong>We couldn’t load the accounts.</strong>
            <p>Check your connection and use Refresh to try again.</p>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table users-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Organization</th>
                        <th>Email</th>
                        <th>Account status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userRows"></tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="userPageSummary" role="status">Loading…</span>
            <div class="ws-actions"><button
                    type="button"
                    class="btn btn-sm"
                    id="userPrevious"
                    disabled
                ><?= UiIcon('arrow-left') ?> Previous</button><button
                    type="button"
                    class="btn btn-sm"
                    id="userNext"
                    disabled
                >Next <?= UiIcon('arrow-right') ?></button></div>
        </div>
    </section>
    <div class="ws-note"><strong>Organization changes belong in one place.</strong> Manage groups, departments, units and positions in <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/organization/index.php')) ?>">Organization</a>. New accounts require a password change at first sign-in.</div>
</div>
<dialog
    id="userEditor"
    class="ws-dialog wide"
    aria-labelledby="userEditorTitle"
>
    <form id="userForm">
        <div class="ws-dialog-header">
            <div>
                <h3 id="userEditorTitle">New user</h3>
                <p id="userEditorSubtitle">Create an account and choose where this person belongs.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="userEditor"
                aria-label="Close user editor"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="userFormError"
                class="ws-error"
                role="alert"
                tabindex="-1"
                hidden
            ></div><button
                type="button"
                class="btn btn-sm users-editor-retry"
                id="userEditorRetry"
                hidden
            >Retry loading account</button>
            <fieldset id="userFields" class="users-fieldset">
                <fieldset class="ws-form-section">
                    <legend>01 / IDENTITY</legend>
                    <div class="form-grid">
                        <div class="form-row"><label for="us_employee_id">Employee ID *</label><input
                                type="text"
                                id="us_employee_id"
                                required
                                maxlength="50"
                                autocomplete="off"
                            ></div>
                        <div class="form-row" id="us_password_row"><label for="us_password">Temporary password <span class="muted">(optional)</span></label>
                            <div class="ws-password"><input
                                    type="password"
                                    id="us_password"
                                    minlength="8"
                                    autocomplete="new-password"
                                    placeholder="Use the default password"
                                ><button
                                    type="button"
                                    data-reveal="us_password"
                                    aria-pressed="false"
                                    aria-label="Show or hide temporary password"
                                >Show</button></div>
                            <div class="hint">Leave blank to use the default in Parameters.</div>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-row"><label for="us_first_name">First name *</label><input
                                type="text"
                                id="us_first_name"
                                required
                                maxlength="100"
                                autocomplete="given-name"
                            ></div>
                        <div class="form-row"><label for="us_last_name">Last name *</label><input
                                type="text"
                                id="us_last_name"
                                required
                                maxlength="100"
                                autocomplete="family-name"
                            ></div>
                    </div>
                    <div class="form-row"><label for="us_middle_name">Middle name <span class="muted">(optional)</span></label><input
                            type="text"
                            id="us_middle_name"
                            maxlength="100"
                            autocomplete="additional-name"
                        ></div>
                    <div class="form-row"><label for="us_birthday">Birthday <span class="muted">(optional)</span></label><input
                            type="date"
                            id="us_birthday"
                            min="1900-01-01"
                            max="<?= H((new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) ?>"
                            autocomplete="bday"
                        >
                        <div class="hint">Shown on the department calendar each year without the birth year or age.</div>
                    </div>
                </fieldset>
                <fieldset class="ws-form-section">
                    <legend>02 / ORGANIZATION PLACEMENT</legend>
                    <p class="users-placement-hint">Choose each level in order. You can leave the placement unassigned.</p>
                    <div class="form-grid">

                        <?php foreach (['group' => 'Group','division' => 'Division','department' => 'Department','unit' => 'Unit','position' => 'Position'] as $key => $label): ?>
                        <div class="form-row"><label for="us_<?= $key ?>_id"><?= $label ?></label><select id="us_<?= $key ?>_id">
                                <option value="">Not assigned</option>
                            </select></div>

                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <fieldset class="ws-form-section">
                    <legend>03 / CONTACT &amp; ACCESS</legend>
                    <div class="form-row"><label for="us_email">Email address</label><input
                            type="email"
                            id="us_email"
                            maxlength="200"
                            autocomplete="email"
                        >
                        <div class="hint" id="us_email_hint">Used for welcome emails, password recovery and email-code sign-in.</div>
                    </div>
                    <div class="form-row"><label for="us_role_key">Access role</label><select id="us_role_key" <?= Permissions::IsAdmin() ? '' : 'disabled' ?>>

                            <?php foreach (Database::GetConnection('app')->query('SELECT role_key,role_name FROM app_roles ORDER BY LOWER(role_name),role_key')->getResultArray() as $role): ?>
                            <option value="<?= H($role['role_key']) ?>" <?= $role['role_key'] === 'analyst' ? 'selected' : '' ?>><?= H($role['role_name']) ?></option>

                            <?php endforeach; ?>
                        </select>
                        <p class="hint">Role permissions apply on the next request. Only Administrators can change roles.

                            <?php if (Permissions::IsAdmin()): ?>
                            <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/permissions/index.php')) ?>">Create or manage roles</a>

                            <?php endif; ?>
                        </p>
                    </div><label class="users-enabled-control" for="us_is_active"><input
                            type="checkbox"
                            id="us_is_active"
                            checked
                        ><span><strong>Account enabled</strong><small>Disabled accounts cannot sign in.</small></span></label>
                </fieldset>
            </fieldset>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-dialog-close="userEditor"
            >Cancel</button><button
                class="btn btn-primary"
                id="saveUserBtn"
                type="submit"
            >Create user</button></div>
    </form>
</dialog>
<dialog
    id="userActions"
    class="ws-dialog"
    aria-labelledby="userActionsTitle"
>
    <div class="ws-dialog-header">
        <div>
            <h3 id="userActionsTitle">Account actions</h3>
            <p id="userActionsSubtitle"></p>
        </div><button
            type="button"
            class="ws-dialog-close"
            data-dialog-close="userActions"
            aria-label="Close account actions"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            id="userActionError"
            class="ws-error"
            role="alert"
            tabindex="-1"
            hidden
        ></div>
        <div id="userActionButtons" class="users-account-actions"></div>
        <p
            class="users-placement-hint"
            id="userSelfHint"
            hidden
        >You cannot disable or delete the account you’re currently using.</p>
    </div>
</dialog>
<dialog
    id="resetPasswordModal"
    class="ws-dialog"
    aria-labelledby="resetPasswordTitle"
>
    <form id="resetPasswordForm">
        <div class="ws-dialog-header">
            <div>
                <h3 id="resetPasswordTitle">Reset password</h3>
                <p id="resetPasswordSubtitle"></p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="resetPasswordModal"
                aria-label="Close password reset"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="userResetError"
                class="ws-error"
                role="alert"
                tabindex="-1"
                hidden
            ></div>
            <div class="ws-note users-reset-note">This replaces the current password and expires existing sign-in sessions. The user must choose a new password at their next sign-in.</div>
            <div class="form-row"><label for="rp_password">Temporary password *</label>
                <div class="ws-password"><input
                        type="password"
                        id="rp_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    ><button
                        type="button"
                        data-reveal="rp_password"
                        aria-pressed="false"
                        aria-label="Show or hide temporary password"
                    >Show</button></div>
                <div class="hint">At least 8 characters; no more than 72 bytes.</div>
            </div>
            <div class="form-row"><label for="rp_confirmation">Confirm temporary password *</label><input
                    type="password"
                    id="rp_confirmation"
                    required
                    minlength="8"
                    autocomplete="new-password"
                ></div>
        </div>
        <div class="ws-dialog-footer"><button
                class="btn"
                type="button"
                data-dialog-close="resetPasswordModal"
            >Cancel</button><button
                class="btn btn-primary"
                id="userResetSave"
                type="submit"
            >Reset password</button></div>
    </form>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
