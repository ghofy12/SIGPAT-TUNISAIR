<?php
/**
 * Configuration Système TUNISAIR - Gestion du Patrimoine
 * Fichier: config.php
 */

// ============================================
// CONFIGURATION BASE DE DONNÉES
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'tunisair_patrimoine');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// CONFIGURATION APPLICATION
// ============================================
define('APP_NAME', 'TUNISAIR - Gestion du Patrimoine');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/tunisair/');

// ============================================
// CONFIGURATION UPLOADS
// ============================================
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']);

// ============================================
// CONNEXION PDO
// ============================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// ============================================
// DÉMARRAGE DE LA SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// FONCTIONS D'AUTHENTIFICATION
// ============================================

/**
 * Vérifie si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['email']);
}

/**
 * Vérifie si l'utilisateur a une permission spécifique
 */
function hasPermission($pdo, $moduleCode, $permissionCode) {
    if(!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM role_permissions rp
            JOIN modules m ON rp.module_id = m.id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? 
            AND m.code = ? 
            AND p.code = ?
        ");
        $stmt->execute([$_SESSION['role_id'], $moduleCode, $permissionCode]);
        return $stmt->fetchColumn() > 0;
    } catch(PDOException $e) {
        return false;
    }
}

/**
 * Obtenir toutes les permissions d'un rôle organisées par module
 */
function getModulePermissions($pdo, $roleId) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.code as module_code, m.nom as module_nom,
                   GROUP_CONCAT(p.code) as permissions
            FROM role_permissions rp
            JOIN modules m ON rp.module_id = m.id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ?
            GROUP BY m.id, m.code, m.nom
            ORDER BY m.ordre, m.nom
        ");
        $stmt->execute([$roleId]);
        
        $result = [];
        while($row = $stmt->fetch()) {
            $result[$row['module_code']] = explode(',', $row['permissions']);
        }
        return $result;
    } catch(PDOException $e) {
        return [];
    }
}

/**
 * Obtenir les modules accessibles pour un utilisateur
 */
function getAccessibleModules($pdo, $roleId) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id, m.nom, m.code, m.icone, m.description
            FROM modules m
            JOIN role_permissions rp ON m.id = rp.module_id
            WHERE rp.role_id = ? AND m.actif = 1
            ORDER BY m.ordre, m.nom
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}

/**
 * Redirection
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Vérifier et bloquer l'accès si pas de permission
 */
function requirePermission($pdo, $moduleCode, $permissionCode, $redirectUrl = 'dashboard.php') {
    if(!hasPermission($pdo, $moduleCode, $permissionCode)) {
        $_SESSION['error'] = "Vous n'avez pas la permission d'effectuer cette action";
        redirect($redirectUrl);
    }
}

/**
 * Vérifier si l'utilisateur est admin
 */
function isAdmin() {
    return isset($_SESSION['role_niveau']) && $_SESSION['role_niveau'] >= 80;
}

// ============================================
// FONCTIONS DE LOGS
// ============================================

/**
 * Enregistrer une activité dans les logs
 */
function logActivity($pdo, $userId, $action, $module, $referenceId = null, $details = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO logs_activite 
            (user_id, action, module, reference_id, details, adresse_ip, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $action, $module, $referenceId, $details, $ip, $userAgent]);
        return true;
    } catch(PDOException $e) {
        error_log("Erreur log : " . $e->getMessage());
        return false;
    }
}

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Nettoyer les données d'entrée
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Formater une date
 */
function formatDate($date, $format = 'd/m/Y') {
    if(empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Formater un montant
 */
function formatMontant($montant, $devise = 'TND') {
    return number_format($montant, 2, ',', ' ') . ' ' . $devise;
}

/**
 * Générer une référence unique
 */
function generateReference($prefix, $pdo, $table, $column = 'reference') {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING($column, -3) AS UNSIGNED)) as max_num FROM $table WHERE $column LIKE ?");
    $stmt->execute(["$prefix-$year-%"]);
    $result = $stmt->fetch();
    $nextNum = ($result['max_num'] ?? 0) + 1;
    return sprintf("%s-%s-%03d", $prefix, $year, $nextNum);
}

/**
 * Obtenir les statistiques globales
 */
function getStatistiquesGlobales($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM v_statistiques_globales");
        return $stmt->fetch();
    } catch(PDOException $e) {
        return [];
    }
}

// ============================================
// GESTION DES MESSAGES FLASH
// ============================================

/**
 * Définir un message flash
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Obtenir et effacer le message flash
 */
function getFlashMessage() {
    if(isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

// ============================================
// GESTION DES FICHIERS
// ============================================

/**
 * Upload de fichier
 */
function uploadFile($file, $module, $referenceId) {
    if(!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Erreur lors de l\'upload'];
    }
    
    // Vérifier la taille
    if($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'Fichier trop volumineux'];
    }
    
    // Vérifier l'extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if(!in_array($extension, ALLOWED_EXTENSIONS)) {
        return ['success' => false, 'error' => 'Type de fichier non autorisé'];
    }
    
    // Créer le répertoire si nécessaire
    $uploadPath = UPLOAD_DIR . $module . '/';
    if(!is_dir($uploadPath)) {
        mkdir($uploadPath, 0755, true);
    }
    
    // Générer un nom unique
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $uploadPath . $filename;
    
    // Déplacer le fichier
    if(move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'original' => $file['name']
        ];
    }
    
    return ['success' => false, 'error' => 'Erreur lors de la sauvegarde'];
}

// ============================================
// FIN DE CONFIG.PHP
// ============================================
?>