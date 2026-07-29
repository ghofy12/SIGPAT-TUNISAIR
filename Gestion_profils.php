<?php
/**
 * gestion_profils.php
 * Page unique : Créer un profil → Cocher les modules autorisés → Assigner aux utilisateurs
 */
require_once 'config.php';
if (!isLoggedIn()) { redirect('login.php'); }
if (!isAdmin())    { redirect('dashboard.php'); }

$username = $_SESSION['username'] ?? (($_SESSION['prenom'] ?? '').' '.($_SESSION['nom'] ?? ''));
$flash = null;

// ════════════════════════════════════════════════════════════
// TRAITEMENT DES ACTIONS
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. CRÉER UN NOUVEAU PROFIL ─────────────────────────────
    if ($action === 'create_profil') {
        $code = trim($_POST['code'] ?? '');
        $nom  = trim($_POST['nom']  ?? '');
        $desc = trim($_POST['description'] ?? '');
        $couleur = $_POST['couleur'] ?? '#1D4ED8';
        $niveau  = (int)($_POST['niveau'] ?? 10);

        if (!$code || !$nom) {
            $flash = ['type'=>'error','msg'=>'Le code et le nom du profil sont obligatoires.'];
        } elseif (!preg_match('/^[a-z0-9_]+$/', $code)) {
            $flash = ['type'=>'error','msg'=>'Le code ne peut contenir que des minuscules, chiffres et _.'];
        } else {
            $chk = $pdo->prepare("SELECT id FROM roles WHERE code=?");
            $chk->execute([$code]);
            if ($chk->fetch()) {
                $flash = ['type'=>'error','msg'=>'Ce code de profil existe déjà.'];
            } else {
                $pdo->prepare("INSERT INTO roles (code,nom,description,niveau,couleur,actif) VALUES (?,?,?,?,?,1)")
                    ->execute([$code,$nom,$desc,$niveau,$couleur]);
                $newId = $pdo->lastInsertId();
                logActivity($pdo, $_SESSION['user_id'], "Création profil {$nom}", 'admin_roles', $newId);
                $flash = ['type'=>'success','msg'=>"Profil « {$nom} » créé. Sélectionnez maintenant ses modules ci-dessous."];
                $_GET['profil'] = $newId; // ouvrir directement ce profil
            }
        }
    }

    // ── 2. ENREGISTRER LES MODULES D'UN PROFIL (par ID module) ─
    if ($action === 'save_modules') {
        $roleId   = (int)($_POST['role_id'] ?? 0);
        $moduleIds = $_POST['module_ids'] ?? []; // tableau d'IDs de modules cochés
        $permCode  = $_POST['perm_level'] ?? 'read'; // niveau d'accès simple : read / write / full

        // Vérifier que ce n'est pas le rôle super_admin (protégé)
        $roleChk = $pdo->prepare("SELECT code FROM roles WHERE id=?");
        $roleChk->execute([$roleId]);
        $roleCode = $roleChk->fetchColumn();

        if ($roleCode === 'super_admin') {
            $flash = ['type'=>'error','msg'=>'Les modules du Super Administrateur ne peuvent pas être modifiés.'];
        } elseif ($roleId > 0) {
            // Définir les permissions à appliquer selon le niveau choisi
            $permsByLevel = [
                'read'  => ['read'],
                'write' => ['read','create','update'],
                'full'  => ['read','create','update','delete','export','import'],
            ];
            $codesToApply = $permsByLevel[$permCode] ?? ['read'];

            $permIdsStmt = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
            $permIds = [];
            foreach ($codesToApply as $pc) {
                $permIdsStmt->execute([$pc]);
                $pid = $permIdsStmt->fetchColumn();
                if ($pid) $permIds[] = $pid;
            }

            // Supprimer les anciennes permissions de ce rôle, puis réinsérer
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);

            $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, module_id, permission_id) VALUES (?,?,?)");
            foreach ($moduleIds as $modId) {
                $modId = (int)$modId;
                foreach ($permIds as $pid) {
                    $ins->execute([$roleId, $modId, $pid]);
                }
            }

            logActivity($pdo, $_SESSION['user_id'], "Modules mis à jour pour profil #{$roleId}", 'admin_roles', $roleId);
            $flash = ['type'=>'success','msg'=>'Modules du profil enregistrés. Vous pouvez maintenant affecter ce profil aux utilisateurs.'];
            $_GET['profil'] = $roleId;
        }
    }

    // ── 3. AFFECTER UN PROFIL À UN UTILISATEUR ──────────────────
    if ($action === 'assign_user') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $roleCode = trim($_POST['role_code'] ?? '');

        $chkRole = $pdo->prepare("SELECT id, nom FROM roles WHERE code=? AND actif=1");
        $chkRole->execute([$roleCode]);
        $roleInfo = $chkRole->fetch();

        if ($userId && $roleInfo) {
            $pdo->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$roleCode, $userId]);
            logActivity($pdo, $_SESSION['user_id'], "Profil « {$roleInfo['nom']} » affecté à user #{$userId}", 'admin_users', $userId);
            $flash = ['type'=>'success','msg'=>'Profil affecté à l\'utilisateur avec succès.'];
        } else {
            $flash = ['type'=>'error','msg'=>'Utilisateur ou profil invalide.'];
        }
    }

    // ── 4. AFFECTATION MULTIPLE (plusieurs users → 1 profil) ───
    if ($action === 'assign_bulk') {
        $roleCode = trim($_POST['bulk_role_code'] ?? '');
        $userIds  = $_POST['bulk_user_ids'] ?? [];

        $chkRole = $pdo->prepare("SELECT id, nom FROM roles WHERE code=? AND actif=1");
        $chkRole->execute([$roleCode]);
        $roleInfo = $chkRole->fetch();

        if ($roleInfo && !empty($userIds)) {
            $upd = $pdo->prepare("UPDATE users SET role_id=? WHERE id=?");
            foreach ($userIds as $uid) {
                $upd->execute([$roleCode, (int)$uid]);
                logActivity($pdo, $_SESSION['user_id'], "Profil « {$roleInfo['nom']} » affecté (lot) à user #{$uid}", 'admin_users', (int)$uid);
            }
            $flash = ['type'=>'success','msg'=>count($userIds)." utilisateur(s) affecté(s) au profil « {$roleInfo['nom']} »."];
        } else {
            $flash = ['type'=>'error','msg'=>'Sélectionnez un profil et au moins un utilisateur.'];
        }
    }
}

