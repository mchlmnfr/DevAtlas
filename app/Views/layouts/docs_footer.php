<?php
/**
    * Filename: docs_footer.php
    * Purpose: Provide docs footer processing and supporting DevAtlas workflows.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-29
    * Recent updates: Render CodeIgniter layouts and align first-party template markup.
    */

/* Standalone help pages deliberately have no application sidebar. */ ?>
</main>
<footer class="docs-footer"><?= H(APP_NAME) ?> &middot; Help &amp; updates

    <?php echo view('layouts/theme_control', get_defined_vars());
?>
</footer>

<?php echo view('layouts/updates_dialog', get_defined_vars());
?>

<?php if (!empty($pageScript)): ?>
<script src="<?= H(AssetUrl($pageScript)) ?>"></script>

<?php endif; ?>
<script src="<?= H(AssetUrl('assets/js/updates.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/vendor/flatpickr/flatpickr.min.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/vendor/flatpickr/month-select.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/experience-core.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/experience-forms.js')) ?>"></script>
</body>
</html>
