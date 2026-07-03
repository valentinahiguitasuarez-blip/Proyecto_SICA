<?php
declare(strict_types=1);
require_once __DIR__ . '/paths.php';
$loginJsVersion = (string)filemtime(__DIR__ . '/../js/login.js');
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= htmlspecialchars(app_url('js/login.js') . '?v=' . $loginJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>