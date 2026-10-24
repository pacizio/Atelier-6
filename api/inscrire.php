<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/db.php';

function respond(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Inscription</title>';
    echo '<p>' . $safeMessage . '</p><p><a href="../index.html">Retour au formulaire</a></p></html>';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, 'Méthode non autorisée.');
    exit;
}

$nom = isset($_POST['nom']) && is_string($_POST['nom']) ? trim($_POST['nom']) : '';
$pnom = isset($_POST['pnom']) && is_string($_POST['pnom']) ? trim($_POST['pnom']) : '';
$ville = isset($_POST['ville']) && is_string($_POST['ville']) ? trim($_POST['ville']) : '';

if ($nom === '' || $pnom === '' || $ville === '') {
    respond(400, 'Veuillez remplir tous les champs.');
    exit;
}

if (
    !isset($_FILES['img'])
    || !is_array($_FILES['img'])
    || !isset($_FILES['img']['error'], $_FILES['img']['tmp_name'], $_FILES['img']['size'])
    || !is_int($_FILES['img']['error'])
    || !is_string($_FILES['img']['tmp_name'])
    || !is_int($_FILES['img']['size'])
) {
    respond(400, 'Veuillez sélectionner une image.');
    exit;
}

$image = $_FILES['img'];
if ($image['error'] !== UPLOAD_ERR_OK) {
    $message = $image['error'] === UPLOAD_ERR_NO_FILE
        ? 'Veuillez sélectionner une image.'
        : 'Le téléversement de l’image a échoué. Vérifiez sa taille et réessayez.';
    respond(400, $message);
    exit;
}

$maxImageBytes = 2 * 1024 * 1024;
if ($image['size'] > $maxImageBytes) {
    respond(400, 'L’image doit peser 2 Mo maximum.');
    exit;
}

if (!is_uploaded_file($image['tmp_name'])) {
    respond(400, 'Le fichier reçu n’est pas un téléversement valide.');
    exit;
}

$imageInfo = @getimagesize($image['tmp_name']);
$allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if ($imageInfo === false || !in_array($imageInfo['mime'] ?? '', $allowedImageTypes, true)) {
    respond(400, 'Format d’image non accepté. Utilisez JPEG, PNG, GIF ou WebP.');
    exit;
}

$cloudinary = null;
$uploadedPublicId = null;

try {
    $cloudinary = new \Cloudinary\Cloudinary();
    $upload = $cloudinary->uploadApi()->upload(
        $image['tmp_name'],
        [
            'folder' => 'atelier-stagiaires',
            'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        ]
    );

    $imgUrl = $upload['secure_url'] ?? null;
    $uploadedPublicId = $upload['public_id'] ?? null;
    if (!is_string($imgUrl) || !is_string($uploadedPublicId)) {
        throw new RuntimeException('Cloudinary returned an incomplete upload response.');
    }

    $pdo = getDatabase();
    $stmt = $pdo->prepare(
        'INSERT INTO stagiaires (nom, prenom, ville, photo) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$nom, $pnom, $ville, $imgUrl]);
} catch (Throwable $exception) {
    if ($uploadedPublicId !== null && $cloudinary !== null) {
        try {
            $cloudinary->uploadApi()->destroy($uploadedPublicId);
        } catch (Throwable $cleanupException) {
            error_log('Cloudinary cleanup failed: ' . $cleanupException->getMessage());
        }
    }

    error_log('Registration upload failed: ' . $exception->getMessage());
    respond(500, 'L’inscription a échoué. Veuillez réessayer plus tard.');
    exit;
}

respond(200, 'Inscription enregistrée avec succès.');
