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

function coord_pill_class(string $estado): string
{
    $estado = mb_strtolower($estado, 'UTF-8');
    if (str_contains($estado, 'final')) {
        return 'ok';
    }
    if (str_contains($estado, 'activo')) {
        return 'navy';
    }
    if (str_contains($estado, 'pendiente')) {
        return 'pending';
    }
    if (str_contains($estado, 'cancel') || str_contains($estado, 'rechaz')) {
        return 'info';
    }
    return 'muted';
}

function coord_dotacion_sin_registrar(mixed $value): bool
{
    return $value === null || $value === '' || $value === false
        || (is_string($value) && trim($value) === '');
}

function coord_dotacion_label(mixed $value): string
{
    if (coord_dotacion_sin_registrar($value)) {
        return 'Por registrar';
    }

    return (int)$value === 1 ? 'Sí' : 'No';
}

function coord_computadores_label(mixed $value): string
{
    if (coord_dotacion_sin_registrar($value)) {
        return 'Por registrar';
    }

    return (string)(int)$value;
}

function coord_dotacion_value_class(mixed $value): string
{
    return coord_dotacion_sin_registrar($value) ? 'is-unregistered' : '';
}

function coord_event_query(): string
{
    return 'SELECT e.id_evento, e.nombre_evento, e.descripcion, e.fecha_evento, e.hora_inicio, e.hora_fin,
                   e.codigo_evento, e.observacion, e.fecha_aprobacion, e.id_solicitante, e.id_coordinador,
                   a.nombre_auditorio, a.bloque, a.capacidad, te.nombre_tipo, es.nombre_estado AS estado,
                   u.nombre, u.apellido, u.correo
            FROM evento e
            INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
            INNER JOIN tipo_evento te ON te.id_tipo_evento = e.id_tipo_evento
            INNER JOIN estado es ON es.id_estado = e.id_estado
            LEFT JOIN usuario u ON u.id_documento = e.id_solicitante';
}

function coord_historial_filters(array $input, int $coordinadorId): array
{
    $estadoFiltro = trim((string)($input['estado'] ?? ''));
    $busqueda = trim((string)($input['q'] ?? ''));
    $estadosHistorial = ['Activo', 'Cancelado', 'Finalizado'];
    if ($estadoFiltro !== '' && !in_array($estadoFiltro, $estadosHistorial, true)) {
        $estadoFiltro = '';
    }
    if (mb_strlen($busqueda, 'UTF-8') > 80 || preg_match('/[\x00-\x1F]/', $busqueda)) {
        $busqueda = '';
    }
    $desdeRaw = (string)($input['desde'] ?? '');
    $hastaRaw = (string)($input['hasta'] ?? '');
    $fechaDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeRaw)
        && checkdate((int)substr($desdeRaw, 5, 2), (int)substr($desdeRaw, 8, 2), (int)substr($desdeRaw, 0, 4))
        ? $desdeRaw
        : '';
    $fechaHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaRaw)
        && checkdate((int)substr($hastaRaw, 5, 2), (int)substr($hastaRaw, 8, 2), (int)substr($hastaRaw, 0, 4))
        ? $hastaRaw
        : '';
    if ($fechaDesde !== '' && $fechaHasta !== '' && $fechaDesde > $fechaHasta) {
        [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
    }

    $where = ['e.id_coordinador = :coordinador', "es.nombre_estado IN ('Activo', 'Cancelado', 'Finalizado')"];
    $params = [':coordinador' => $coordinadorId];

    if ($estadoFiltro !== '') {
        $where[] = 'es.nombre_estado = :estado';
        $params[':estado'] = $estadoFiltro;
    }
    if ($busqueda !== '') {
        $where[] = '(e.nombre_evento LIKE :busqueda OR e.codigo_evento LIKE :busqueda OR u.nombre LIKE :busqueda OR u.apellido LIKE :busqueda)';
        $params[':busqueda'] = '%' . $busqueda . '%';
    }
    if ($fechaDesde !== '') {
        $where[] = 'DATE(e.fecha_aprobacion) >= :desde';
        $params[':desde'] = $fechaDesde;
    }
    if ($fechaHasta !== '') {
        $where[] = 'DATE(e.fecha_aprobacion) <= :hasta';
        $params[':hasta'] = $fechaHasta;
    }

    $filtrosTexto = array_filter([$busqueda, $estadoFiltro]);
    if ($fechaDesde !== '' || $fechaHasta !== '') {
        $filtrosTexto[] = trim(($fechaDesde !== '' ? $fechaDesde : '...') . ' - ' . ($fechaHasta !== '' ? $fechaHasta : '...'));
    }

    return [
        'whereSql' => 'WHERE ' . implode(' AND ', $where),
        'params' => $params,
        'estadoFiltro' => $estadoFiltro,
        'busqueda' => $busqueda,
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'estadosHistorial' => $estadosHistorial,
        'hayFiltroActivo' => $estadoFiltro !== '' || $busqueda !== '' || $fechaDesde !== '' || $fechaHasta !== '',
        'filtroResumen' => $filtrosTexto !== [] ? implode(' · ', $filtrosTexto) : 'los filtros aplicados',
    ];
}

