<?php
declare(strict_types=1);

function coord_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function coord_user(): array
{
    return $_SESSION['usuario'] ?? [];
}

function coord_full_name(array $user): string
{
    $name = trim((string)($user['nombre'] ?? 'Coordinador'));
    $last = trim((string)($user['apellido'] ?? ''));
    $full = trim($name . ' ' . $last);
    return $full !== '' ? $full : 'Coordinador SICA';
}

function coord_initials(array $user): string
{
    $name = trim((string)($user['nombre'] ?? 'C'));
    $last = trim((string)($user['apellido'] ?? ''));
    $initials = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8') . mb_substr($last, 0, 1, 'UTF-8'), 'UTF-8');
    return $initials !== '' ? $initials : 'CO';
}

function coord_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coord_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function coord_estado_id(PDO $pdo, string $estado): int
{
    $stmt = $pdo->prepare('SELECT id_estado FROM estado WHERE nombre_estado = :estado LIMIT 1');
    $stmt->execute([':estado' => $estado]);
    return (int)$stmt->fetchColumn();
}

function coord_status_class(string $estado): string
{
    return match ($estado) {
        'Activo' => 'approved',
        'Pendiente' => 'pending',
        'Cancelado' => 'rejected',
        'Finalizado' => 'finished',
        default => 'neutral',
    };
}

function coord_hora12(?string $hora): string
{
    $hora = trim((string)$hora);
    if ($hora === '') {
        return '';
    }
    $ts = strtotime($hora);
    if ($ts === false) {
        return $hora;
    }
    $formatted = date('g:i A', $ts);
    return str_replace(['AM', 'PM'], ['a. m.', 'p. m.'], $formatted);
}

function coord_layout_start(string $active): void
{
    global $pdo;
    $user = coord_user();
    $name = coord_full_name($user);
    $mail = (string)($user['correo'] ?? 'coordinador@sica.edu.co');
    $initials = coord_initials($user);
    $photo = (string)($user['foto_perfil'] ?? '');
    $idCoordinador = (int)($user['id_documento'] ?? 0);

    $pendientes = 0;
    if ($pdo instanceof PDO && $idCoordinador > 0) {
        try {
            $pendientes = coord_scalar(
                $pdo,
                "SELECT COUNT(*) FROM evento e
                 INNER JOIN estado es ON es.id_estado = e.id_estado
                 WHERE e.id_coordinador = :coordinador AND es.nombre_estado = 'Pendiente'",
                [':coordinador' => $idCoordinador]
            );
        } catch (Throwable $exception) {
            error_log('SICA coordinador badge: ' . $exception->getMessage());
        }
    }

    $items = [
        'solicitudes' => ['SR', 'Solicitudes', 'coordinador/index.php', $pendientes],
        'calendario' => ['CA', 'Calendario de auditorios', 'coordinador/calendario.php', 0],
        'auditorios' => ['AU', 'Auditorios', 'coordinador/auditorios.php', 0],
        'historial' => ['HI', 'Historial de decisiones', 'coordinador/historial.php', 0],
        'perfil' => ['PE', 'Perfil', 'coordinador/perfil.php', 0],
    ];
    ?>
    <main class="admin-dashboard coord-dashboard">
        <aside class="admin-sidebar" aria-label="Menu del coordinador">
            <a class="admin-brand" href="<?= coord_h(app_url('coordinador/index.php')) ?>">
                <span class="coord-brand-mark">S</span>
                <span>
                    <strong>SICA</strong>
                    <small>Coordinacion de auditorios</small>
                </span>
            </a>

            <section class="admin-profile" aria-label="Coordinador activo">
                <div class="admin-avatar">
                    <?php if ($photo !== ''): ?>
                        <img src="<?= coord_h(app_url($photo)) ?>" alt="">
                    <?php else: ?>
                        <?= coord_h($initials) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?= coord_h($name) ?></strong>
                    <small><?= coord_h($mail) ?></small>
                    <span>Coordinador activo</span>
                    <a class="admin-profile-link" href="<?= coord_h(app_url('coordinador/perfil.php')) ?>">Ver perfil</a>
                </div>
            </section>

            <nav class="admin-nav">
                <?php foreach ($items as $key => [$short, $label, $url, $badge]): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= coord_h(app_url($url)) ?>">
                        <span><?= coord_h($short) ?></span><?= coord_h($label) ?>
                        <?php if ($badge > 0): ?><em class="admin-nav-badge"><?= coord_h($badge) ?></em><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?= coord_h(app_url('login/logout.php')) ?>"><span>SL</span>Cerrar sesion</a>
            </nav>
        </aside>

        <section class="admin-main">
<?php
}

function coord_layout_end(): void
{
    ?>
        </section>
    </main>
    <?php
}
