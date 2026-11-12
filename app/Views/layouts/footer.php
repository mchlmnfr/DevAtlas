<?php
/**
 * Filename: footer.php
 * Purpose: Provide footer processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
</div><!-- /content -->
</div><!-- /main -->
</div><!-- /app-shell -->

<?php echo view('layouts/updates_dialog', get_defined_vars());
 ?>

<script src="<?= H(AssetUrl('assets/vendor/flatpickr/flatpickr.min.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/vendor/flatpickr/month-select.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/experience-core.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/experience-forms.js')) ?>"></script>
<script src="<?= H(AssetUrl('assets/js/experience-tables.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
<script src="<?= H(AssetUrl($script)) ?>"></script>

<?php endforeach; ?>

<?php if (!empty($pageScript)): ?>
<script src="<?= H(AssetUrl($pageScript)) ?>"></script>

<?php endif; ?>
<script src="<?= H(AssetUrl('assets/js/updates.js')) ?>"></script>
</body>
</html>
