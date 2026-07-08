<?php
declare(strict_types=1);

if (!function_exists('apprentice_e')) {
    function apprentice_e(string|int|null $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('apprentice_month')) {
    function apprentice_month(DateTimeInterface $date): string
    {
        $months = [
            1 => 'ENE',
            2 => 'FEB',
            3 => 'MAR',
            4 => 'ABR',
            5 => 'MAY',
            6 => 'JUN',
            7 => 'JUL',
            8 => 'AGO',
            9 => 'SEP',
            10 => 'OCT',
            11 => 'NOV',
            12 => 'DIC',
        ];

        return $months[(int)$date->format('n')] ?? mb_strtoupper($date->format('M'), 'UTF-8');
    }
}

if (!function_exists('apprentice_short_date')) {
    function apprentice_short_date(DateTimeInterface $date): string
    {
        return $date->format('d') . ' ' . apprentice_month($date);
    }
}

if (!function_exists('apprentice_sidebar')) {
    function apprentice_sidebar(
        string $current,
        string $nombreCompleto,
        string $iniciales,
        string $fotoPerfil,
        string $correo
    ): void {
        $items = [
            'dashboard' => ['IN', 'Dashboard', app_url('aprendiz/index.php')],
            'eventos' => ['EV', 'Eventos', app_url('aprendiz/eventos.php')],
            'preregistro' => ['PR', 'Pre-registro', app_url('aprendiz/preregistro.php')],
            'certificados' => ['CE', 'Certificados', app_url('aprendiz/certificados.php')],
            'perfil' => ['PE', 'Perfil', app_url('aprendiz/perfil.php')],
        ];
        ?>
        <aside class="apprentice-sidebar" aria-label="Menu del aprendiz">
            <a class="apprentice-brand" href="<?= apprentice_e(app_url('aprendiz/index.php')) ?>">
                <span>
                    <strong>SICA</strong>
                    <small>Registro de asistencia</small>
                </span>
            </a>

            <a class="apprentice-person" href="<?= apprentice_e(app_url('aprendiz/perfil.php')) ?>" aria-label="Ver perfil del aprendiz">
                <div class="apprentice-person-avatar">
                    <?php if ($fotoPerfil !== ''): ?>
                        <img src="<?= apprentice_e(app_url($fotoPerfil)) ?>" alt="">
                    <?php else: ?>
                        <?= apprentice_e($iniciales) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?= apprentice_e($nombreCompleto) ?></strong>
                    <small><?= apprentice_e($correo) ?></small>
                </div>
            </a>

            <nav class="apprentice-nav">
                <?php foreach ($items as $key => [$icon, $label, $url]): ?>
                    <a class="<?= $current === $key ? 'active' : '' ?>" href="<?= apprentice_e($url) ?>">
                        <span aria-hidden="true"><?= apprentice_e($icon) ?></span>
                        <?= apprentice_e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php
    }
}
