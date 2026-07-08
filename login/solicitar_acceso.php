<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../config/conexion.php';
session_start();

$pageTitle = 'Solicitar acceso - SICA';
$message = $_SESSION['access_request_message'] ?? '';
$messageType = $_SESSION['access_request_type'] ?? 'success';
$old = $_SESSION['access_request_old'] ?? [];
unset($_SESSION['access_request_message'], $_SESSION['access_request_type'], $_SESSION['access_request_old']);

if (empty($_SESSION['csrf_access_request'])) {
    $_SESSION['csrf_access_request'] = bin2hex(random_bytes(32));
}

function access_h(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function access_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$roles = [];
$fichas = [];
try {
    $roles = access_rows($pdo, 'SELECT id_rol, nombre_rol FROM rol WHERE id_rol <> 1 ORDER BY id_rol ASC');
    $fichas = access_rows(
        $pdo,
        'SELECT f.id_ficha, p.nombre_programa, j.nombre_jornada
         FROM ficha f
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = p.id_jornada
         ORDER BY f.id_ficha DESC'
    );
} catch (Throwable $exception) {
    error_log('SICA solicitud acceso catalogos: ' . $exception->getMessage());
}

$roleNamesById = [];
foreach ($roles as $role) {
    $roleNamesById[(int)$role['id_rol']] = (string)$role['nombre_rol'];
}
$roleIds = array_keys($roleNamesById);
$fichaIds = array_map(static fn(array $ficha): int => (int)$ficha['id_ficha'], $fichas);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_access_request'] ?? '');
    $old = $_POST;

    $tipoDocumento = (string)($_POST['tipo_documento'] ?? 'CC');
    $documentoRaw = trim((string)($_POST['id_documento'] ?? ''));
    $documento = ctype_digit($documentoRaw) ? (int)$documentoRaw : 0;
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $apellido = trim((string)($_POST['apellido'] ?? ''));
    $correo = mb_strtolower(trim((string)($_POST['correo'] ?? '')), 'UTF-8');
    $telefono = trim((string)($_POST['telefono'] ?? ''));
    $rol = (int)($_POST['id_rol'] ?? 0);
    $fichaRaw = trim((string)($_POST['id_ficha'] ?? ''));
    $ficha = $fichaRaw === '' ? null : (int)$fichaRaw;
    $rolNombre = mb_strtolower($roleNamesById[$rol] ?? '', 'UTF-8');
    if (!str_contains($rolNombre, 'aprendiz')) {
        $ficha = null;
    }

    if (!hash_equals((string)$_SESSION['csrf_access_request'], $csrf)) {
        $message = 'La sesion expiro. Intenta de nuevo.';
        $messageType = 'danger';
    } elseif (!in_array($tipoDocumento, ['CC', 'TI', 'CE', 'PEP'], true)) {
        $message = 'Selecciona un tipo de documento valido.';
        $messageType = 'danger';
    } elseif ($documento <= 0 || strlen($documentoRaw) > 20) {
        $message = 'Escribe un documento valido.';
        $messageType = 'danger';
    } elseif ($nombre === '' || $apellido === '' || strlen($nombre) > 50 || strlen($apellido) > 50) {
        $message = 'Escribe nombre y apellido validos.';
        $messageType = 'danger';
    } elseif ($correo === '' || strlen($correo) > 100 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $message = 'Escribe un correo personal valido.';
        $messageType = 'danger';
    } elseif ($telefono !== '' && strlen($telefono) > 15) {
        $message = 'El telefono no puede superar 15 caracteres.';
        $messageType = 'danger';
    } elseif (!in_array($rol, $roleIds, true)) {
        $message = 'Selecciona el rol que necesitas.';
        $messageType = 'danger';
    } elseif ($ficha !== null && !in_array($ficha, $fichaIds, true)) {
        $message = 'Selecciona una ficha valida o deja el campo vacio.';
        $messageType = 'danger';
    } else {
        try {
            $exists = $pdo->prepare('SELECT id_documento FROM usuario WHERE id_documento = :doc OR correo = :correo LIMIT 1');
            $exists->execute([':doc' => $documento, ':correo' => $correo]);

            $pending = $pdo->prepare("SELECT id_solicitud FROM solicitud_usuario WHERE estado = 'Pendiente' AND (id_documento = :doc OR correo = :correo) LIMIT 1");
            $pending->execute([':doc' => $documento, ':correo' => $correo]);

            if ($exists->fetch()) {
                throw new RuntimeException('Ya existe una cuenta con ese documento o correo.');
            }
            if ($pending->fetch()) {
                throw new RuntimeException('Ya tienes una solicitud pendiente. Espera la aprobacion del administrador.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO solicitud_usuario (tipo_documento, id_documento, nombre, apellido, correo, telefono, id_rol, id_ficha)
                 VALUES (:tipo_documento, :id_documento, :nombre, :apellido, :correo, :telefono, :id_rol, :id_ficha)'
            );
            $insert->execute([
                ':tipo_documento' => $tipoDocumento,
                ':id_documento' => $documento,
                ':nombre' => $nombre,
                ':apellido' => $apellido,
                ':correo' => $correo,
                ':telefono' => $telefono !== '' ? $telefono : null,
                ':id_rol' => $rol,
                ':id_ficha' => $ficha,
            ]);

            unset($_SESSION['csrf_access_request']);
            $_SESSION['access_request_message'] = 'Solicitud enviada. El administrador revisara tus datos y te enviara el acceso.';
            $_SESSION['access_request_type'] = 'success';
            header('Location: ' . app_url('login/solicitar_acceso.php'));
            exit;
        } catch (Throwable $exception) {
            $message = $exception->getMessage() ?: 'No fue posible enviar la solicitud.';
            $messageType = 'danger';
        }
    }

    if ($messageType === 'danger') {
        $_SESSION['access_request_message'] = $message;
        $_SESSION['access_request_type'] = $messageType;
    }
}
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="login-page">
    <section class="login-shell" aria-label="Solicitar acceso">
        <div class="login-card access-request-card">
            <div class="login-logo" aria-label="SICA"><span>SICA</span></div>
            <header class="login-header">
                <h1>Solicitar acceso</h1>
                <p>Completa tus datos y el administrador revisara tu solicitud.</p>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= access_h($messageType) ?> shadow-sm" role="alert"><?= access_h($message) ?></div>
            <?php endif; ?>

            <div class="access-request-steps" aria-label="Proceso de acceso">
                <span>1. Envias tus datos</span>
                <span>2. El admin aprueba</span>
                <span>3. Recibes tu acceso</span>
            </div>

            <form class="access-request-form" method="post" action="<?= access_h(app_url('login/solicitar_acceso.php')) ?>">
                <input type="hidden" name="csrf_access_request" value="<?= access_h($_SESSION['csrf_access_request']) ?>">
                <div class="access-request-grid">
                    <label><span>Tipo documento</span><select name="tipo_documento" required>
                        <?php foreach (['CC' => 'Cedula de ciudadania', 'TI' => 'Tarjeta de identidad', 'CE' => 'Cedula de extranjeria', 'PEP' => 'PEP'] as $key => $label): ?>
                            <option value="<?= access_h($key) ?>" <?= ($old['tipo_documento'] ?? 'CC') === $key ? 'selected' : '' ?>><?= access_h($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>Documento</span><input type="text" name="id_documento" inputmode="numeric" maxlength="20" required placeholder="Numero de documento" value="<?= access_h($old['id_documento'] ?? '') ?>"></label>
                    <label><span>Nombre</span><input type="text" name="nombre" maxlength="50" required placeholder="Tu nombre" value="<?= access_h($old['nombre'] ?? '') ?>"></label>
                    <label><span>Apellido</span><input type="text" name="apellido" maxlength="50" required placeholder="Tu apellido" value="<?= access_h($old['apellido'] ?? '') ?>"></label>
                    <label><span>Correo personal</span><input type="email" name="correo" maxlength="100" required placeholder="correo al que tienes acceso" value="<?= access_h($old['correo'] ?? '') ?>"></label>
                    <label><span>Telefono</span><input type="text" name="telefono" maxlength="15" placeholder="Opcional" value="<?= access_h($old['telefono'] ?? '') ?>"></label>
                    <label><span>Rol solicitado</span><select name="id_rol" required data-role-select>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= access_h($role['id_rol']) ?>" <?= (int)($old['id_rol'] ?? 4) === (int)$role['id_rol'] ? 'selected' : '' ?>><?= access_h($role['nombre_rol']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label data-ficha-field><span>Ficha</span><input type="text" name="id_ficha" list="fichasSolicitud" inputmode="numeric" placeholder="Buscar por ficha o programa" value="<?= access_h($old['id_ficha'] ?? '') ?>"></label>
                </div>
                <button type="submit" class="login-submit access-request-submit">Enviar solicitud</button>
            </form>

            <p class="login-register"><a href="<?= access_h(app_url('login/index.php')) ?>">Volver al inicio de sesion</a></p>
        </div>
    </section>
</main>

<datalist id="fichasSolicitud">
    <?php foreach ($fichas as $ficha): ?>
        <option value="<?= access_h($ficha['id_ficha']) ?>"><?= access_h($ficha['id_ficha']) ?> - <?= access_h($ficha['nombre_programa'] ?? 'Programa no asignado') ?><?= !empty($ficha['nombre_jornada']) ? ' / ' . access_h($ficha['nombre_jornada']) : '' ?></option>
    <?php endforeach; ?>
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-role-select]').forEach(function (select) {
        const update = function () {
            const form = select.closest('form');
            const field = form ? form.querySelector('[data-ficha-field]') : null;
            const text = select.options[select.selectedIndex] ? select.options[select.selectedIndex].textContent.toLowerCase() : '';
            if (field) {
                field.hidden = !text.includes('aprendiz');
                if (field.hidden) {
                    const input = field.querySelector('input');
                    if (input) input.value = '';
                }
            }
        };
        update();
        select.addEventListener('change', update);
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