// ════════════════════════════════════════════════════════════
// CHARGEMENT DES DONNÉES
// ════════════════════════════════════════════════════════════

// Tous les profils (rôles)
$profils = $pdo->query("
    SELECT r.*,
        (SELECT COUNT(*) FROM users u WHERE u.role_id = r.code) AS nb_users,
        (SELECT COUNT(DISTINCT module_id) FROM role_permissions WHERE role_id = r.id) AS nb_modules
    FROM roles r
    ORDER BY r.niveau DESC, r.nom
")->fetchAll();

// Tous les modules disponibles (avec leur ID)
$modules = $pdo->query("SELECT id, code, nom, icone, description FROM modules WHERE actif=1 ORDER BY ordre, nom")->fetchAll();

// Tous les utilisateurs
$tousUsers = $pdo->query("SELECT id, nom, prenom, email, role_id, departement, actif FROM users ORDER BY nom, prenom")->fetchAll();

// Profil sélectionné (pour afficher ses modules cochés)
$selectedProfilId = isset($_GET['profil']) ? (int)$_GET['profil'] : (isset($profils[0]) ? $profils[0]['id'] : 0);

$selectedModuleIds = [];
$currentPermLevel  = 'read';
if ($selectedProfilId) {
    $sp = $pdo->prepare("SELECT module_id, permission_id FROM role_permissions WHERE role_id = ?");
    $sp->execute([$selectedProfilId]);
    $rows = $sp->fetchAll();
    $codesSeen = [];
    foreach ($rows as $row) {
        $selectedModuleIds[$row['module_id']] = true;
    }
    // Déterminer le niveau actuel approximatif
    $permCodes = $pdo->prepare("
        SELECT DISTINCT p.code FROM role_permissions rp
        JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = ?
    ");
    $permCodes->execute([$selectedProfilId]);
    $codesSeen = $permCodes->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('delete', $codesSeen)) $currentPermLevel = 'full';
    elseif (in_array('create', $codesSeen) || in_array('update', $codesSeen)) $currentPermLevel = 'write';
    else $currentPermLevel = 'read';
}

$profilCourant = null;
foreach ($profils as $p) { if ($p['id'] == $selectedProfilId) { $profilCourant = $p; break; } }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profils & Accès — TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --red:#C8102E;--red-dark:#9B0E23;
  --navy:#0F2563;--navy-mid:#1D4ED8;
  --ink:#1A1A18;--muted:#6B7280;
  --bg:#F4F6F9;--white:#fff;
  --rule:rgba(0,0,0,.07);--shadow:0 4px 20px rgba(0,0,0,.07);
  --green:#15803D;--orange:#D97706;
}
html,body{min-height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}

