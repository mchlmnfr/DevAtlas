<?php
/**
 * Filename: user_menu.php
 * Purpose: Provide user menu processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */

if ($currentUser):
    $accountName = $currentUser['full_name'] ?: $currentUser['employee_id'];
    $accountParts = preg_split('/\s+/u', trim($accountName), -1, PREG_SPLIT_NO_EMPTY) ?: [$accountName];
    $accountInitials = mb_strtoupper(mb_substr($accountParts[0], 0, 1) . (count($accountParts) > 1 ? mb_substr($accountParts[count($accountParts) - 1], 0, 1) : ''));
    $accountIcon = static function (string $name): string
    {
        $icons = ['chevron' => 'chevron-down','close' => 'x','person' => 'user-round','camera' => 'camera','key' => 'key-round','sun' => 'sun','moon' => 'moon','monitor' => 'monitor','exit' => 'log-out','copy' => 'copy','clock' => 'clock-3'];
        return UiIcon($icons[$name] ?? 'user-round', 'account-icon');
    };
    $accountSignedIn = (int)($_SESSION['logged_in_at'] ?? 0);
    ?>
<div class="user-menu" id="userMenu">
    <button
        class="user-menu-trigger"
        id="userMenuTrigger"
        type="button"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="userMenuDropdown"
        aria-label="<?= H('Account options for '.$accountName) ?>"
        onclick="AT.toggleUserMenu()"
    >
        <span class="avatar avatar-sm" id="sidebarAvatar">

            <?php if ($currentUser['profile_picture_path']): ?>
            <img src="<?= H(APP_BASE_URL.'/'.$currentUser['profile_picture_path']) ?>" alt="">

            <?php else: ?>
            <?= H($accountInitials) ?>

            <?php endif; ?>
        </span>
        <span class="sidebar-user-summary"><strong><?= H($accountName) ?></strong><small><?= H($currentUser['position_title'] ?: $currentUser['employee_id']) ?></small></span>
        <span class="sidebar-user-chevron" aria-hidden="true"><?= $accountIcon('chevron') ?></span>
    </button>
    <section
        class="user-menu-dropdown"
        id="userMenuDropdown"
        role="dialog"
        aria-labelledby="userMenuTitle"
        hidden
    >
        <header class="account-menu-header">
            <div class="account-menu-topline">
                <h2 id="userMenuTitle">Your account</h2><button
                    type="button"
                    class="account-close"
                    onclick="AT.closeUserMenu(true)"
                    aria-label="Close account options"
                ><?= $accountIcon('close') ?></button>
            </div>
            <div class="account-identity"><span class="avatar avatar-lg" id="dropdownAvatar">

                    <?php if ($currentUser['profile_picture_path']): ?>
                    <img src="<?= H(APP_BASE_URL.'/'.$currentUser['profile_picture_path']) ?>" alt="">

                    <?php else: ?>
                    <?= H($accountInitials) ?>

                    <?php endif; ?>
                </span>
                <div><strong class="account-name"><?= H($accountName) ?></strong><span class="account-position"><?= H($currentUser['position_title'] ?: 'DevAtlas account') ?></span><span class="account-signed-in"><i aria-hidden="true"></i> Signed in</span></div>
            </div>
        </header>
        <div class="account-menu-body">
            <div class="account-section-label">Account</div>
            <details class="account-details" id="accountDetails">
                <summary class="account-action" data-account-focus><span class="account-action-icon"><?= $accountIcon('person') ?></span><span><strong>Profile details</strong><small>Your contact and organization information</small></span><span class="account-action-chevron"><?= $accountIcon('chevron') ?></span></summary>
                <div class="account-details-body">
                    <dl>
                        <div>
                            <dt>Employee ID</dt>
                            <dd class="account-copy-value"><span><?= H($currentUser['employee_id']) ?></span><button
                                    type="button"
                                    class="account-copy"
                                    data-account-copy="<?= H($currentUser['employee_id']) ?>"
                                    aria-label="Copy employee ID"
                                ><?= $accountIcon('copy') ?></button></dd>
                        </div>
                        <div>
                            <dt>Email address</dt>
                            <dd>

                                <?php if ($currentUser['email']): ?>
                                <span class="account-copy-value"><span><?= H($currentUser['email']) ?></span><button
                                        type="button"
                                        class="account-copy"
                                        data-account-copy="<?= H($currentUser['email']) ?>"
                                        aria-label="Copy email address"
                                    ><?= $accountIcon('copy') ?></button></span>

                                <?php else: ?>
                                <span class="account-missing">No email address on file. Ask your administrator to add one for email sign-in and password recovery.</span>

                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Department</dt>
                            <dd><?= H($currentUser['department_name'] ?: 'Not assigned') ?></dd>
                        </div>
                        <div>
                            <dt>Access role</dt>
                            <dd><?= H(Permissions::Actor()['role_name'] ?? 'Not assigned') ?></dd>
                        </div>
                        <div>
                            <dt>Position</dt>
                            <dd><?= H($currentUser['position_title'] ?: 'Not assigned') ?></dd>
                        </div>
                    </dl>
                    <p>Name, contact and organization changes are managed by your system administrator.</p>
                </div>
            </details>
            <button
                class="account-action"
                id="avatarUploadButton"
                type="button"
                onclick="document.getElementById('avatarFileInput').click()"
                aria-describedby="avatarUploadHint avatarUploadFeedback"
            ><span class="account-action-icon"><?= $accountIcon('camera') ?></span><span><strong id="avatarUploadLabel">Change profile photo</strong><small id="avatarUploadHint">JPG, PNG, GIF or WebP · Up to 2 MB</small></span></button>
            <input
                type="file"
                id="avatarFileInput"
                accept="image/png,image/jpeg,image/gif,image/webp"
                hidden
                onchange="AT.uploadAvatar(this)"
                aria-label="Choose a profile photo"
            >
            <p
                id="avatarUploadFeedback"
                class="account-upload-feedback"
                role="status"
                aria-live="polite"
                hidden
            ></p>
            <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/change_password.php')) ?>" class="account-action"><span class="account-action-icon"><?= $accountIcon('key') ?></span><span><strong>Change password</strong><small>Update your sign-in password</small></span><span class="account-action-arrow" aria-hidden="true"><?= UiIcon('arrow-right') ?></span></a>
            <div class="account-appearance">
                <div class="account-section-label" id="accountAppearanceLabel">Appearance</div>
                <div
                    class="account-themes"
                    x-data="ThemePicker"
                    role="group"
                    aria-labelledby="accountAppearanceLabel"
                ><button
                        type="button"
                        data-account-theme="light"
                        aria-pressed="true"
                        x-on:click="Choose('light')"
                    ><?= $accountIcon('sun') ?> Light</button><button
                        type="button"
                        data-account-theme="dark"
                        aria-pressed="false"
                        x-on:click="Choose('dark')"
                    ><?= $accountIcon('moon') ?> Dark</button><button
                        type="button"
                        data-account-theme="system"
                        aria-pressed="false"
                        x-on:click="Choose('system')"
                    ><?= $accountIcon('monitor') ?> System</button></div>
            </div>
        </div>
        <footer class="account-menu-footer"><a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/logout.php')) ?>" class="account-action account-signout"><span class="account-action-icon"><?= $accountIcon('exit') ?></span><span><strong>Sign out</strong><small>End this session</small></span></a>

            <?php if ($accountSignedIn > 0): ?>
            <p class="account-session"><?= $accountIcon('clock') ?><span>Signed in <time datetime="<?= H(Date(DATE_ATOM, $accountSignedIn)) ?>" title="<?= H(Date('F j, Y · g:i A', $accountSignedIn)) ?>"><?= H(Date('M j · g:i A', $accountSignedIn)) ?></time></span></p>

            <?php endif; ?>
        </footer>
    </section>
</div>

<?php endif; ?>
