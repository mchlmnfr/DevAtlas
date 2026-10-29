<?php
/**
 * Filename: auth_end.php
 * Purpose: Render the recovery page footer.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>

</main>
<footer class="login-footer"><span><?= H(APP_NAME) ?> Â· Enterprise IT management</span>

    <?php echo view('layouts/theme_control', get_defined_vars());
    ?>
</footer>
</body>
</html>