.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:68px;padding:0 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:42px;object-fit:contain;}
.nav-brand-text{font-size:15px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:18px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:8px 20px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;}
.btn-deconnexion:hover{background:var(--red-dark);}

.page{max-width:1280px;margin:0 auto;padding:36px 24px 80px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:18px;}
.breadcrumb a{color:var(--muted);text-decoration:none;}.breadcrumb a:hover{color:var(--red);}
.breadcrumb-sep{opacity:.4;}

.flash{display:flex;align-items:center;gap:10px;padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:13.5px;font-weight:500;animation:fadeUp .3s ease;}
.flash-success{background:#F0FDF4;border:1.5px solid #86EFAC;color:#15803D;}
.flash-error  {background:#FEF2F2;border:1.5px solid #FECACA;color:#B91C1C;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}

.hero{display:flex;align-items:center;gap:14px;margin-bottom:8px;}
.hero-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--red-dark),var(--red));border-radius:13px;display:grid;place-items:center;box-shadow:0 6px 18px rgba(200,16,46,.25);flex-shrink:0;}
.hero h1{font-size:22px;font-weight:700;}
.hero p{font-size:13px;color:var(--muted);margin-top:2px;}

/* STEPS BAR */
.steps-bar{display:flex;gap:0;margin:26px 0;background:var(--white);border-radius:14px;border:1.5px solid var(--rule);overflow:hidden;box-shadow:var(--shadow);}
.step{flex:1;padding:16px 18px;display:flex;align-items:center;gap:12px;position:relative;}
.step:not(:last-child)::after{content:'';position:absolute;right:0;top:20%;bottom:20%;width:1px;background:var(--rule);}
.step-num{width:30px;height:30px;border-radius:50%;background:var(--bg);color:var(--muted);font-size:13px;font-weight:700;display:grid;place-items:center;flex-shrink:0;border:2px solid var(--rule);}
.step.active .step-num{background:var(--red);color:white;border-color:var(--red);}
.step-text{font-size:12px;}
.step-title{font-weight:700;color:var(--ink);}
.step-desc{color:var(--muted);font-size:11px;margin-top:1px;}

/* LAYOUT 3 colonnes */
.layout{display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start;margin-bottom:24px;}
@media(max-width:920px){.layout{grid-template-columns:1fr;}}

/* PANEL */
.panel{background:var(--white);border-radius:16px;border:1.5px solid var(--rule);box-shadow:var(--shadow);overflow:hidden;}
.panel-header{padding:16px 18px;border-bottom:1px solid var(--rule);display:flex;align-items:center;gap:10px;justify-content:space-between;}
.panel-header-left{display:flex;align-items:center;gap:10px;}
.panel-icon{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;flex-shrink:0;}
.panel-title{font-size:13px;font-weight:700;}
.panel-body{padding:18px;}

