<?php
/**
    * Filename: ui_assets.php
    * Purpose: Provide ui assets processing and supporting DevAtlas workflows.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-29
    * Recent updates: Render CodeIgniter layouts and align first-party template markup.
    */

/* Shared local feedback assets, loaded before app.js in every page frame. */ ?>
<meta name="icon-sprite" content="<?= H(AssetUrl('assets/vendor/lucide/sprite.svg')) ?>">
<link rel="stylesheet" href="<?= H(AssetUrl('assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= H(AssetUrl('assets/vendor/sweetalert2/sweetalert2.min.css')) ?>">
<script src="<?= H(AssetUrl('assets/vendor/sweetalert2/sweetalert2.min.js')) ?>"></script>

<meta name="htmx-config" content='{"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true,"historyCacheSize":0}'>
<link rel="stylesheet" href="<?= H(AssetUrl('assets/css/tailwind.css')) ?>">
<script src="<?= H(AssetUrl('assets/vendor/htmx/htmx.min.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/stack.js')) ?>"></script>
<script defer src="<?= H(AssetUrl('assets/vendor/alpine/alpine.min.js')) ?>"></script>
