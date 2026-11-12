<?php
/**
 * Filename: customizer.php
 * Purpose: Render dashboard customization controls with native keyboard focus containment.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Add personal dashboard layouts and shared motion controls.
 */ /** Account-owned dashboard controls. Native dialog supplies keyboard focus containment. */ ?>
<dialog id="dashboardCustomizer" class="dashboard-customizer" aria-labelledby="dashboardCustomizerTitle">
    <div class="dashboard-customizer-head">
        <div><span class="dashboard-overline">YOUR WORKSPACE, YOUR WAY</span><h2 id="dashboardCustomizerTitle">Customize dashboard</h2><p>Choose what you see and put it in the order that works for you.</p></div>
        <button type="button" class="btn btn-sm" id="dashboardCustomizeClose" aria-label="Close dashboard customization">×</button>
    </div>
    <form id="dashboardCustomizeForm">
        <div class="dashboard-customizer-body">
            <div id="dashboardCustomizeError" class="dashboard-customize-message" role="alert" hidden></div>
            <p id="dashboardCustomizeNotice" class="dashboard-customize-note" aria-live="polite"></p>
            <?php if (!$uiState['storage_ready']): ?>
            <p class="dashboard-customize-note">Saving personalization is not available yet. Please contact an administrator.</p>
            <?php endif; ?>
            <fieldset id="dashboardCustomizeFields">
                <legend class="sr-only">Dashboard preferences</legend>
                <section aria-labelledby="dashboardWidgetsHeading">
                    <div class="dashboard-customize-heading"><h3 id="dashboardWidgetsHeading">Your widgets</h3><span id="dashboardVisibleCount"></span></div>
                    <p class="dashboard-customize-note" id="dashboardReorderHelp">Drag the grip to reorder, or use the up and down buttons. Turn off widgets you do not need.</p>
                    <ol id="dashboardWidgetOptions" class="dashboard-widget-options" aria-describedby="dashboardReorderHelp"></ol>
                </section>
                <section class="dashboard-customize-options" aria-labelledby="dashboardAppearanceHeading">
                    <h3 id="dashboardAppearanceHeading">Appearance &amp; motion</h3>
                    <div class="form-row"><label for="dashboardDensity">Dashboard spacing</label><select id="dashboardDensity"><option value="comfortable">Comfortable</option><option value="compact">Compact</option></select></div>
                    <div class="form-row"><label for="dashboardMotion">Animations across DevAtlas</label><select id="dashboardMotion"><option value="standard">Standard</option><option value="subtle">Subtle</option><option value="off">Off</option></select><p class="dashboard-customize-note">Your device’s reduced-motion setting always takes priority.</p></div>
                </section>
            </fieldset>
        </div>
        <div class="dashboard-customizer-footer">
            <button type="button" class="btn" id="dashboardCustomizeDefaults">Restore defaults</button>
            <div><button type="button" class="btn" id="dashboardCustomizeReload" hidden>Reload saved settings</button><button type="button" class="btn" id="dashboardCustomizeCancel">Cancel</button><button type="submit" class="btn btn-primary" id="dashboardCustomizeSave" <?= $uiState['storage_ready'] ? '' : 'disabled' ?>>Save changes</button></div>
        </div>
    </form>
</dialog>
