<?php
/**
    * Filename: ui_assets.php
    * Purpose: Provide ui assets processing and supporting DevAtlas workflows.
    * Developer: DevAtlas maintainers
    * Date: 2026-11-12
    * Recent updates: Load shared motion assets across module, authentication and documentation frames.
    */

/* Shared local feedback assets, loaded before app.js in every page frame. */
if (!isset($uiState) && !empty($_SESSION['user_id'])) {
    require_once ROOTPATH . 'lib/UserUiPreferences.php';
    $uiState = UserUiPreferences::Load(Database::GetConnection('app'), (int)$_SESSION['user_id']);
}
$uiMotion = $uiState['preferences']['motion'] ?? 'standard';
$uiAppearance = $experienceState['documents']['appearance']['value'] ?? null;
if ($uiAppearance === null && !empty($_SESSION['user_id'])) {
    require_once ROOTPATH.'lib/WorkspaceExperience.php';
    $uiAppearance = WorkspaceExperience::Read(Database::GetConnection('app'), (int)$_SESSION['user_id'], 'appearance', false)['value'];
}
$uiAppearance = $uiAppearance ?? ['accent'=>'blue','textSize'=>'normal'];
?>
<script>document.documentElement.dataset.motion = <?= json_encode($uiMotion, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;document.documentElement.dataset.accent=<?= json_encode($uiAppearance['accent']) ?>;document.documentElement.dataset.readingSize=<?= json_encode($uiAppearance['textSize']) ?>;</script>
<meta name="icon-sprite" content="<?= H(AssetUrl('assets/vendor/lucide/sprite.svg')) ?>">
<link rel="stylesheet" href="<?= H(AssetUrl('assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= H(AssetUrl('assets/vendor/sweetalert2/sweetalert2.min.css')) ?>">
<script src="<?= H(AssetUrl('assets/vendor/sweetalert2/sweetalert2.min.js')) ?>"></script>

<meta name="htmx-config" content='{"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true,"historyCacheSize":0}'>
<link rel="stylesheet" href="<?= H(AssetUrl('assets/css/tailwind.css')) ?>">
<script src="<?= H(AssetUrl('assets/vendor/htmx/htmx.min.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/stack.js')) ?>"></script>
<script defer src="<?= H(AssetUrl('assets/vendor/alpine/alpine.min.js')) ?>"></script>
<link rel="stylesheet" href="<?= H(AssetUrl('assets/css/ui-motion.css')) ?>">
<script defer src="<?= H(AssetUrl('assets/js/ui-motion.js')) ?>"></script>


<link rel="stylesheet" href="<?= H(AssetUrl('assets/vendor/flatpickr/flatpickr.min.css')) ?>">
<link rel="stylesheet" href="<?= H(AssetUrl('assets/vendor/flatpickr/month-select.css')) ?>">
<link rel="stylesheet" href="<?= H(AssetUrl('assets/css/experience.css')) ?>">
<script src="<?= H(AssetUrl('assets/js/experience-boot.js')) ?>"></script>