function coord_detail_steps(array $evento): array
{
    $estado = (string)($evento['estado'] ?? '');
    $decisionDate = '';
    try {
        if (!empty($evento['fecha_aprobacion'])) {
            $decisionDate = (new DateTime((string)$evento['fecha_aprobacion']))->format('d/m/Y');
        }
    } catch (Throwable) {
        $decisionDate = '';
    }

    return [
        ['key' => 'solicitado', 'label' => 'Solicitado', 'extra' => ''],
        ['key' => 'revision', 'label' => 'En revisión', 'extra' => ''],
        ['key' => 'decision', 'label' => 'Decisión', 'extra' => in_array($estado, ['Activo', 'Cancelado'], true) && $decisionDate !== '' ? ' - ' . $decisionDate : ''],
        ['key' => 'notificado', 'label' => 'Seguimiento', 'extra' => $estado === 'Finalizado' && $decisionDate !== '' ? ' - ' . $decisionDate : ''],
    ];
}

function coord_detail_step_class(array $step, string $estado): string
{
    $active = match ($estado) {
        'Activo', 'Cancelado' => 'decision',
        'Pendiente' => 'revision',
        'Finalizado' => 'notificado',
        default => 'solicitado',
    };

    if ($active !== (string)$step['key']) {
        return 'step';
    }

    $statusClass = match ($estado) {
        'Activo' => 'state-active',
        'Pendiente' => 'state-pending',
        'Cancelado' => 'state-cancelled',
        'Finalizado' => 'state-finished',
        default => 'state-muted',
    };

    return 'step is-current ' . $statusClass;
}