/* ── COLONNE PROFILS ── */
.profil-item{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:10px;cursor:pointer;text-decoration:none;color:inherit;margin-bottom:6px;border:1.5px solid transparent;transition:all .15s;}
.profil-item:hover{background:var(--bg);}
.profil-item.selected{background:#FEF2F2;border-color:var(--red);}
.pi-badge{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;font-size:11px;font-weight:800;color:white;flex-shrink:0;}
.pi-info{flex:1;min-width:0;}
.pi-name{font-size:13px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pi-meta{font-size:10.5px;color:var(--muted);}
.pi-count{font-size:10px;font-weight:700;color:var(--muted);background:var(--bg);padding:2px 7px;border-radius:10px;}

/* New profil form */
.new-profil-form{border-top:1px solid var(--rule);padding-top:14px;margin-top:10px;}
.fg-mini{margin-bottom:10px;}
.fl-mini{display:block;font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.fi-mini{width:100%;padding:8px 10px;border-radius:7px;border:1.5px solid var(--rule);background:var(--bg);font-family:'DM Sans',sans-serif;font-size:12.5px;outline:none;transition:border-color .2s;}
.fi-mini:focus{border-color:var(--red);}
.btn-add-profil{width:100%;padding:9px;background:var(--navy);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;margin-top:4px;transition:all .2s;}
.btn-add-profil:hover{background:var(--navy-mid);}
.toggle-new-form{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px;border:1.5px dashed var(--rule);border-radius:8px;background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;}
.toggle-new-form:hover{border-color:var(--red);color:var(--red);}
#newProfilForm{display:none;}
#newProfilForm.open{display:block;}

/* ── MODULES GRID (étape 2) ── */
.profil-banner{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:12px;background:var(--bg);margin-bottom:18px;}
.pb-badge{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:14px;font-weight:800;color:white;flex-shrink:0;}
.pb-name{font-size:15px;font-weight:700;}
.pb-id{font-size:11px;color:var(--muted);}

.level-selector{display:flex;gap:8px;margin-bottom:18px;}
.level-opt{flex:1;padding:12px 10px;border-radius:10px;border:1.5px solid var(--rule);background:var(--white);cursor:pointer;text-align:center;transition:all .15s;}
.level-opt:hover{border-color:var(--navy);}
.level-opt.checked{border-color:var(--red);background:#FEF2F2;}
.level-opt input{display:none;}
.lo-icon{font-size:18px;margin-bottom:4px;}
.lo-label{font-size:12px;font-weight:700;}
.lo-desc{font-size:10px;color:var(--muted);margin-top:2px;}

.modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:18px;}
.mod-card{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border-radius:11px;border:1.5px solid var(--rule);cursor:pointer;transition:all .15s;background:var(--white);}
.mod-card:hover{border-color:var(--navy-mid);background:var(--bg);}
.mod-card.checked{border-color:var(--red);background:#FEF2F2;}
.mod-card input{display:none;}
.mc-icon{font-size:18px;flex-shrink:0;}
.mc-info{flex:1;min-width:0;}
.mc-name{font-size:12.5px;font-weight:700;color:var(--ink);}
.mc-id{font-size:9.5px;color:var(--muted);font-family:monospace;margin-top:1px;}
.mc-check{width:18px;height:18px;border-radius:5px;border:1.5px solid var(--rule);flex-shrink:0;display:grid;place-items:center;transition:all .15s;}
.mod-card.checked .mc-check{background:var(--red);border-color:var(--red);}
.mod-card.checked .mc-check::after{content:'✓';color:white;font-size:11px;font-weight:800;}

.sel-actions{display:flex;gap:8px;margin-bottom:14px;}
.btn-sel-mini{padding:6px 12px;border-radius:7px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-sel-mini:hover{border-color:var(--navy);color:var(--navy);}

.btn-save-modules{padding:12px 26px;background:linear-gradient(130deg,var(--red-dark),var(--red));color:white;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:13.5px;font-weight:600;cursor:pointer;box-shadow:0 4px 16px rgba(200,16,46,.25);transition:all .2s;}
.btn-save-modules:hover{transform:translateY(-1px);}

/* ── ÉTAPE 3 : Affectation utilisateurs ── */
.assign-layout{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;}
@media(max-width:920px){.assign-layout{grid-template-columns:1fr;}}

.users-list{max-height:480px;overflow-y:auto;}
.user-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;transition:background .15s;}
.user-row:hover{background:var(--bg);}
.user-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--red);cursor:pointer;flex-shrink:0;}
.ur-avatar{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;font-size:11px;font-weight:700;color:white;flex-shrink:0;}
.ur-info{flex:1;min-width:0;}
.ur-name{font-size:12.5px;font-weight:600;}
.ur-meta{font-size:10.5px;color:var(--muted);}
.ur-role-tag{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;white-space:nowrap;}

.quick-assign-form{display:flex;align-items:center;gap:8px;}
.quick-select{padding:5px 9px;border-radius:7px;border:1.5px solid var(--rule);background:var(--bg);font-size:11.5px;font-family:'DM Sans',sans-serif;cursor:pointer;}

.bulk-panel-sticky{position:sticky;top:90px;}
.bulk-summary{font-size:12px;color:var(--muted);margin-bottom:12px;padding:10px 12px;background:var(--bg);border-radius:9px;}
.bulk-summary strong{color:var(--ink);}
.btn-bulk-assign{width:100%;padding:11px;background:linear-gradient(130deg,var(--navy),var(--navy-mid));color:white;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-bulk-assign:hover{transform:translateY(-1px);}
.btn-bulk-assign:disabled{opacity:.5;cursor:not-allowed;}
</style>
</head>
<body>
<nav class="navbar">
  <a href="dashboard.php" class="nav-brand">
    <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
    <span class="nav-brand-text">TUNISAIR — Gestion du Patrimoine</span>
  </a>
  <div class="nav-right">
    <span class="nav-user"><?=htmlspecialchars($username)?></span>
    <a href="logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</nav>

<div class="page">
  <div class="breadcrumb">
    <a href="dashboard.php">Accueil</a><span class="breadcrumb-sep">›</span>
    <span>Administration</span><span class="breadcrumb-sep">›</span>
    <span>Profils & Accès</span>
  </div>

  <div class="hero">
    <div class="hero-icon">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="2" width="9" height="9" rx="2" stroke="white" stroke-width="1.5"/>
        <rect x="13" y="2" width="9" height="9" rx="2" stroke="white" stroke-width="1.5"/>
        <rect x="2" y="13" width="9" height="9" rx="2" stroke="white" stroke-width="1.5"/>
        <circle cx="17.5" cy="17.5" r="4.5" stroke="white" stroke-width="1.5"/>
      </svg>
    </div>
    <div>
      <h1>Profils & Accès</h1>
      <p>Créez un profil, sélectionnez ses modules autorisés, puis affectez-le aux utilisateurs.</p>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="flash flash-<?=$flash['type']?>"><?=$flash['type']==='success'?'✓':'✕'?> <?=htmlspecialchars($flash['msg'])?></div>
  <?php endif; ?>

  <!-- Barre des 3 étapes -->
  <div class="steps-bar">
    <div class="step active">
      <div class="step-num">1</div>
      <div class="step-text"><div class="step-title">Choisir / créer un profil</div><div class="step-desc">Liste à gauche</div></div>
    </div>
    <div class="step active">
      <div class="step-num">2</div>
      <div class="step-text"><div class="step-title">Cocher ses modules</div><div class="step-desc">ID + niveau d'accès</div></div>
    </div>
    <div class="step active">
      <div class="step-num">3</div>
      <div class="step-text"><div class="step-title">Affecter aux utilisateurs</div><div class="step-desc">En bas de page</div></div>
    </div>
  </div>

  <!-- ═══════════════ ÉTAPE 1 + 2 ═══════════════ -->
  <div class="layout">

    <!-- COLONNE GAUCHE : liste des profils -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-header-left">
          <div class="panel-icon" style="background:linear-gradient(135deg,var(--red-dark),var(--red))">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5" r="2.5" stroke="white" stroke-width="1.3"/><path d="M2 15c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" stroke="white" stroke-width="1.3" stroke-linecap="round"/></svg>
          </div>
          <span class="panel-title">Profils (<?=count($profils)?>)</span>
        </div>
      </div>
      <div class="panel-body">

        <?php foreach ($profils as $p):
          $isSelected = ($p['id'] == $selectedProfilId);
        ?>
        <a href="?profil=<?=$p['id']?>" class="profil-item <?=$isSelected?'selected':''?>">
          <div class="pi-badge" style="background:<?=htmlspecialchars($p['couleur'])?>"><?=strtoupper(substr($p['code'],0,2))?></div>
          <div class="pi-info">
            <div class="pi-name"><?=htmlspecialchars($p['nom'])?></div>
            <div class="pi-meta">ID #<?=$p['id']?> · niv. <?=$p['niveau']?></div>
          </div>
          <span class="pi-count"><?=$p['nb_modules']?> mod.</span>
        </a>
        <?php endforeach; ?>

        <!-- Formulaire nouveau profil -->
        <button type="button" class="toggle-new-form" onclick="toggleNewForm()">+ Créer un nouveau profil</button>

        <div id="newProfilForm" class="new-profil-form">
          <form method="POST">
            <input type="hidden" name="action" value="create_profil">

            <div class="fg-mini">
              <label class="fl-mini">Code (technique)</label>
              <input class="fi-mini" type="text" name="code" placeholder="ex: agent_siege" pattern="[a-z0-9_]+" required>
            </div>
            <div class="fg-mini">
              <label class="fl-mini">Nom du profil</label>
              <input class="fi-mini" type="text" name="nom" placeholder="ex: Agent Siège" required>
            </div>
            <div class="fg-mini">
              <label class="fl-mini">Description</label>
              <input class="fi-mini" type="text" name="description" placeholder="Optionnel">
            </div>
            <div class="fg-mini" style="display:flex;gap:8px;">
              <div style="flex:1;">
                <label class="fl-mini">Couleur</label>
                <input class="fi-mini" type="color" name="couleur" value="#1D4ED8" style="height:34px;padding:3px;">
              </div>
              <div style="flex:2;">
                <label class="fl-mini">Niveau (1-100)</label>
                <input class="fi-mini" type="number" name="niveau" value="20" min="1" max="100">
              </div>
            </div>
            <button type="submit" class="btn-add-profil">Créer le profil</button>
          </form>
        </div>
      </div>
    </div>

    <!-- COLONNE DROITE : modules du profil sélectionné -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-header-left">
          <div class="panel-icon" style="background:linear-gradient(135deg,var(--navy),var(--navy-mid))">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="white" stroke-width="1.3"/><path d="M2 6h12" stroke="white" stroke-width="1.2"/></svg>
          </div>
          <span class="panel-title">Modules autorisés pour ce profil</span>
        </div>
      </div>
      <div class="panel-body">

        <?php if ($profilCourant): ?>
        <div class="profil-banner">
          <div class="pb-badge" style="background:<?=htmlspecialchars($profilCourant['couleur'])?>"><?=strtoupper(substr($profilCourant['code'],0,2))?></div>
          <div>
            <div class="pb-name"><?=htmlspecialchars($profilCourant['nom'])?></div>
            <div class="pb-id">ID profil : <strong>#<?=$profilCourant['id']?></strong> · code : <code><?=htmlspecialchars($profilCourant['code'])?></code></div>
          </div>
        </div>

        <?php if ($profilCourant['code'] === 'super_admin'): ?>
        <div style="background:#FEF9C3;border:1px solid #FDE047;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#78350F;">
          ⚠️ Le Super Administrateur a accès à tous les modules par défaut — non modifiable.
        </div>
        <?php else: ?>

        <form method="POST" id="modulesForm">
          <input type="hidden" name="action" value="save_modules">
          <input type="hidden" name="role_id" value="<?=$profilCourant['id']?>">

          <!-- Niveau d'accès -->
          <label class="fl-mini" style="margin-bottom:8px;display:block;">Niveau d'accès appliqué aux modules cochés</label>
          <div class="level-selector">
            <label class="level-opt <?=$currentPermLevel==='read'?'checked':''?>" onclick="selectLevel('read')">
              <input type="radio" name="perm_level" value="read" <?=$currentPermLevel==='read'?'checked':''?>>
              <div class="lo-icon">👁</div>
              <div class="lo-label">Lecture</div>
              <div class="lo-desc">Consultation seule</div>
            </label>
            <label class="level-opt <?=$currentPermLevel==='write'?'checked':''?>" onclick="selectLevel('write')">
              <input type="radio" name="perm_level" value="write" <?=$currentPermLevel==='write'?'checked':''?>>
              <div class="lo-icon">✏️</div>
              <div class="lo-label">Écriture</div>
              <div class="lo-desc">Lecture + création/modif</div>
            </label>
            <label class="level-opt <?=$currentPermLevel==='full'?'checked':''?>" onclick="selectLevel('full')">
              <input type="radio" name="perm_level" value="full" <?=$currentPermLevel==='full'?'checked':''?>>
              <div class="lo-icon">⚙️</div>
              <div class="lo-label">Total</div>
              <div class="lo-desc">+ suppression, export, import</div>
            </label>
          </div>

          <div class="sel-actions">
            <button type="button" class="btn-sel-mini" onclick="toggleAllModules(true)">✅ Tout cocher</button>
            <button type="button" class="btn-sel-mini" onclick="toggleAllModules(false)">☐ Tout décocher</button>
          </div>

          <!-- Grille des modules avec leur ID -->
          <div class="modules-grid">
            <?php foreach ($modules as $m):
              $checked = isset($selectedModuleIds[$m['id']]);
            ?>
            <label class="mod-card <?=$checked?'checked':''?>" onclick="this.classList.toggle('checked')">
              <input type="checkbox" name="module_ids[]" value="<?=$m['id']?>" class="mod-checkbox" <?=$checked?'checked':''?>>
              <span class="mc-icon"><?=$m['icone']?></span>
              <div class="mc-info">
                <div class="mc-name"><?=htmlspecialchars($m['nom'])?></div>
                <div class="mc-id">ID module : #<?=$m['id']?> — <?=htmlspecialchars($m['code'])?></div>
              </div>
              <div class="mc-check"></div>
            </label>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="btn-save-modules">Enregistrer les modules de ce profil</button>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:40px 0;">Sélectionnez ou créez un profil à gauche.</p>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- ═══════════════ ÉTAPE 3 : Affecter aux utilisateurs ═══════════════ -->
  <div class="panel" style="margin-top:8px;">
    <div class="panel-header">
      <div class="panel-header-left">
        <div class="panel-icon" style="background:linear-gradient(135deg,var(--green),#22C55E)">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="6" cy="5" r="2.3" stroke="white" stroke-width="1.3"/><path d="M1 14c0-2.8 2.2-4.7 5-4.7s5 1.9 5 4.7" stroke="white" stroke-width="1.3" stroke-linecap="round"/><path d="M12 9l1.3 1.3L15.5 8" stroke="white" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <span class="panel-title">Affecter un profil aux utilisateurs</span>
      </div>
    </div>
    <div class="panel-body">
      <div class="assign-layout">

        <!-- Liste utilisateurs avec checkbox + rôle actuel -->
        <form method="POST" id="bulkForm">
          <input type="hidden" name="action" value="assign_bulk">
          <div class="users-list">
            <?php foreach ($tousUsers as $u):
              $initials = strtoupper(substr($u['prenom'],0,1).substr($u['nom'],0,1));
              $roleInfo = null;
              foreach($profils as $p){ if($p['code']===$u['role_id']){$roleInfo=$p;break;} }
              $rc = $roleInfo['couleur'] ?? '#6B7280';
              $rn = $roleInfo['nom'] ?? $u['role_id'];
            ?>
            <div class="user-row">
              <input type="checkbox" name="bulk_user_ids[]" value="<?=$u['id']?>" form="bulkForm">
              <div class="ur-avatar" style="background:<?=$rc?>"><?=htmlspecialchars($initials)?></div>
              <div class="ur-info">
                <div class="ur-name"><?=htmlspecialchars($u['prenom'].' '.$u['nom'])?></div>
                <div class="ur-meta"><?=htmlspecialchars($u['email'])?></div>
              </div>
              <span class="ur-role-tag" style="background:<?=$rc?>18;color:<?=$rc?>"><?=htmlspecialchars($rn)?></span>

              <!-- Affectation rapide individuelle -->
              <form method="POST" class="quick-assign-form">
                <input type="hidden" name="action" value="assign_user">
                <input type="hidden" name="user_id" value="<?=$u['id']?>">
                <select name="role_code" class="quick-select" onchange="this.form.submit()">
                  <?php foreach($profils as $p): ?>
                  <option value="<?=htmlspecialchars($p['code'])?>" <?=$p['code']===$u['role_id']?'selected':''?>>
                    <?=htmlspecialchars($p['nom'])?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </form>
            </div>
            <?php endforeach; ?>
          </div>
        </form>

        <!-- Panneau affectation en lot -->
        <div class="bulk-panel-sticky">
          <div class="bulk-summary">
            Cochez un ou plusieurs utilisateurs à gauche, choisissez le profil cible, puis validez pour les affecter <strong>tous en une fois</strong>.
          </div>
          <div class="fg-mini">
            <label class="fl-mini">Profil à affecter</label>
            <select class="fi-mini" name="bulk_role_code" form="bulkForm">
              <?php foreach($profils as $p): ?>
              <option value="<?=htmlspecialchars($p['code'])?>"><?=htmlspecialchars($p['nom'])?> (#<?=$p['id']?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" form="bulkForm" class="btn-bulk-assign">Affecter aux utilisateurs sélectionnés</button>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
function toggleNewForm() {
    document.getElementById('newProfilForm').classList.toggle('open');
}

function selectLevel(level) {
    document.querySelectorAll('.level-opt').forEach(el => el.classList.remove('checked'));
    document.querySelector(`.level-opt input[value="${level}"]`).closest('.level-opt').classList.add('checked');
}

function toggleAllModules(state) {
    document.querySelectorAll('.mod-checkbox').forEach(cb => {
        cb.checked = state;
        cb.closest('.mod-card').classList.toggle('checked', state);
    });
}

// Garder la synchro visuelle checkbox <-> carte (au cas où clic direct sur input)
document.querySelectorAll('.mod-checkbox').forEach(cb => {
    cb.addEventListener('click', e => e.stopPropagation());
});
</script>
</body>
</html>