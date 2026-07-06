<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
iniciarSesionSegura();
requireRole([4]);
require_once __DIR__ . '/../config/conexion.php';

$pageTitle = 'Perfil del Aprendiz - SICA';
$pageStyles = ['css/aprendiz.css'];

function e(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function profile_normalize_spaces(string $value): string
{
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function profile_name_is_valid(string $value, int $max): bool
{
    return (bool)preg_match('/^[\p{L}\s\'-]{2,' . $max . '}$/u', $value);
}

$usuario = $_SESSION['usuario'] ?? [];
$idDocumento = (int)($usuario['id_documento'] ?? 0);
$profileMessage = $_SESSION['profile_message'] ?? '';
$profileMessageType = $_SESSION['profile_message_type'] ?? 'success';
unset($_SESSION['profile_message'], $_SESSION['profile_message_type']);

if (empty($_SESSION['csrf_perfil'])) {
    $_SESSION['csrf_perfil'] = bin2hex(random_bytes(32));
}

$fichas = [];
try {
    $fichas = $pdo->query(
        'SELECT f.id_ficha, p.nombre_programa, j.nombre_jornada
         FROM ficha f
         INNER JOIN programa p ON p.id_programa = f.id_programa
         INNER JOIN jornada j ON j.id_jornada = p.id_jornada
         INNER JOIN estado es ON es.id_estado = f.id_estado
         WHERE es.nombre_estado = \'Activo\'
         ORDER BY f.id_ficha ASC'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('SICA perfil: no se pudieron cargar fichas: ' . $exception->getMessage());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string)($_POST['csrf_perfil'] ?? '');
    $nombrePost = profile_normalize_spaces((string)($_POST['nombre'] ?? ''));
    $apellidoPost = profile_normalize_spaces((string)($_POST['apellido'] ?? ''));
    $correoPost = strtolower(trim((string)($_POST['correo'] ?? '')));
    $telefonoPost = trim((string)($_POST['telefono'] ?? ''));
    $fichaPost = (int)($_POST['id_ficha'] ?? 0);
    $documentoActual = (string)$idDocumento;

    if (!hash_equals((string)$_SESSION['csrf_perfil'], $csrf)) {
        $_SESSION['profile_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif (!preg_match('/^\d{1,10}$/', $documentoActual)) {
        $_SESSION['profile_message'] = 'El documento debe tener solo numeros y maximo 10 digitos.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif (!profile_name_is_valid($nombrePost, 50)) {
        $_SESSION['profile_message'] = 'El nombre debe tener entre 2 y 50 letras. No uses numeros ni simbolos.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif (!profile_name_is_valid($apellidoPost, 60)) {
        $_SESSION['profile_message'] = 'El apellido debe tener entre 2 y 60 letras. No uses numeros ni simbolos.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif (strlen($correoPost) > 100 || !filter_var($correoPost, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['profile_message'] = 'Escribe un correo personal valido de maximo 100 caracteres.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif ($telefonoPost !== '' && !preg_match('/^\d{10}$/', $telefonoPost)) {
        $_SESSION['profile_message'] = 'El telefono debe tener exactamente 10 numeros.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif ($fichaPost <= 0) {
        $_SESSION['profile_message'] = 'Selecciona una ficha valida.';
        $_SESSION['profile_message_type'] = 'danger';
    } else {
        if (($profileMessage = $_SESSION['profile_message'] ?? '') === '') {
            try {
                $fotoPerfil = null;
                $fichaExiste = false;
                foreach ($fichas as $ficha) {
                    if ((int)$ficha['id_ficha'] === $fichaPost) {
                        $fichaExiste = true;
                        break;
                    }
                }

                if (!$fichaExiste) {
                    throw new RuntimeException('Ficha no valida.');
                }

                $correoStmt = $pdo->prepare(
                    'SELECT id_documento
                     FROM usuario
                     WHERE correo = :correo AND id_documento <> :id_documento
                     LIMIT 1'
                );
                $correoStmt->execute([
                    ':correo' => $correoPost,
                    ':id_documento' => $idDocumento,
                ]);

                if ($correoStmt->fetch()) {
                    throw new RuntimeException('correo_duplicado');
                }

                if (!empty($_FILES['foto_perfil']['name'])) {
                    if (($_FILES['foto_perfil']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('No fue posible cargar la foto.');
                    }

                    if ((int)($_FILES['foto_perfil']['size'] ?? 0) > 2 * 1024 * 1024) {
                        throw new RuntimeException('La foto no puede superar 2 MB.');
                    }

                    $tmpName = (string)($_FILES['foto_perfil']['tmp_name'] ?? '');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = (string)$finfo->file($tmpName);
                    $extensiones = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ];

                    if (!array_key_exists($mime, $extensiones)) {
                        throw new RuntimeException('La foto debe ser JPG, PNG o WebP.');
                    }

                    $uploadDir = __DIR__ . '/../uploads/perfiles';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                        throw new RuntimeException('No se pudo preparar la carpeta de fotos.');
                    }

                    $fileName = 'aprendiz_' . $idDocumento . '_' . time() . '.' . $extensiones[$mime];
                    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                    if (!move_uploaded_file($tmpName, $destination)) {
                        throw new RuntimeException('No se pudo guardar la foto.');
                    }

                    $fotoPerfil = 'uploads/perfiles/' . $fileName;
                }

                $currentStmt = $pdo->prepare(
                    'SELECT nombre, apellido, correo, telefono, id_ficha
                     FROM usuario
                     WHERE id_documento = :id_documento
                     LIMIT 1'
                );
                $currentStmt->execute([':id_documento' => $idDocumento]);
                $currentProfile = $currentStmt->fetch() ?: [];

                $sinCambios = $fotoPerfil === null
                    && $nombrePost === (string)($currentProfile['nombre'] ?? '')
                    && $apellidoPost === (string)($currentProfile['apellido'] ?? '')
                    && $correoPost === strtolower((string)($currentProfile['correo'] ?? ''))
                    && $telefonoPost === (string)($currentProfile['telefono'] ?? '')
                    && $fichaPost === (int)($currentProfile['id_ficha'] ?? 0);

                if ($sinCambios) {
                    $_SESSION['profile_message'] = 'No realizaste cambios en tu perfil.';
                    $_SESSION['profile_message_type'] = 'info';
                    header('Location: ' . app_url('aprendiz/perfil.php'));
                    exit;
                }

                $fotoSql = $fotoPerfil !== null ? ', foto_perfil = :foto_perfil' : '';
                $updateStmt = $pdo->prepare(
                    'UPDATE usuario
                     SET nombre = :nombre,
                         apellido = :apellido,
                         correo = :correo,
                         telefono = :telefono,
                         id_ficha = :id_ficha' . $fotoSql . '
                     WHERE id_documento = :id_documento'
                );
                $params = [
                    ':nombre' => $nombrePost,
                    ':apellido' => $apellidoPost,
                    ':correo' => $correoPost,
                    ':telefono' => $telefonoPost !== '' ? $telefonoPost : null,
                    ':id_ficha' => $fichaPost,
                    ':id_documento' => $idDocumento,
                ];

                if ($fotoPerfil !== null) {
                    $params[':foto_perfil'] = $fotoPerfil;
                }

                $updateStmt->execute($params);

                $_SESSION['usuario']['nombre'] = $nombrePost;
                $_SESSION['usuario']['apellido'] = $apellidoPost;
                $_SESSION['usuario']['correo'] = $correoPost;
                $_SESSION['usuario']['telefono'] = $telefonoPost;
                $_SESSION['usuario']['id_ficha'] = $fichaPost;
                if ($fotoPerfil !== null) {
                    $_SESSION['usuario']['foto_perfil'] = $fotoPerfil;
                }
                $_SESSION['profile_message'] = 'Perfil actualizado correctamente.';
                $_SESSION['profile_message_type'] = 'success';
            } catch (PDOException $exception) {
                $_SESSION['profile_message'] = $exception->getCode() === '23000'
                    ? 'Ese correo ya esta registrado por otro usuario.'
                    : 'No fue posible guardar el perfil.';
                $_SESSION['profile_message_type'] = 'danger';
                error_log('SICA perfil: error actualizando usuario: ' . $exception->getMessage());
            } catch (Throwable $exception) {
                $_SESSION['profile_message'] = $exception->getMessage() === 'correo_duplicado'
                    ? 'Ese correo ya esta registrado por otro usuario.'
                    : 'No fue posible guardar el perfil.';
                $_SESSION['profile_message_type'] = 'danger';
                error_log('SICA perfil: ' . $exception->getMessage());
            }
        }
    }

    header('Location: ' . app_url('aprendiz/perfil.php'));
    exit;
}

$perfil = null;
try {
    $perfilStmt = $pdo->prepare(
        'SELECT u.id_documento, u.nombre, u.apellido, u.correo, u.telefono, u.foto_perfil, u.fecha_registro,
                u.id_ficha, r.nombre_rol, es.nombre_estado, p.nombre_programa,
                j.nombre_jornada, n.nombre_nivel, f.fecha_inicio_f, f.fecha_fin_f
         FROM usuario u
         INNER JOIN rol r ON r.id_rol = u.id_rol
         INNER JOIN estado es ON es.id_estado = u.id_estado
         LEFT JOIN ficha f ON f.id_ficha = u.id_ficha
         LEFT JOIN programa p ON p.id_programa = f.id_programa
         LEFT JOIN jornada j ON j.id_jornada = p.id_jornada
         LEFT JOIN nivel_formacion n ON n.id_nivel_formacion = p.id_nivel_formacion
         WHERE u.id_documento = :id_documento
         LIMIT 1'
    );
    $perfilStmt->execute([':id_documento' => $idDocumento]);
    $perfil = $perfilStmt->fetch();
} catch (Throwable $exception) {
    error_log('SICA perfil: no se pudo cargar perfil: ' . $exception->getMessage());
}

if (!$perfil) {
    http_response_code(404);
    exit('Perfil no encontrado.');
}

$nombre = trim((string)$perfil['nombre']);
$apellido = trim((string)$perfil['apellido']);
$nombreCompleto = trim($nombre . ' ' . $apellido);
$iniciales = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8') . mb_substr($apellido, 0, 1, 'UTF-8'), 'UTF-8');
$iniciales = $iniciales !== '' ? $iniciales : 'A';
$fotoPerfil = !empty($perfil['foto_perfil']) ? (string)$perfil['foto_perfil'] : '';
?>
<?php include_once __DIR__ . '/../includes/header.php'; ?>

<main class="apprentice-dashboard">
    <aside class="apprentice-sidebar" aria-label="Menu del aprendiz">
        <a class="apprentice-brand" href="<?= e(app_url('aprendiz/index.php')) ?>">
            <span>
                <strong>SICA</strong>
                <small>Aprendiz</small>
            </span>
        </a>

        <section class="apprentice-person" aria-label="Aprendiz activo">
            <div class="apprentice-person-avatar">
                <?php if ($fotoPerfil !== ''): ?>
                    <img src="<?= e(app_url($fotoPerfil)) ?>" alt="">
                <?php else: ?>
                    <?= e($iniciales) ?>
                <?php endif; ?>
            </div>
            <div>
                <strong><?= e($nombreCompleto) ?></strong>
                <small><?= e((string)$perfil['correo']) ?></small>
            </div>
        </section>

        <nav class="apprentice-nav">
            <a href="<?= e(app_url('aprendiz/index.php')) ?>"><span aria-hidden="true">IN</span>Dashboard</a>
            <a href="<?= e(app_url('aprendiz/eventos.php')) ?>"><span aria-hidden="true">EV</span>Eventos</a>
            <a href="<?= e(app_url('aprendiz/preregistro.php')) ?>"><span aria-hidden="true">PR</span>Pre-registro</a>
            <a class="active" href="<?= e(app_url('aprendiz/perfil.php')) ?>"><span aria-hidden="true">PE</span>Perfil</a>
        </nav>

        <section class="learner-id-card" aria-label="Credencial del aprendiz">
            <span class="sidebar-label">Credencial del aprendiz</span>
            <div class="learner-photo-card">
                <div class="learner-photo" aria-hidden="true">
                    <?php if ($fotoPerfil !== ''): ?>
                        <img src="<?= e(app_url($fotoPerfil)) ?>" alt="">
                    <?php else: ?>
                        <span><?= e($iniciales) ?></span>
                    <?php endif; ?>
                </div>
                <div class="learner-scan" aria-hidden="true"></div>
            </div>
            <div class="learner-id-copy">
                <strong><?= e($nombreCompleto) ?></strong>
                <small><?= e((string)$perfil['nombre_estado']) ?></small>
            </div>
            <div class="learner-id-data">
                <div>
                    <span>Ficha</span>
                    <strong><?= e($perfil['id_ficha'] ?? 'Sin ficha') ?></strong>
                </div>
                <div>
                    <span>Programa</span>
                    <strong><?= e($perfil['nombre_programa'] ?? 'Programa no asignado') ?></strong>
                </div>
            </div>
        </section>
    </aside>

    <section class="apprentice-main">
        <header class="apprentice-topbar">
            <div>
                <p class="eyebrow">Perfil del aprendiz</p>
                <h1>Mis datos personales</h1>
                <span>Actualiza tu informacion para pre-registros, certificados y notificaciones.</span>
            </div>

            <a class="top-logout" href="<?= e(app_url('aprendiz/index.php')) ?>">
                <span aria-hidden="true">IN</span>
                Dashboard
            </a>
        </header>

        <?php if ($profileMessage !== ''): ?>
            <div class="event-alert <?= e($profileMessageType) ?>">
                <?= e($profileMessage) ?>
            </div>
        <?php endif; ?>

        <section class="profile-grid" aria-label="Perfil del aprendiz">
            <article class="profile-summary">
                <div class="profile-summary-head">
                    <div class="profile-avatar" aria-hidden="true">
                        <?php if ($fotoPerfil !== ''): ?>
                            <img src="<?= e(app_url($fotoPerfil)) ?>" alt="">
                        <?php else: ?>
                            <?= e($iniciales) ?>
                        <?php endif; ?>
                    </div>
                    <p class="eyebrow">Credencial activa</p>
                    <h2><?= e($nombreCompleto) ?></h2>
                    <span><?= e((string)$perfil['correo']) ?></span>
                </div>
                <dl>
                    <div>
                        <dt>Documento</dt>
                        <dd><?= e($perfil['id_documento']) ?></dd>
                    </div>
                    <div>
                        <dt>Rol</dt>
                        <dd><?= e($perfil['nombre_rol']) ?></dd>
                    </div>
                    <div>
                        <dt>Estado</dt>
                        <dd><?= e($perfil['nombre_estado']) ?></dd>
                    </div>
                    <div>
                        <dt>Registro</dt>
                        <dd><?= e($perfil['fecha_registro']) ?></dd>
                    </div>
                </dl>
            </article>

            <form class="profile-form" method="post" action="<?= e(app_url('aprendiz/perfil.php')) ?>" enctype="multipart/form-data" data-profile-form>
                <input type="hidden" name="csrf_perfil" value="<?= e($_SESSION['csrf_perfil']) ?>">

                <div class="profile-form-intro">
                    <div class="profile-photo-preview">
                        <?php if ($fotoPerfil !== ''): ?>
                            <img src="<?= e(app_url($fotoPerfil)) ?>" alt="">
                        <?php else: ?>
                            <strong><?= e($iniciales) ?></strong>
                        <?php endif; ?>
                    </div>
                    <label class="profile-photo-field">
                        <span>Foto de perfil</span>
                        <input type="file" name="foto_perfil" accept="image/png,image/jpeg,image/webp" data-profile-photo>
                        <small>JPG, PNG o WebP. Maximo 2 MB.</small>
                        <small class="profile-field-error" data-error-for="foto_perfil"></small>
                    </label>
                </div>

                <label>
                    <span>Nombre</span>
                    <input type="text" name="nombre" value="<?= e($perfil['nombre']) ?>" required minlength="2" maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s'-]{2,50}" autocomplete="given-name">
                    <small class="profile-field-error" data-error-for="nombre"></small>
                </label>
                <label>
                    <span>Apellido</span>
                    <input type="text" name="apellido" value="<?= e($perfil['apellido']) ?>" required minlength="2" maxlength="60" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s'-]{2,60}" autocomplete="family-name">
                    <small class="profile-field-error" data-error-for="apellido"></small>
                </label>
                <label>
                    <span>Correo personal</span>
                    <input type="email" name="correo" value="<?= e($perfil['correo']) ?>" required maxlength="100" autocomplete="email">
                    <small class="profile-field-error" data-error-for="correo"></small>
                </label>
                <label>
                    <span>Telefono</span>
                    <input type="tel" name="telefono" value="<?= e($perfil['telefono'] ?? '') ?>" maxlength="10" pattern="\d{10}" inputmode="numeric" autocomplete="tel">
                    <small>Opcional. Si lo escribes, debe tener 10 numeros.</small>
                    <small class="profile-field-error" data-error-for="telefono"></small>
                </label>
                <label>
                    <span>Documento</span>
                    <input type="text" value="<?= e($perfil['id_documento']) ?>" maxlength="10" pattern="\d{1,10}" inputmode="numeric" readonly>
                    <small>Solo numeros. Maximo 10 digitos.</small>
                </label>
                <label class="profile-wide-field">
                    <span>Ficha, programa y jornada</span>
                    <select name="id_ficha" required>
                        <option value="">Selecciona una ficha</option>
                        <?php foreach ($fichas as $ficha): ?>
                            <option value="<?= e($ficha['id_ficha']) ?>" <?= ((int)$perfil['id_ficha'] === (int)$ficha['id_ficha']) ? 'selected' : '' ?>>
                                <?= e($ficha['id_ficha'] . ' - ' . $ficha['nombre_programa'] . ' - ' . $ficha['nombre_jornada']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Selecciona la ficha correspondiente con su programa y jornada.</small>
                    <small class="profile-field-error" data-error-for="id_ficha"></small>
                </label>

                <div class="profile-readonly-line">
                    <div>
                        <span>Programa actual</span>
                        <strong><?= e($perfil['nombre_programa'] ?? 'Programa no asignado') ?></strong>
                    </div>
                    <div>
                        <span>Jornada actual</span>
                        <strong><?= e($perfil['nombre_jornada'] ?? 'Jornada no asignada') ?></strong>
                    </div>
                    <div>
                        <span>Nivel</span>
                        <strong><?= e($perfil['nombre_nivel'] ?? 'Nivel no asignado') ?></strong>
                    </div>
                    <div>
                        <span>Vigencia ficha</span>
                        <strong><?= e(($perfil['fecha_inicio_f'] ?? '') . ' / ' . ($perfil['fecha_fin_f'] ?? '')) ?></strong>
                    </div>
                </div>

                <button type="submit">Guardar cambios</button>
            </form>
        </section>
    </section>
</main>

<script>
(() => {
    const form = document.querySelector('[data-profile-form]');
    if (!form) {
        return;
    }

    const submitButton = form.querySelector('button[type="submit"]');
    const fields = {
        nombre: form.elements.nombre,
        apellido: form.elements.apellido,
        correo: form.elements.correo,
        telefono: form.elements.telefono,
        id_ficha: form.elements.id_ficha,
        foto_perfil: form.querySelector('[data-profile-photo]')
    };

    const messages = {
        nombre: 'El nombre debe tener entre 2 y 50 letras.',
        apellido: 'El apellido debe tener entre 2 y 60 letras.',
        correo: 'Escribe un correo personal valido, maximo 100 caracteres.',
        telefono: 'El telefono debe tener exactamente 10 numeros.',
        id_ficha: 'Selecciona una ficha valida.',
        foto_perfil: 'La foto debe ser JPG, PNG o WebP y pesar maximo 2 MB.'
    };

    const namePattern = /^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s'-]+$/;
    const setError = (name, message = '') => {
        const target = form.querySelector(`[data-error-for="${name}"]`);
        const field = fields[name];
        if (target) {
            target.textContent = message;
        }
        if (field) {
            field.classList.toggle('is-invalid', message !== '');
        }
    };

    const validate = () => {
        let valid = true;
        const nombre = fields.nombre.value.trim().replace(/\s+/g, ' ');
        const apellido = fields.apellido.value.trim().replace(/\s+/g, ' ');
        const correo = fields.correo.value.trim();
        const telefono = fields.telefono.value.trim();
        const ficha = fields.id_ficha.value;
        const foto = fields.foto_perfil.files[0];

        if (nombre.length < 2 || nombre.length > 50 || !namePattern.test(nombre)) {
            setError('nombre', messages.nombre);
            valid = false;
        } else {
            setError('nombre');
        }

        if (apellido.length < 2 || apellido.length > 60 || !namePattern.test(apellido)) {
            setError('apellido', messages.apellido);
            valid = false;
        } else {
            setError('apellido');
        }

        if (correo.length > 100 || !fields.correo.validity.valid) {
            setError('correo', messages.correo);
            valid = false;
        } else {
            setError('correo');
        }

        if (telefono !== '' && !/^\d{10}$/.test(telefono)) {
            setError('telefono', messages.telefono);
            valid = false;
        } else {
            setError('telefono');
        }

        if (ficha === '') {
            setError('id_ficha', messages.id_ficha);
            valid = false;
        } else {
            setError('id_ficha');
        }

        if (foto) {
            const allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowed.includes(foto.type) || foto.size > 2 * 1024 * 1024) {
                setError('foto_perfil', messages.foto_perfil);
                valid = false;
            } else {
                setError('foto_perfil');
            }
        } else {
            setError('foto_perfil');
        }

        submitButton.disabled = !valid;
        return valid;
    };

    ['input', 'change'].forEach((eventName) => {
        form.addEventListener(eventName, (event) => {
            if (event.target.matches('input, select')) {
                validate();
            }
        });
    });

    form.addEventListener('submit', (event) => {
        if (!validate()) {
            event.preventDefault();
        }
    });

    validate();
})();
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