function coord_register_decision(PDO $pdo, int $coordinadorId, int $idEvento, string $accion, string $observacion): void
{
    if ($coordinadorId <= 0 || $idEvento <= 0 || !in_array($accion, ['aprobar', 'cancelar'], true)) {
        throw new RuntimeException('Selecciona una decisión válida.');
    }
    if ($accion === 'cancelar' && $observacion === '') {
        throw new RuntimeException('Para cancelar una solicitud debes registrar una observación clara.');
    }
    if (mb_strlen($observacion, 'UTF-8') > 220 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $observacion)) {
        throw new RuntimeException('La observación no puede superar 220 caracteres ni contener caracteres inválidos.');
    }

    $estadoIds = [
        'Activo' => coord_estado_id($pdo, 'Activo'),
        'Cancelado' => coord_estado_id($pdo, 'Cancelado'),
    ];

    $stmt = $pdo->prepare(
        'SELECT e.id_evento, e.fecha_evento, e.hora_inicio, e.hora_fin, e.id_auditorio, es.nombre_estado,
                ea.nombre_estado AS estado_auditorio
         FROM evento e
         INNER JOIN estado es ON es.id_estado = e.id_estado
         INNER JOIN auditorio a ON a.id_auditorio = e.id_auditorio
         INNER JOIN estado ea ON ea.id_estado = a.id_estado
         WHERE e.id_evento = :id_evento
           AND e.id_coordinador = :coordinador
         LIMIT 1'
    );
    $stmt->execute([':id_evento' => $idEvento, ':coordinador' => $coordinadorId]);
    $evento = $stmt->fetch();

    if (!$evento) {
        throw new RuntimeException('Solicitud no encontrada para tu coordinación.');
    }
    if ((string)$evento['nombre_estado'] !== 'Pendiente') {
        throw new RuntimeException('Esta solicitud ya tiene una decisión registrada.');
    }
    if ((string)$evento['fecha_evento'] < date('Y-m-d')) {
        throw new RuntimeException('No puedes aprobar o cancelar solicitudes con fecha vencida.');
    }

    if ($accion === 'aprobar') {
        if ((string)$evento['estado_auditorio'] !== 'Activo') {
            throw new RuntimeException('El auditorio ya no está activo. No puedes aprobar esta reserva.');
        }

        $overlap = coord_scalar(
            $pdo,
            "SELECT COUNT(*)
             FROM evento e
             INNER JOIN estado es ON es.id_estado = e.id_estado
             WHERE e.id_evento <> :id_evento
               AND e.id_auditorio = :auditorio
               AND e.fecha_evento = :fecha
               AND es.nombre_estado IN ('Activo', 'Pendiente')
               AND NOT (e.hora_fin <= :inicio OR e.hora_inicio >= :fin)",
            [
                ':id_evento' => $idEvento,
                ':auditorio' => (int)$evento['id_auditorio'],
                ':fecha' => (string)$evento['fecha_evento'],
                ':inicio' => (string)$evento['hora_inicio'],
                ':fin' => (string)$evento['hora_fin'],
            ]
        );

        if ($overlap > 0) {
            throw new RuntimeException('Ya existe otra reserva en ese auditorio y horario.');
        }
    }

    $nuevoEstado = $accion === 'aprobar' ? 'Activo' : 'Cancelado';
    $update = $pdo->prepare(
        'UPDATE evento
         SET id_estado = :estado,
             observacion = :observacion,
             fecha_aprobacion = NOW()
         WHERE id_evento = :id_evento
           AND id_coordinador = :coordinador'
    );
    $update->execute([
        ':estado' => $estadoIds[$nuevoEstado],
        ':observacion' => $observacion !== '' ? $observacion : null,
        ':id_evento' => $idEvento,
        ':coordinador' => $coordinadorId,
    ]);
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
        'dashboard' => ['DB', 'Dashboard', 'coordinador/index.php'],
        'solicitudes' => ['SR', 'Solicitudes', 'coordinador/solicitudes.php'],
        'calendario' => ['CA', 'Calendario de auditorios', 'coordinador/calendario.php'],
        'auditorios' => ['AU', 'Auditorios', 'coordinador/auditorios.php'],
        'historial' => ['HI', 'Historial de decisiones', 'coordinador/historial.php'],
        'perfil' => ['PE', 'Perfil', 'coordinador/perfil.php'],
    ];
    ?>
    <main class="instructor-dashboard coord-dashboard">
        <aside class="instructor-sidebar" aria-label="Menú del coordinador">
            <a class="instructor-brand" href="<?= coord_h(app_url('coordinador/index.php')) ?>">
                <span class="brand-mark">S</span>
                <span>
                    <strong>SICA</strong>
                    <small>Coordinación</small>
                </span>
            </a>

            <section class="instructor-person">
                <div class="instructor-avatar">
                    <?php if ($photo !== ''): ?>
                        <img src="<?= coord_h(app_url($photo)) ?>" alt="">
                    <?php else: ?>
                        <?= coord_h($initials) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?= coord_h($name) ?></strong>
                    <small><?= coord_h($mail) ?></small>
                </div>
            </section>

            <nav class="instructor-nav">
                <?php foreach ($items as $key => [$short, $label, $url]): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= coord_h(app_url($url)) ?>">
                        <span aria-hidden="true"><?= coord_h($short) ?></span>
                        <?= coord_h($label) ?>
                        <?php if ($key === 'solicitudes' && $pendientes > 0): ?>
                            <em class="instructor-nav-badge"><?= coord_h($pendientes) ?></em>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>


            <a class="instructor-logout" href="<?= coord_h(app_url('login/logout.php')) ?>">
                <span aria-hidden="true">SL</span>
                Cerrar sesión
            </a>
        </aside>

        <section class="instructor-main">
<?php
}

function coord_layout_end(): void
{
    ?>
        </section>
    </main>
    <?php
}
