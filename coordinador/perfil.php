<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

iniciarSesionSegura();

requireRole([2]);

require_once __DIR__ . '/../config/conexion.php';

require_once __DIR__ . '/../includes/coordinador_panel.php';



$pageTitle = 'Perfil coordinador - SICA';

$pageStyles = ['css/instructor.css', 'css/admin.css'];

$user = coord_user();

$idCoordinador = (int)($user['id_documento'] ?? 0);

$message = $_SESSION['coord_profile_message'] ?? '';

$messageType = $_SESSION['coord_profile_message_type'] ?? 'success';

unset($_SESSION['coord_profile_message'], $_SESSION['coord_profile_message_type']);



if (empty($_SESSION['csrf_coord_profile'])) {

    $_SESSION['csrf_coord_profile'] = bin2hex(random_bytes(32));

}



if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $csrf = (string)($_POST['csrf'] ?? '');

    $nombre = trim((string)($_POST['nombre'] ?? ''));

    $apellido = trim((string)($_POST['apellido'] ?? ''));

    $correo = mb_strtolower(trim((string)($_POST['correo'] ?? '')), 'UTF-8');

    $telefono = trim((string)($_POST['telefono'] ?? ''));

    $contrasena = trim((string)($_POST['contrasena'] ?? ''));

    $confirmarContrasena = trim((string)($_POST['confirmar_contrasena'] ?? ''));



    try {

        if (!hash_equals((string)$_SESSION['csrf_coord_profile'], $csrf)) {

            throw new RuntimeException('La sesión expiró. Intenta de nuevo.');

        }

        if ($nombre === '' || $apellido === '' || strlen($nombre) > 50 || strlen($apellido) > 50 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {

            throw new RuntimeException('Revisa nombre, apellido y correo.');

        }

        if ($telefono !== '' && !preg_match('/^[0-9+\s-]{7,15}$/', $telefono)) {

            throw new RuntimeException('El teléfono solo debe contener números, espacios, + o guiones.');

        }

        if ($contrasena !== '' || $confirmarContrasena !== '') {

            if (strlen($contrasena) < 6 || strlen($contrasena) > 72) {

                throw new RuntimeException('La contraseña debe tener entre 6 y 72 caracteres.');

            }

            if ($contrasena !== $confirmarContrasena) {

                throw new RuntimeException('Las contraseñas no coinciden.');

            }

        }



        $fotoPerfil = null;

        if (isset($_FILES['foto_perfil']['tmp_name']) && is_uploaded_file((string)$_FILES['foto_perfil']['tmp_name'])) {

            $error = $_FILES['foto_perfil']['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($error !== UPLOAD_ERR_OK) {

                throw new RuntimeException('No fue posible cargar la foto.');

            }

            if ((int)($_FILES['foto_perfil']['size'] ?? 0) > 2 * 1024 * 1024) {

                throw new RuntimeException('La foto no puede superar 2 MB.');

            }

            $tmpName = (string)($_FILES['foto_perfil']['tmp_name'] ?? '');

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            $mime = (string)$finfo->file($tmpName);

            $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

            if (!array_key_exists($mime, $extensiones)) {

                throw new RuntimeException('La foto debe ser JPG, PNG o WebP.');

            }

            $uploadDir = __DIR__ . '/../uploads/perfiles';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {

                throw new RuntimeException('No se pudo preparar la carpeta de fotos.');

            }

            $fileName = 'coordinador_' . $idCoordinador . '_' . time() . '.' . $extensiones[$mime];

            $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

            if (!move_uploaded_file($tmpName, $destination)) {

                throw new RuntimeException('No se pudo guardar la foto.');

            }

            $fotoPerfil = 'uploads/perfiles/' . $fileName;

        }



        $fotoSql = $fotoPerfil !== null ? ', foto_perfil = :foto' : '';

        $passwordSql = $contrasena !== '' ? ', contrasena = :contrasena' : '';

        $stmt = $pdo->prepare(

            'UPDATE usuario

             SET nombre = :nombre, apellido = :apellido, correo = :correo, telefono = :telefono' . $fotoSql . $passwordSql . '

             WHERE id_documento = :id'

        );

        $params = [

            ':nombre' => $nombre,

            ':apellido' => $apellido,

            ':correo' => $correo,

            ':telefono' => $telefono !== '' ? $telefono : null,

            ':id' => $idCoordinador,

        ];

        if ($fotoPerfil !== null) {

            $params[':foto'] = $fotoPerfil;

        }

        if ($contrasena !== '') {

            $params[':contrasena'] = password_hash($contrasena, PASSWORD_DEFAULT);

        }

        $stmt->execute($params);



        $_SESSION['usuario']['nombre'] = $nombre;

        $_SESSION['usuario']['apellido'] = $apellido;

        $_SESSION['usuario']['correo'] = $correo;

        $_SESSION['usuario']['telefono'] = $telefono;

        if ($fotoPerfil !== null) {

            $_SESSION['usuario']['foto_perfil'] = $fotoPerfil;

        }



        $_SESSION['coord_profile_message'] = 'Perfil actualizado correctamente.';

        $_SESSION['coord_profile_message_type'] = 'success';

    } catch (PDOException $exception) {

        $_SESSION['coord_profile_message'] = $exception->getCode() === '23000'

                ? 'Ese correo ya está registrado por otro usuario.'

            : 'No fue posible guardar el perfil.';

        $_SESSION['coord_profile_message_type'] = 'danger';

        error_log('SICA coordinador perfil: ' . $exception->getMessage());

    } catch (Throwable $exception) {

        $_SESSION['coord_profile_message'] = $exception->getMessage();

        $_SESSION['coord_profile_message_type'] = 'danger';

    }



    header('Location: ' . app_url('coordinador/perfil.php'));

    exit;

}



