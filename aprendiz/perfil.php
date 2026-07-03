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
    $nombrePost = trim((string)($_POST['nombre'] ?? ''));
    $apellidoPost = trim((string)($_POST['apellido'] ?? ''));
    $correoPost = trim((string)($_POST['correo'] ?? ''));
    $telefonoPost = trim((string)($_POST['telefono'] ?? ''));
    $fichaPost = (int)($_POST['id_ficha'] ?? 0);

    if (!hash_equals((string)$_SESSION['csrf_perfil'], $csrf)) {
        $_SESSION['profile_message'] = 'La sesion expiro. Intenta de nuevo.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif ($nombrePost === '' || $apellidoPost === '' || !filter_var($correoPost, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['profile_message'] = 'Revisa nombre, apellido y correo antes de guardar.';
        $_SESSION['profile_message_type'] = 'danger';
    } elseif ($telefonoPost !== '' && !preg_match('/^[0-9+\s-]{7,15}$/', $telefonoPost)) {
        $_SESSION['profile_message'] = 'El telefono solo debe contener numeros, espacios, + o guiones.';
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
                $_SESSION['profile_message'] = 'No fue posible guardar el perfil.';
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
$fichaActualLabel = trim((string)($perfil['id_ficha'] ?? '') . ' - ' . (string)($perfil['nombre_programa'] ?? '') . ' - ' . (string)($perfil['nombre_jornada'] ?? ''));
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

            <form class="profile-form" method="post" action="<?= e(app_url('aprendiz/perfil.php')) ?>" enctype="multipart/form-data">
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
                        <input type="file" name="foto_perfil" accept="image/png,image/jpeg,image/webp">
                        <small>JPG, PNG o WebP. Maximo 2 MB.</small>
                    </label>
                </div>

                <label>
                    <span>Nombre</span>
                    <input type="text" name="nombre" value="<?= e($perfil['nombre']) ?>" required maxlength="50">
                </label>
                <label>
                    <span>Apellido</span>
                    <input type="text" name="apellido" value="<?= e($perfil['apellido']) ?>" required maxlength="50">
                </label>
                <label>
                    <span>Correo personal</span>
                    <input type="email" name="correo" value="<?= e($perfil['correo']) ?>" required maxlength="100">
                </label>
                <label>
                    <span>Telefono</span>
                    <input type="tel" name="telefono" value="<?= e($perfil['telefono'] ?? '') ?>" maxlength="15">
                </label>
                <label>
                    <span>Documento</span>
                    <input type="text" value="<?= e($perfil['id_documento']) ?>" readonly>
                </label>
                <label class="profile-wide-field">
                    <span>Ficha, programa y jornada</span>
                    <input type="hidden" id="idFichaPerfil" name="id_ficha" value="<?= e($perfil['id_ficha']) ?>">
                    <div class="profile-ficha-picker" data-fichas='<?= e(json_encode(array_map(static function (array $ficha): array {
                        return [
                            'id' => (string)$ficha['id_ficha'],
                            'label' => $ficha['id_ficha'] . ' - ' . $ficha['nombre_programa'] . ' - ' . $ficha['nombre_jornada'],
                        ];
                    }, $fichas), JSON_UNESCAPED_UNICODE)) ?>'>
                        <input type="search" id="fichaSearch" class="profile-ficha-search" value="<?= e($fichaActualLabel) ?>" placeholder="Escribe 306, ADSO, MIXTA..." autocomplete="off" required>
                        <div id="fichaResults" class="profile-ficha-results" hidden></div>
                    </div>
                    <small>Escribe 306 y apareceran las fichas que empiezan por 306; tambien puedes buscar por programa o jornada.</small>
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
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('fichaSearch');
    const hidden = document.getElementById('idFichaPerfil');
    const picker = document.querySelector('.profile-ficha-picker');
    const results = document.getElementById('fichaResults');

    if (!search || !hidden || !picker || !results) {
        return;
    }

    let fichas = [];
    try {
        fichas = JSON.parse(picker.dataset.fichas || '[]');
    } catch (error) {
        fichas = [];
    }

    const closeResults = function () {
        results.hidden = true;
        results.innerHTML = '';
    };

    const chooseFicha = function (ficha) {
        search.value = ficha.label;
        hidden.value = ficha.id;
        search.setCustomValidity('');
        closeResults();
    };

    const renderResults = function () {
        const query = search.value.trim().toLowerCase();
        hidden.value = '';

        if (query.length < 2) {
            closeResults();
            return;
        }

        const isNumericSearch = /^[0-9]+$/.test(query);
        const matches = fichas
            .filter(function (ficha) {
                if (isNumericSearch) {
                    return ficha.id.startsWith(query);
                }

                return ficha.label.toLowerCase().includes(query);
            })
            .slice(0, 12);

        if (matches.length === 0) {
            results.innerHTML = '<span class="empty-result">No se encontraron fichas.</span>';
            results.hidden = false;
            return;
        }

        results.innerHTML = '';
        matches.forEach(function (ficha) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = ficha.label;
            button.addEventListener('click', function () {
                chooseFicha(ficha);
            });
            results.appendChild(button);
        });
        results.hidden = false;
    };

    const syncFicha = function () {
        const selected = fichas.find(function (ficha) {
            return ficha.label === search.value;
        });

        if (selected) {
            hidden.value = selected.id;
            search.setCustomValidity('');
        } else {
            search.setCustomValidity('Selecciona una ficha de la lista.');
        }
    };

    search.addEventListener('input', renderResults);
    search.addEventListener('focus', renderResults);
    document.addEventListener('click', function (event) {
        if (!picker.contains(event.target)) {
            closeResults();
        }
    });
    search.form.addEventListener('submit', syncFicha);
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
