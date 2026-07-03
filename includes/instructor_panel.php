<?php
declare(strict_types=1);

function instructor_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function instructor_user(): array
{
    return $_SESSION['usuario'] ?? [];
}

function instructor_full_name(array $user): string
{
    $name = trim((string)($user['nombre'] ?? 'Instructor'));
    $last = trim((string)($user['apellido'] ?? ''));
    $full = trim($name . ' ' . $last);
    return $full !== '' ? $full : 'Instructor SICA';
}

function instructor_initials(array $user): string
{
    $name = trim((string)($user['nombre'] ?? 'I'));
    $last = trim((string)($user['apellido'] ?? ''));
    $initials = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8') . mb_substr($last, 0, 1, 'UTF-8'), 'UTF-8');
    return $initials !== '' ? $initials : 'IN';
}

function instructor_estado_id(PDO $pdo, string $estado): int
{
    $stmt = $pdo->prepare('SELECT id_estado FROM estado WHERE nombre_estado = :estado LIMIT 1');
    $stmt->execute([':estado' => $estado]);
    $id = (int)$stmt->fetchColumn();

    if ($id > 0) {
        return $id;
    }

    $insert = $pdo->prepare('INSERT INTO estado (nombre_estado) VALUES (:estado)');
    $insert->execute([':estado' => $estado]);
    return (int)$pdo->lastInsertId();
}

function instructor_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function instructor_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function instructor_layout_start(string $active): void
{
    $user = instructor_user();
    $name = instructor_full_name($user);
    $mail = (string)($user['correo'] ?? '');
    $initials = instructor_initials($user);
    $items = [
        'dashboard' => ['IN', 'Dashboard', 'instructor/index.php'],
        'disponibilidad' => ['DI', 'Disponibilidad', 'instructor/disponibilidad.php'],
        'solicitudes' => ['SO', 'Mis solicitudes', 'instructor/mis_solicitudes.php'],
        'asistencia' => ['QR', 'Asistencia / codigo', 'instructor/asistencia.php'],
        'participantes' => ['PA', 'Participantes', 'instructor/participantes.php'],
        'perfil' => ['PE', 'Perfil', 'instructor/perfil.php'],
    ];
    ?>
    <main class="instructor-dashboard">
        <aside class="instructor-sidebar" aria-label="Menu del instructor">
            <a class="instructor-brand" href="<?= instructor_h(app_url('instructor/index.php')) ?>">
                <span class="brand-mark">S</span>
                <span>
                    <strong>SICA</strong>
                    <small>Instructor</small>
                </span>
            </a>

            <section class="instructor-person">
                <div class="instructor-avatar"><?= instructor_h($initials) ?></div>
                <div>
                    <strong><?= instructor_h($name) ?></strong>
                    <small><?= instructor_h($mail) ?></small>
                </div>
            </section>

            <nav class="instructor-nav">
                <?php foreach ($items as $key => [$short, $label, $url]): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= instructor_h(app_url($url)) ?>">
                        <span aria-hidden="true"><?= instructor_h($short) ?></span>
                        <?= instructor_h($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <a class="instructor-logout" href="<?= instructor_h(app_url('login/logout.php')) ?>">
                <span aria-hidden="true">SL</span>
                Cerrar sesion
            </a>
        </aside>

        <section class="instructor-main">
    <?php
}

function instructor_layout_end(): void
{
    ?>
        </section>
    </main>
    <?php
}

function instructor_status_class(string $estado): string
{
    $estado = mb_strtolower($estado, 'UTF-8');
    if (str_contains($estado, 'activo') || str_contains($estado, 'aprob')) {
        return 'ok';
    }
    if (str_contains($estado, 'pendiente')) {
        return 'pending';
    }
    if (str_contains($estado, 'cancel') || str_contains($estado, 'rechaz')) {
        return 'danger';
    }
    return 'muted';
}

function instructor_event_query(): string
{
    return 'SELECT e.id_evento, e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin,
                   e.codigo_evento, e.observacion, e.fecha_aprobacion, e.id_solicitante,
                   a.nombre_auditorio, a.bloque, a.capacidad, te.nombre_tipo, es.nombre_estado AS estado
            FROM evento e
            INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
            INNER JOIN tipo_evento te ON te.id_tipo_evento = e.id_tipo_evento
            INNER JOIN estado es ON es.id_estado = e.id_estado';
}

function instructor_download_qr_svg(string $code, string $title = 'Codigo SICA'): string
{
    $seed = crc32($code);
    $cells = '';
    for ($y = 0; $y < 17; $y++) {
        for ($x = 0; $x < 17; $x++) {
            $finder = ($x < 5 && $y < 5) || ($x > 11 && $y < 5) || ($x < 5 && $y > 11);
            $bit = (($seed >> (($x + $y * 3) % 24)) + $x * 7 + $y * 11) % 5 < 2;
            if ($finder || $bit) {
                $cells .= '<rect x="' . (28 + $x * 10) . '" y="' . (38 + $y * 10) . '" width="8" height="8" rx="1" fill="#0e1a2f"/>';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="260" height="320" viewBox="0 0 260 320">'
        . '<rect width="260" height="320" rx="18" fill="#f7fbff"/>'
        . '<rect x="16" y="16" width="228" height="288" rx="14" fill="#ffffff" stroke="#dbe6f0"/>'
        . '<text x="130" y="30" text-anchor="middle" font-family="Arial" font-size="12" font-weight="700" fill="#165dff">SICA</text>'
        . '<text x="130" y="232" text-anchor="middle" font-family="Arial" font-size="18" font-weight="800" fill="#0e1a2f">' . instructor_h($code) . '</text>'
        . '<text x="130" y="260" text-anchor="middle" font-family="Arial" font-size="12" fill="#65748b">' . instructor_h($title) . '</text>'
        . $cells
        . '</svg>';
}
