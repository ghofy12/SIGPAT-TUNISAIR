<?php
/**
 * extract_contrat.php  v5
 * Reçoit image/PDF en base64, appelle extract_decision.py, retourne JSON.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

// ── Filet de sécurité global ──────────────────────────────────────────────────
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'Erreur fatale PHP : ' . $err['message'],
            'ligne' => $err['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ── Helper JSON ───────────────────────────────────────────────────────────────
function jsonExit(array $payload, int $code = 200): never
{
    ob_end_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    exit(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ── Authentification ──────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    jsonExit(['error' => 'Non authentifié.'], 401);
}

if (!hasModuleAccess($pdo, 'extract_ocr')) {
    jsonExit(['error' => 'Votre profil ne vous autorise pas cette fonctionnalité.'], 403);
}

// ── Python : lire depuis config.php si déjà défini, sinon détecter ───────────
// config.php définit PYTHON_BIN via define() — on l'utilise directement.
if (defined('PYTHON_BIN') && file_exists(PYTHON_BIN)) {
    $pythonBin = PYTHON_BIN;
} else {
    // Fallback : chercher Python sur le système
    $pythonBin = 'python';
    $user = getenv('USERNAME') ?: 'user';
    $candidates = [];
    foreach (['313','312','311','310','39','38'] as $v) {
        $candidates[] = "C:\\Program Files\\Python{$v}\\python.exe";
        $candidates[] = "C:\\Python{$v}\\python.exe";
        $candidates[] = "C:\\Users\\{$user}\\AppData\\Local\\Programs\\Python\\Python{$v}\\python.exe";
    }
    foreach ($candidates as $c) {
        if (file_exists($c)) { $pythonBin = $c; break; }
    }
    // where.exe en dernier recours (filtre WindowsApps)
    if (!file_exists($pythonBin)) {
        $out = @shell_exec('where python 2>NUL');
        foreach (explode("\n", (string)$out) as $ligne) {
            $ligne = trim($ligne);
            if ($ligne && file_exists($ligne) && stripos($ligne, 'WindowsApps') === false) {
                $pythonBin = $ligne; break;
            }
        }
    }
}

$pythonScript = __DIR__ . '/extract_decision.py';
$isWindows    = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// ── Lire le body JSON ─────────────────────────────────────────────────────────
$body = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($body) || empty($body['image_base64'])) {
    jsonExit(['error' => 'Paramètre image_base64 manquant.'], 400);
}

$imageBase64 = (string) $body['image_base64'];
$imageType   = (string) ($body['image_type'] ?? 'image/jpeg');

// ── Valider le type MIME ──────────────────────────────────────────────────────
$extMap = [
    'image/jpeg' => 'jpg', 'image/png'  => 'png',
    'image/gif'  => 'gif', 'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
if (!isset($extMap[$imageType])) {
    jsonExit(['error' => "Type non supporté : $imageType"], 400);
}
$ext = $extMap[$imageType];

// ── Décoder base64 ────────────────────────────────────────────────────────────
$decoded = base64_decode($imageBase64, true);
if ($decoded === false || strlen($decoded) < 50) {
    jsonExit(['error' => 'Données base64 invalides ou trop courtes.'], 400);
}
if ($imageType === 'application/pdf' && substr($decoded, 0, 4) !== '%PDF') {
    jsonExit(['error' => 'Le fichier ne semble pas être un PDF valide.'], 400);
}

// ── Fichier temporaire ────────────────────────────────────────────────────────
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_contrats';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0700, true);
if (!is_dir($tmpDir)) {
    jsonExit(['error' => "Impossible de créer le dossier temporaire : $tmpDir"], 500);
}
$tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'contrat_' . uniqid('', true) . '.' . $ext;
if (file_put_contents($tmpFile, $decoded) === false) {
    jsonExit(['error' => "Impossible d'écrire le fichier temporaire."], 500);
}

// ── Vérifications ─────────────────────────────────────────────────────────────
if (!file_exists($pythonScript)) {
    @unlink($tmpFile);
    jsonExit(['error' => 'Script Python introuvable : ' . $pythonScript], 500);
}
if (!function_exists('shell_exec')) {
    @unlink($tmpFile);
    jsonExit(['error' => 'shell_exec est désactivé sur ce serveur.'], 500);
}

// ── Construire la commande ────────────────────────────────────────────────────
// Sur Windows, escapeshellarg() produit des guillemets simples ignorés par cmd.exe
// On force les guillemets doubles pour les chemins avec espaces.
$argBin    = '"' . str_replace('"', '', $pythonBin)    . '"';
$argScript = '"' . str_replace('"', '', $pythonScript) . '"';
$argFile   = '"' . str_replace('"', '', $tmpFile)      . '"';
$argZone   = escapeshellarg($zone);
$cmd       = "$argBin $argScript $argFile 2>&1";

// ── Exécuter ──────────────────────────────────────────────────────────────────
$output = @shell_exec($cmd);
@unlink($tmpFile);

if ($output === null || trim((string)$output) === '') {
    jsonExit([
        'error'         => 'Aucune sortie de Python.',
        'python_bin'    => $pythonBin,
        'python_exists' => file_exists($pythonBin) ? 'oui' : 'non',
        'commande'      => $cmd,
    ], 500);
}

// ── Extraire le JSON (algorithme de parenthésage, N niveaux) ─────────────────
$jsonStr   = null;
$lastBrace = strrpos($output, '}');
if ($lastBrace !== false) {
    $depth = 0;
    for ($i = $lastBrace; $i >= 0; $i--) {
        $c = $output[$i];
        if     ($c === '}') { $depth++; }
        elseif ($c === '{') { $depth--; if ($depth === 0) { $jsonStr = substr($output, $i, $lastBrace - $i + 1); break; } }
    }
}

if ($jsonStr === null) {
    jsonExit([
        'error'      => 'Aucun JSON dans la sortie Python.',
        'sortie'     => substr($output, 0, 1500),
        'python_bin' => $pythonBin,
        'commande'   => $cmd,
    ], 500);
}

$data = json_decode($jsonStr, true);
if (!is_array($data)) {
    jsonExit(['error' => 'JSON malformé retourné par Python.', 'extrait' => substr($jsonStr, 0, 300)], 500);
}
if (isset($data['error'])) {
    jsonExit($data, 500);
}

// ── Enrichir avec les données employé depuis la BDD ──────────────────────────
// Si on a un matricule, on cherche l'employé dans la table employes
if (!empty($data['matricule_agent'])) {
    try {
        $mle = trim($data['matricule_agent']);
        $stmt = $pdo->prepare("
            SELECT nom, prenom, emploi, l_fonct, l_entite, l_lieu, direction, direction_centrale
            FROM employes
            WHERE mle = ? OR mle = LPAD(?, 6, '0')
            LIMIT 1
        ");
        $stmt->execute([$mle, $mle]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            // Nom complet
            $nomComplet = trim(($emp['prenom'] ?? '') . ' ' . ($emp['nom'] ?? ''));
            if ($nomComplet) {
                $data['affectation'] = $nomComplet;
            }
            // Fonction et entité
            $data['emploi']             = $emp['emploi']             ?? null;
            $data['fonction']           = $emp['l_fonct']            ?? null;
            $data['entite']             = $emp['l_entite']           ?? null;
            $data['lieu_affectation']   = $emp['l_lieu']             ?? null;
            $data['direction']          = $emp['direction']          ?? null;
            $data['direction_centrale'] = $emp['direction_centrale'] ?? null;
            $data['employe_trouve']     = true;
        } else {
            $data['employe_trouve'] = false;
        }
    } catch (Exception $e) {
        // BDD indisponible — on continue sans enrichissement
        $data['employe_trouve'] = false;
    }
}

// ── Succès ────────────────────────────────────────────────────────────────────
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);