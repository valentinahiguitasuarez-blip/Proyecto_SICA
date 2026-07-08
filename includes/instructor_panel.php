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
    $photo = (string)($user['foto_perfil'] ?? '');
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
                <div class="instructor-avatar">
                    <?php if ($photo !== ''): ?>
                        <img src="<?= instructor_h(app_url($photo)) ?>" alt="">
                    <?php else: ?>
                        <?= instructor_h($initials) ?>
                    <?php endif; ?>
                </div>
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

            <section class="instructor-id-card" aria-label="Credencial del instructor">
                <span class="instructor-sidebar-label">Credencial del instructor</span>
                <div class="instructor-photo-card">
                    <div class="instructor-id-photo" aria-hidden="true">
                        <?php if ($photo !== ''): ?>
                            <img src="<?= instructor_h(app_url($photo)) ?>" alt="">
                        <?php else: ?>
                            <span><?= instructor_h($initials) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="instructor-scan" aria-hidden="true"></div>
                </div>
                <div class="instructor-id-copy">
                    <strong><?= instructor_h($name) ?></strong>
                    <small>Instructor activo</small>
                </div>
                <a class="instructor-profile-link" href="<?= instructor_h(app_url('instructor/perfil.php')) ?>">Ver perfil</a>
            </section>

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
    if (str_contains($estado, 'final') || str_contains($estado, 'finalizado')) {
        return 'ok';
    }
    if (str_contains($estado, 'activo')) {
        return 'navy';
    }
    if (str_contains($estado, 'aprob')) {
        return 'ok';
    }
    if (str_contains($estado, 'pendiente')) {
        return 'pending';
    }
    if (str_contains($estado, 'cancel') || str_contains($estado, 'rechaz')) {
        return 'info';
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

function instructor_event_qr_payload(array $evento): string
{
    $path = 'aprendiz/preregistro.php?evento=' . (int)$evento['id_evento']
        . '&codigo=' . rawurlencode((string)$evento['codigo_evento']);
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . app_url($path);
    }

    $configPath = __DIR__ . '/../config/app.php';
    $config = is_file($configPath) ? require $configPath : [];
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

    if ($baseUrl !== '') {
        return $baseUrl . '/' . ltrim($path, '/');
    }

    return 'http://localhost' . app_url($path);
}

function instructor_qr_image_url(string $payload, int $size = 220): string
{
    $size = max(120, min(600, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&format=svg&margin=12&data=' . rawurlencode($payload);
}

function instructor_download_qr_svg(string $code, string $title = 'Codigo SICA', ?string $payload = null): string
{
    $payload = $payload ?? $code;
    $qrUrl = instructor_qr_image_url($payload, 190);

    return '<svg xmlns="http://www.w3.org/2000/svg" width="260" height="320" viewBox="0 0 260 320">'
        . '<rect width="260" height="320" rx="18" fill="#f7fbff"/>'
        . '<rect x="16" y="16" width="228" height="288" rx="14" fill="#ffffff" stroke="#dbe6f0"/>'
        . '<text x="130" y="30" text-anchor="middle" font-family="Arial" font-size="12" font-weight="700" fill="#165dff">SICA</text>'
        . '<image href="' . instructor_h($qrUrl) . '" x="35" y="42" width="190" height="190"/>'
        . '<text x="130" y="232" text-anchor="middle" font-family="Arial" font-size="18" font-weight="800" fill="#0e1a2f">' . instructor_h($code) . '</text>'
        . '<text x="130" y="260" text-anchor="middle" font-family="Arial" font-size="12" fill="#65748b">' . instructor_h($title) . '</text>'
        . '</svg>';
}