$stmt = $pdo->prepare(

    'SELECT u.*, r.nombre_rol, e.nombre_estado

     FROM usuario u

     INNER JOIN rol r ON r.id_rol = u.id_rol

     LEFT JOIN estado e ON e.id_estado = u.id_estado

     WHERE u.id_documento = :id

     LIMIT 1'

);

$stmt->execute([':id' => $idCoordinador]);

$perfil = $stmt->fetch();



if (!$perfil) {

    http_response_code(404);

    exit('Perfil no encontrado.');

}



$nombreCompleto = trim((string)$perfil['nombre'] . ' ' . (string)$perfil['apellido']);

$iniciales = coord_initials($perfil);

$fotoPerfil = !empty($perfil['foto_perfil']) ? (string)$perfil['foto_perfil'] : '';

?>

<?php include_once __DIR__ . '/../includes/header.php'; ?>

<?php coord_layout_start('perfil'); ?>



<header class="instructor-topbar">

    <div>

        <p class="eyebrow">Perfil del coordinador</p>

        <h1>Mis datos profesionales</h1>

        <span>Actualiza tus datos para la coordinación de auditorios, notificaciones y seguimiento de reservas.</span>

    </div>

    <a class="top-action" href="<?= coord_h(app_url('coordinador/index.php')) ?>">Dashboard</a>

</header>



<?php if ($message !== ''): ?>

    <div class="form-message <?= coord_h($messageType) ?>"><?= coord_h($message) ?></div>

<?php endif; ?>



<section class="instructor-profile-grid">

    <article class="profile-summary">

        <div class="profile-summary-head">

            <div class="profile-avatar" aria-hidden="true">

                <?php if ($fotoPerfil !== ''): ?>

                    <img src="<?= coord_h(app_url($fotoPerfil)) ?>" alt="">

                <?php else: ?>

                    <?= coord_h($iniciales) ?>

                <?php endif; ?>

            </div>

            <p class="eyebrow">Credencial activa</p>

            <h2><?= coord_h($nombreCompleto) ?></h2>

            <span><?= coord_h($perfil['correo']) ?></span>

        </div>

        <dl>

            <div><dt>Documento</dt><dd><?= coord_h($perfil['id_documento']) ?></dd></div>

            <div><dt>Rol</dt><dd><?= coord_h($perfil['nombre_rol']) ?></dd></div>

            <div><dt>Estado</dt><dd><?= coord_h($perfil['nombre_estado'] ?? 'Activo') ?></dd></div>

            <div><dt>Registro</dt><dd><?= coord_h($perfil['fecha_registro']) ?></dd></div>

        </dl>

    </article>



    <form class="profile-form instructor-profile-form" method="post" action="<?= coord_h(app_url('coordinador/perfil.php')) ?>" enctype="multipart/form-data">

        <input type="hidden" name="csrf" value="<?= coord_h($_SESSION['csrf_coord_profile']) ?>">



        <div class="profile-form-intro">

            <div class="profile-photo-preview">

                <?php if ($fotoPerfil !== ''): ?>

                    <img src="<?= coord_h(app_url($fotoPerfil)) ?>" alt="">

                <?php else: ?>

                    <strong><?= coord_h($iniciales) ?></strong>

                <?php endif; ?>

            </div>

            <label class="profile-photo-field">

                <span>Foto de perfil</span>

                <input type="file" name="foto_perfil" accept="image/png,image/jpeg,image/webp">

                <small>JPG, PNG o WebP. Máximo 2 MB.</small>

            </label>

        </div>



        <label>
            <span>Nombre</span>
            <input type="text" name="nombre" value="<?= coord_h($perfil['nombre']) ?>"
                   minlength="2" maxlength="50" required
                   pattern="[A-Za-zÁÉÍÓÚáéíóúÑñÜü\s\-']+"
                   title="Solo letras, espacios, guiones o apóstrofes. Mínimo 2 caracteres.">
        </label>

        <label>
            <span>Apellido</span>
            <input type="text" name="apellido" value="<?= coord_h($perfil['apellido']) ?>"
                   minlength="2" maxlength="50" required
                   pattern="[A-Za-zÁÉÍÓÚáéíóúÑñÜü\s\-']+"
                   title="Solo letras, espacios, guiones o apóstrofes. Mínimo 2 caracteres.">
        </label>

        <label>
            <span>Correo personal</span>
            <input type="email" name="correo" value="<?= coord_h($perfil['correo']) ?>"
                   maxlength="100" required autocomplete="email">
        </label>

        <label>
            <span>Teléfono</span>
            <input type="tel" name="telefono" value="<?= coord_h($perfil['telefono'] ?? '') ?>"
                   maxlength="15" pattern="[0-9+\s\-]{7,15}"
                   title="Entre 7 y 15 dígitos. Puede incluir +, espacios o guiones.">
        </label>

        <label><span>Documento</span><input type="text" value="<?= coord_h($perfil['id_documento']) ?>" readonly aria-readonly="true"></label>

        <label><span>Rol asignado</span><input type="text" value="<?= coord_h($perfil['nombre_rol']) ?>" readonly aria-readonly="true"></label>

        <button type="submit">Guardar cambios</button>

    </form>

</section>



<?php coord_layout_end(); ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>

