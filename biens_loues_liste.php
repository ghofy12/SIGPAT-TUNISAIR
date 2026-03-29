<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';

$zone = $_GET['zone'] ?? 'Tunisie';
$isTN = $zone === 'Tunisie';

// ── UPLOAD PDF ──
if(isset($_POST['action']) && $_POST['action']==='upload_pdf' && !empty($_POST['id'])){
    $pdfFields = ['contrat_pdf','avenant_pdf','facture_pdf','bon_commande_pdf'];
    $uploaded = false;
    foreach($pdfFields as $pf){
        if(!empty($_FILES[$pf]['tmp_name']) && $_FILES[$pf]['error'] === UPLOAD_ERR_OK){
            $dir = 'documents/';
            if(!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = $pf.'_bl_'.(int)$_POST['id'].'_'.time().'.pdf';
            if(move_uploaded_file($_FILES[$pf]['tmp_name'], $dir.$fname)){
                $old = $pdo->prepare("SELECT $pf FROM biens_loues_tunisair WHERE id=?");
                $old->execute([(int)$_POST['id']]);
                $oldRow = $old->fetch();
                if($oldRow && !empty($oldRow[$pf]) && file_exists($oldRow[$pf])){
                    @unlink($oldRow[$pf]);
                }
                $pdo->prepare("UPDATE biens_loues_tunisair SET $pf=? WHERE id=?")->execute([$dir.$fname, (int)$_POST['id']]);
                $uploaded = true;
            }
        }
    }
    $status = $uploaded ? 'upload_ok=1' : 'upload_err=1';
    header("Location: ?zone=".urlencode($zone)."&id=".(int)$_POST['id']."&".$status);
    exit;
}

// ── DELETE PDF ──
if(isset($_POST['action']) && $_POST['action']==='delete_pdf' && !empty($_POST['id']) && !empty($_POST['field'])){
    $allowed = ['contrat_pdf','avenant_pdf','facture_pdf','bon_commande_pdf'];
    $field = $_POST['field'];
    if(in_array($field,$allowed)){
        $row = $pdo->prepare("SELECT $field FROM biens_loues_tunisair WHERE id=?");
        $row->execute([(int)$_POST['id']]); $row=$row->fetch();
        if($row && !empty($row[$field]) && file_exists($row[$field])) @unlink($row[$field]);
        $pdo->prepare("UPDATE biens_loues_tunisair SET $field=NULL WHERE id=?")->execute([(int)$_POST['id']]);
    }
    header("Location: ?zone=".urlencode($zone)."&id=".(int)$_POST['id']); exit;
}

// ── FETCH DATA ──
$stmt_all = $pdo->prepare("SELECT * FROM biens_loues_tunisair WHERE localisation=? ORDER BY adresse ASC");
$stmt_all->execute([$zone]); $biens = $stmt_all->fetchAll();

$total=count($biens); $actifs=0; $loues=0; $superficie_total=0; $loyer_total=0;
foreach($biens as $b){
    if(stripos($b['statut']??'','actif')!==false) $actifs++;
    if(!empty($b['locataire'])) $loues++;
    $superficie_total += (float)($b['superficie']??0);
    $loyer_total      += (float)($b['loyer_mensuel']??0);
}

$selectedId = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Biens Loués — <?=htmlspecialchars($zone)?> · TUNISAIR</title>
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
  --accent:<?=$isTN?'var(--red)':'var(--navy-mid)'?>;
  --accent-dark:<?=$isTN?'var(--red-dark)':'var(--navy)'?>;
  --accent-glow:<?=$isTN?'rgba(200,16,46,.18)':'rgba(29,78,216,.16)'?>;
  --green:#059669;--orange:#D97706;
  --sidebar-w:270px;
}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);overflow:hidden;}

/* ── NAVBAR ── */
.navbar{
  background:var(--white);border-bottom:3px solid var(--red);
  box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;
  padding:0 24px;display:flex;align-items:center;justify-content:space-between;
  position:fixed;top:0;left:0;right:0;z-index:200;
}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:16px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:7px 18px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;transition:background .2s;}
.btn-deconnexion:hover{background:var(--red-dark);}

/* ── LAYOUT ── */
.layout{display:flex;height:calc(100vh - 64px);margin-top:64px;}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar-w);flex-shrink:0;
  background:var(--white);border-right:1px solid var(--rule);
  display:flex;flex-direction:column;overflow:hidden;
}
.sidebar-top{padding:16px 14px;border-bottom:1px solid var(--rule);flex-shrink:0;}
.btn-retour{
  display:flex;align-items:center;gap:7px;padding:8px 12px;
  border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);
  color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;
  transition:all .18s;width:100%;margin-bottom:10px;cursor:pointer;
}
.btn-retour:hover{background:var(--white);border-color:var(--accent);color:var(--accent);}
.btn-add{
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;padding:10px 14px;border-radius:10px;
  background:var(--accent);color:white;border:none;cursor:pointer;
  font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;
  box-shadow:0 3px 12px var(--accent-glow);transition:opacity .2s,transform .15s;
}
.btn-add:hover{opacity:.9;transform:translateY(-1px);}

.sidebar-search{padding:10px 14px;border-bottom:1px solid var(--rule);flex-shrink:0;}
.sidebar-search-input{
  width:100%;padding:8px 12px 8px 32px;border-radius:8px;
  border:1.5px solid var(--rule);background:var(--bg);
  font-family:'DM Sans',sans-serif;font-size:12px;color:var(--ink);
  outline:none;transition:border-color .2s;position:relative;
}
.sidebar-search-input:focus{border-color:var(--accent);}
.sidebar-search-wrap{position:relative;}
.sidebar-search-icon{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}

.sidebar-label{
  padding:10px 16px 6px;font-size:10px;font-weight:700;
  letter-spacing:.14em;text-transform:uppercase;color:var(--muted);flex-shrink:0;
}
.sidebar-list{flex:1;overflow-y:auto;padding:4px 10px 10px;}
.sidebar-list::-webkit-scrollbar{width:4px;}
.sidebar-list::-webkit-scrollbar-track{background:transparent;}
.sidebar-list::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:2px;}

.bien-item{
  display:flex;align-items:center;gap:10px;padding:10px 10px;
  border-radius:10px;cursor:pointer;transition:background .15s;
  margin-bottom:3px;
}
.bien-item:hover{background:var(--bg);}
.bien-item.active{background:linear-gradient(135deg,var(--accent-dark),var(--accent));box-shadow:0 3px 12px var(--accent-glow);}
.bien-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#D1D5DB;}
.bien-item.active .bien-dot{background:rgba(255,255,255,.6);}
.bien-item-body{flex:1;min-width:0;}
.bien-adresse{font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bien-item.active .bien-adresse{color:white;}
.bien-meta{font-size:10px;color:var(--muted);margin-top:2px;}
.bien-item.active .bien-meta{color:rgba(255,255,255,.7);}
.bien-num{font-size:10px;font-weight:700;color:var(--muted);flex-shrink:0;width:18px;text-align:right;}
.bien-item.active .bien-num{color:rgba(255,255,255,.6);}
.bien-status-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;margin-left:2px;}

/* ── MAIN ── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;}

/* ── STATS BAR ── */
.stats-bar{
  display:flex;gap:12px;padding:16px 20px;
  background:var(--white);border-bottom:1px solid var(--rule);flex-shrink:0;
}
.stat-card{flex:1;background:var(--bg);border-radius:12px;padding:14px 16px;border-left:3px solid var(--accent);}
.stat-card.green{border-left-color:var(--green);}
.stat-card.orange{border-left-color:var(--orange);}
.stat-label{font-size:9px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.stat-value{font-size:22px;font-weight:700;color:var(--ink);}
.stat-value.accent{color:var(--accent);}
.stat-sub{font-size:10px;color:var(--muted);margin-top:3px;}

/* ── CONTENT AREA ── */
.content-area{flex:1;overflow-y:auto;padding:24px;}
.content-area::-webkit-scrollbar{width:6px;}
.content-area::-webkit-scrollbar-track{background:transparent;}
.content-area::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:3px;}

/* ── EMPTY STATE ── */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--muted);text-align:center;animation:fadeIn .3s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
.empty-icon{width:80px;height:80px;border-radius:20px;background:var(--white);display:flex;align-items:center;justify-content:center;border:2px dashed #D1D5DB;margin-bottom:18px;}
.empty-state h3{font-size:17px;font-weight:700;color:var(--ink);margin-bottom:7px;}
.empty-state p{font-size:13px;max-width:280px;line-height:1.6;}

/* ── DETAIL PANEL ── */
.detail{animation:slideIn .28s cubic-bezier(.22,.68,0,1.1);}
@keyframes slideIn{from{opacity:0;transform:translateX(14px);}to{opacity:1;transform:none;}}

.detail-header{background:linear-gradient(135deg,var(--accent-dark),var(--accent));border-radius:16px;padding:22px 24px;margin-bottom:18px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;}
.detail-header-info{flex:1;}
.detail-badge-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.detail-zone-tag{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.7);}
.detail-adresse{font-size:18px;font-weight:700;color:white;line-height:1.35;}
.detail-ref{font-size:12px;color:rgba(255,255,255,.65);margin-top:5px;}
.detail-header-actions{display:flex;gap:8px;flex-shrink:0;}
.hbtn{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;transition:all .15s;}
.hbtn-edit{background:rgba(255,255,255,.2);color:white;border:1.5px solid rgba(255,255,255,.3);}
.hbtn-edit:hover{background:rgba(255,255,255,.3);}
.hbtn-del{background:rgba(0,0,0,.2);color:white;border:1.5px solid rgba(0,0,0,.15);}
.hbtn-del:hover{background:rgba(0,0,0,.3);}

.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;}
.detail-section{font-size:9px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--accent);margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid var(--accent);display:block;}
.d-card{background:var(--white);border-radius:12px;border:1px solid var(--rule);padding:16px 18px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.d-card.full{grid-column:1/-1;}
.d-card.hl{border-left:3px solid var(--accent);}
.d-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.d-field{padding:10px 12px;background:var(--bg);border-radius:8px;border:1px solid var(--rule);}
.d-field.hl-field{border-left:3px solid var(--accent);}
.d-label{font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.d-value{font-size:13px;font-weight:600;color:var(--ink);}
.d-value.big{font-size:16px;color:var(--accent);}
.d-value.green{color:var(--green);}

.statut-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-actif{background:#DCFCE7;color:#15803D;}
.badge-expire{background:#FEE2E2;color:#DC2626;}
.badge-resilie{background:#FEF3C7;color:#92400E;}
.badge-renouvelle{background:#DBEAFE;color:#1D4ED8;}
.badge-negociation{background:#F3E8FF;color:#6D28D9;}
.badge-other{background:#F3F4F6;color:#4B5563;}

.progress-wrap{margin-top:10px;}
.progress-bar{height:6px;background:rgba(0,0,0,.07);border-radius:3px;overflow:hidden;}
.progress-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--accent-dark),var(--accent));}
.progress-txt{font-size:10px;color:var(--muted);margin-top:5px;}

/* ── PDF CARDS ── */
.pdf-row{display:flex;gap:12px;flex-wrap:wrap;}
.pdf-doc-card{background:var(--white);border:1px solid var(--rule);border-radius:12px;padding:14px;width:160px;display:flex;flex-direction:column;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:box-shadow .15s,transform .15s;}
.pdf-doc-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-1px);}
.pdf-doc-card-empty{opacity:.65;}
.pdf-doc-icon{width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,var(--red-dark),var(--red));display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pdf-doc-icon-empty{background:var(--bg);border:1.5px dashed #D1D5DB;}
.pdf-doc-info{flex:1;}
.pdf-doc-title{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:3px;}
.pdf-doc-name{font-size:10px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;}
.pdf-doc-actions{display:flex;flex-direction:column;gap:5px;}
.pdf-doc-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:6px 10px;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;border:1.5px solid var(--rule);background:var(--bg);color:var(--ink);font-family:'DM Sans',sans-serif;text-decoration:none;transition:background .14s,transform .12s;}
.pdf-doc-btn:hover{background:#E5E7EB;transform:translateY(-1px);}
.pdf-doc-btn-replace{background:var(--red);color:white;border-color:var(--red);}
.pdf-doc-btn-replace:hover{background:var(--red-dark);}
.pdf-doc-btn-upload{background:var(--accent);color:white;border-color:var(--accent);}
.pdf-doc-btn-upload:hover{opacity:.9;}

/* ── PDF CONTRACT FIELD BUTTON ── */
.contrat-pdf-btn{
  display:inline-flex;align-items:center;gap:7px;padding:7px 14px;
  border-radius:8px;background:linear-gradient(135deg,var(--red-dark),var(--red));
  color:white;font-size:11px;font-weight:600;cursor:pointer;
  border:none;font-family:'DM Sans',sans-serif;text-decoration:none;
  box-shadow:0 2px 8px rgba(200,16,46,.25);transition:opacity .15s,transform .12s;
}
.contrat-pdf-btn:hover{opacity:.88;transform:translateY(-1px);}
.contrat-pdf-empty{
  display:inline-flex;align-items:center;gap:7px;padding:7px 14px;
  border-radius:8px;background:var(--bg);color:var(--muted);
  font-size:11px;font-weight:600;cursor:pointer;
  border:1.5px dashed #D1D5DB;font-family:'DM Sans',sans-serif;
  transition:all .15s;
}
.contrat-pdf-empty:hover{border-color:var(--accent);color:var(--accent);background:white;}

/* ── MAP IFRAME ── */
.map-iframe-wrap{
  width:100%;border-radius:10px;overflow:hidden;
  border:1px solid var(--rule);box-shadow:0 2px 8px rgba(0,0,0,.06);
  position:relative;
}
.map-iframe-wrap iframe{
  display:block;width:100%;height:240px;border:none;
}
.map-open-link{
  display:inline-flex;align-items:center;gap:6px;margin-top:10px;
  padding:7px 14px;border-radius:8px;background:linear-gradient(135deg,#1a73e8,#0d47a1);
  color:white;font-size:11px;font-weight:600;text-decoration:none;
  box-shadow:0 2px 8px rgba(26,115,232,.3);transition:opacity .15s,transform .12s;
}
.map-open-link:hover{opacity:.9;transform:translateY(-1px);}

/* Upload spinner overlay */
.upload-overlay{display:none;position:fixed;inset:0;z-index:800;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;}
.upload-overlay.show{display:flex;}
.upload-box{background:white;border-radius:16px;padding:32px 40px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.18);}
.upload-spinner{width:40px;height:40px;border:3px solid var(--rule);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 16px;}
@keyframes spin{to{transform:rotate(360deg);}}
.upload-box p{font-size:14px;font-weight:600;color:var(--ink);}
.upload-box span{font-size:12px;color:var(--muted);}

.upload-hidden{display:none;}

/* ── MODALS ── */
.modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:24px;}
.modal-bg.open{display:flex;}
.edit-inner{background:var(--bg);border-radius:18px;width:min(780px,95vw);max-height:90vh;overflow-y:auto;padding:32px;box-shadow:0 32px 80px rgba(0,0,0,.18);}
.edit-inner h2{font-size:14px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--ink);margin-bottom:6px;}
.modal-sub{font-size:12px;color:var(--muted);margin-bottom:24px;}
.modal-section{font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--rule);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group.full{grid-column:1/-1;}
.form-label{font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.form-input{padding:10px 14px;border-radius:9px;border:1.5px solid var(--rule);background:white;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow);}
.form-check-group{display:flex;align-items:center;gap:10px;}
.form-check-group input{width:16px;height:16px;accent-color:var(--accent);cursor:pointer;}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;}
.del-inner{background:white;border-radius:16px;padding:32px 36px;width:min(400px,92vw);text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.18);}
.del-inner h3{font-size:14px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:12px;}
.del-inner p{font-size:13.5px;color:var(--muted);margin-bottom:24px;line-height:1.6;}
.del-actions{display:flex;gap:10px;justify-content:center;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:'DM Sans',sans-serif;transition:transform .2s,box-shadow .2s,background .15s;}
.btn:hover{transform:translateY(-2px);}
.btn-primary{background:var(--accent);color:white;box-shadow:0 4px 14px var(--accent-glow);}
.btn-primary:hover{box-shadow:0 8px 22px var(--accent-glow);}
.btn-ghost{background:var(--white);color:var(--ink);border:1.5px solid var(--rule);box-shadow:var(--shadow);}
.btn-ghost:hover{background:var(--bg);}
.btn-danger{background:#FEF2F2;color:#DC2626;border:1.5px solid #FECACA;}
.btn-danger:hover{background:#FEE2E2;}

/* PDF modal */
.pdf-inner{background:white;border-radius:18px;width:min(900px,95vw);height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.22);}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--rule);}
.modal-head-title{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;}
.modal-close{width:32px;height:32px;border-radius:8px;border:1px solid var(--rule);background:var(--bg);cursor:pointer;display:grid;place-items:center;}
.modal-close:hover{background:#E5E7EB;}
.pdf-frame{flex:1;border:none;width:100%;}

/* Toast */
.toast{position:fixed;top:76px;right:24px;z-index:900;border-radius:12px;padding:12px 20px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);animation:toastIn .3s ease;}
.toast.ok{background:#ECFDF5;color:#065F46;border:1.5px solid #A7F3D0;}
.toast.err{background:#FEF2F2;color:#991B1B;border:1.5px solid #FECACA;}
@keyframes toastIn{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:none;}}

@media(max-width:700px){
  .detail-grid{grid-template-columns:1fr;}
  .stats-bar{flex-wrap:wrap;}
  .stat-card{min-width:calc(50% - 6px);}
}
</style>
</head>
<body>

<!-- Upload spinner -->
<div class="upload-overlay" id="uploadOverlay">
  <div class="upload-box">
    <div class="upload-spinner"></div>
    <p>Envoi en cours…</p>
    <span>Veuillez patienter</span>
  </div>
</div>

<nav class="navbar">
  <a href="index.php" class="nav-brand">
    <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
    <span class="nav-brand-text">TUNISAIR — Gestion du Patrimoine</span>
  </a>
  <div class="nav-right">
    <span class="nav-user"><?=htmlspecialchars($username)?></span>
    <a href="logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</nav>

<?php if(isset($_GET['upload_ok'])): ?>
<div class="toast ok" id="toast">
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#059669" stroke-width="1.4"/><path d="M5 8l2.5 2.5L11 5.5" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Document uploadé avec succès !
</div>
<script>setTimeout(()=>document.getElementById('toast')?.remove(),3500);</script>
<?php elseif(isset($_GET['upload_err'])): ?>
<div class="toast err" id="toast">
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#DC2626" stroke-width="1.4"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="#DC2626" stroke-width="1.6" stroke-linecap="round"/></svg>
  Erreur lors de l'upload. Vérifiez le fichier.
</div>
<script>setTimeout(()=>document.getElementById('toast')?.remove(),4000);</script>
<?php endif; ?>

<div class="layout">

  <!-- ══ SIDEBAR ══ -->
  <aside class="sidebar">
    <div class="sidebar-top">
      <a href="biens_loues.php" class="btn-retour">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Retour aux biens fonciers
      </a>
      <button class="btn-add" onclick="document.getElementById('addModal').classList.add('open')">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
        Ajouter un bien
      </button>
    </div>

    <div class="sidebar-search">
      <div class="sidebar-search-wrap">
        <span class="sidebar-search-icon">
          <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 10l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        </span>
        <input type="text" class="sidebar-search-input" id="sideSearch" placeholder="Rechercher…" oninput="filterSidebar(this.value)">
      </div>
    </div>

    <div class="sidebar-label">PORTEFEUILLE (<?=$total?>)</div>

    <div class="sidebar-list" id="sidebarList">
      <?php foreach($biens as $i=>$b):
        $s=strtolower($b['statut']??'');
        $dotColor = str_contains($s,'actif')?'#22C55E':(str_contains($s,'expir')?'#EF4444':'#F59E0B');
        $bJson = htmlspecialchars(json_encode($b), ENT_QUOTES);
      ?>
      <div class="bien-item <?=$selectedId===$b['id']?'active':''?>"
           data-id="<?=$b['id']?>"
           data-adresse="<?=htmlspecialchars(strtolower($b['adresse']??''))?>"
           onclick="selectBien(<?=$bJson?>)">
        <div class="bien-dot"></div>
        <div class="bien-item-body">
          <div class="bien-adresse"><?=htmlspecialchars($b['adresse']??'—')?></div>
          <div class="bien-meta"><?=htmlspecialchars($b['reference']??'')?>
            <?php if(!empty($b['loyer_mensuel'])): ?>· <?=number_format((float)$b['loyer_mensuel'],0,',',' ')?> <?=htmlspecialchars($b['devise']??'TND')?>/m<?php endif; ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
          <div class="bien-num"><?=str_pad($i+1,2,'0',STR_PAD_LEFT)?></div>
          <div class="bien-status-dot" style="background:<?=$dotColor?>;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- ══ MAIN ══ -->
  <main class="main">

    <!-- Stats -->
    <div class="stats-bar">
      <div class="stat-card">
        <div class="stat-label">Total Biens</div>
        <div class="stat-value accent"><?=$total?></div>
        <div class="stat-sub"><?=$zone?></div>
      </div>
      <div class="stat-card green">
        <div class="stat-label">Actifs</div>
        <div class="stat-value"><?=$actifs?></div>
        <div class="stat-sub">biens actifs</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Loués</div>
        <div class="stat-value"><?=$loues?></div>
        <div class="stat-sub">en location</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Superficie</div>
        <div class="stat-value"><?=number_format($superficie_total,0,',',' ')?></div>
        <div class="stat-sub">m² total</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Loyer / Mois</div>
        <div class="stat-value accent"><?=number_format($loyer_total,0,',',' ')?></div>
        <div class="stat-sub">TND</div>
      </div>
    </div>

    <!-- Content -->
    <div class="content-area" id="contentArea">

      <div class="empty-state" id="emptyState" style="<?=$selectedId?'display:none':''?>">
        <div class="empty-icon">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="#9CA3AF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><polyline points="9 22 9 12 15 12 15 22" stroke="#9CA3AF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h3>Sélectionnez un bien</h3>
        <p>Choisissez une adresse dans la liste à gauche pour afficher sa fiche.</p>
      </div>

      <div class="detail" id="detailPanel" style="display:none;">

        <div class="detail-header">
          <div class="detail-header-info">
            <div class="detail-badge-row">
              <span class="detail-zone-tag"><?=htmlspecialchars($zone)?></span>
              <span class="statut-badge" id="dp_statut_badge">—</span>
            </div>
            <div class="detail-adresse" id="dp_adresse">—</div>
            <div class="detail-ref" id="dp_ref"></div>
          </div>
          <div class="detail-header-actions">
            <button class="hbtn hbtn-edit" id="dp_edit_btn">
              <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 0 1 2 2L5 13H3v-2L11.5 2.5z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Modifier
            </button>
            <button class="hbtn hbtn-del" id="dp_del_btn">
              <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M6 7v5M10 7v5M3 4l1 9a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1l1-9" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Supprimer
            </button>
          </div>
        </div>

        <div class="detail-grid">

          <div class="d-card">
            <span class="detail-section">Identification</span>
            <div class="d-field-grid">
              <div class="d-field"><div class="d-label">Bailleur</div><div class="d-value" id="dp_bailleur">—</div></div>
              <div class="d-field"><div class="d-label">Entité</div><div class="d-value" id="dp_entite">—</div></div>
              <div class="d-field"><div class="d-label">Type de bien</div><div class="d-value" id="dp_type_bien">—</div></div>
              <div class="d-field"><div class="d-label">Superficie</div><div class="d-value" id="dp_superficie">—</div></div>
            </div>
          </div>

          <div class="d-card hl">
            <span class="detail-section">Finances</span>
            <div class="d-field-grid">
              <div class="d-field hl-field"><div class="d-label">Loyer mensuel</div><div class="d-value big" id="dp_loyer">—</div></div>
              <div class="d-field hl-field"><div class="d-label">Budget annuel</div><div class="d-value big" id="dp_budget">—</div></div>
              <div class="d-field"><div class="d-label">Devise</div><div class="d-value" id="dp_devise">—</div></div>
              <div class="d-field"><div class="d-label">Renouvellement</div><div class="d-value green" id="dp_renouvellement">—</div></div>
            </div>
          </div>

          <div class="d-card full">
            <span class="detail-section">Contrat</span>
            <div class="d-field-grid">
              <!-- PDF CONTRAT remplace N° Contrat -->
              <div class="d-field"><div class="d-label">Contrat PDF</div><div class="d-value" id="dp_contrat_pdf_btn">—</div></div>
              <div class="d-field"><div class="d-label">Préavis résiliation</div><div class="d-value" id="dp_preavis">—</div></div>
              <div class="d-field"><div class="d-label">Date début</div><div class="d-value" id="dp_debut">—</div></div>
              <div class="d-field"><div class="d-label">Date fin</div><div class="d-value" id="dp_fin">—</div></div>
            </div>
            <div class="progress-wrap">
              <div class="progress-bar"><div class="progress-fill" id="dp_progress" style="width:0%"></div></div>
              <div class="progress-txt" id="dp_progress_txt"></div>
            </div>
          </div>

          <div class="d-card">
            <span class="detail-section">Contacts</span>
            <div class="d-field-grid">
              <div class="d-field"><div class="d-label">Responsable interne</div><div class="d-value" id="dp_resp">—</div></div>
              <div class="d-field"><div class="d-label">Contact bailleur</div><div class="d-value" id="dp_contact">—</div></div>
              <div class="d-field" style="grid-column:1/-1"><div class="d-label">Tél. bailleur</div><div class="d-value" id="dp_tel">—</div></div>
            </div>
          </div>

          <!-- MAP — affichage direct iframe -->
          <div class="d-card full">
            <span class="detail-section">Localisation</span>
            <div id="dp_map_content"></div>
          </div>

          <div class="d-card full">
            <span class="detail-section">Documents PDF</span>
            <div class="pdf-row" id="dp_docs"></div>
            <div id="dp_upload_forms"></div>
          </div>

        </div>

      </div>
    </div>
  </main>
</div>

<!-- PDF PREVIEW MODAL -->
<div class="modal-bg" id="pdfModal">
  <div class="pdf-inner">
    <div class="modal-head">
      <span class="modal-head-title" id="pdfTitle">Document</span>
      <button class="modal-close" onclick="closePDF()"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>
    </div>
    <iframe class="pdf-frame" id="pdfFrame" src=""></iframe>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-bg" id="addModal">
  <div class="edit-inner">
    <h2>Nouveau bien loué — <?=htmlspecialchars($zone)?></h2>
    <p class="modal-sub">Seul le champ Adresse est obligatoire.</p>
    <form method="post" action="?zone=<?=urlencode($zone)?>">
      <input type="hidden" name="action" value="add">
      <div class="modal-section">Identification</div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Adresse <span style="color:var(--accent)">*</span></label><input class="form-input" type="text" name="adresse" required placeholder="Ex : 45 Rue de la République, Tunis"></div>
        <div class="form-group"><label class="form-label">Référence</label><input class="form-input" type="text" name="reference" placeholder="BL-001"></div>
        <div class="form-group"><label class="form-label">Bailleur</label><input class="form-input" type="text" name="bailleur"></div>
        <div class="form-group"><label class="form-label">Entité TUNISAIR</label><input class="form-input" type="text" name="entite"></div>
        <div class="form-group"><label class="form-label">Type de bien</label><input class="form-input" type="text" name="type_bien" placeholder="Bureau, Entrepôt…"></div>
        <div class="form-group"><label class="form-label">Superficie (m²)</label><input class="form-input" type="number" step="0.01" name="superficie"></div>
        <div class="form-group"><label class="form-label">Statut</label>
          <select class="form-input" name="statut">
            <?php foreach(['Actif','Expiré','Résilié','Renouvellé','En négociation'] as $o): ?><option><?=$o?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-section">Contrat</div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">N° Contrat</label><input class="form-input" type="text" name="num_contrat"></div>
        <div class="form-group"><label class="form-label">Date début</label><input class="form-input" type="date" name="date_debut"></div>
        <div class="form-group"><label class="form-label">Date fin</label><input class="form-input" type="date" name="date_fin"></div>
        <div class="form-group"><label class="form-label">Loyer mensuel</label><input class="form-input" type="number" step="0.01" name="loyer_mensuel"></div>
        <div class="form-group"><label class="form-label">Budget annuel</label><input class="form-input" type="number" step="0.01" name="budget_annuel"></div>
        <div class="form-group"><label class="form-label">Devise</label>
          <select class="form-input" name="devise">
            <?php foreach(['TND','EUR','USD','GBP','MAD'] as $d): ?><option><?=$d?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Préavis résiliation (jours)</label><input class="form-input" type="number" name="preavis_resiliation"></div>
        <div class="form-group form-check-group" style="padding-top:22px;">
          <input type="checkbox" name="renouvellement_auto" value="1" id="ren_add">
          <label for="ren_add" class="form-label" style="cursor:pointer;">Renouvellement auto.</label>
        </div>
        <div class="form-group"><label class="form-label">Responsable interne</label><input class="form-input" type="text" name="resp_interne"></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addModal').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>Créer
        </button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-bg" id="editModal">
  <div class="edit-inner">
    <h2>Modifier le bien loué</h2>
    <p class="modal-sub" id="editSub"></p>
    <form method="post" action="?zone=<?=urlencode($zone)?>" id="editForm">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-section">Identification</div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Adresse</label><input class="form-input" type="text" name="adresse" id="edit_adresse"></div>
        <div class="form-group"><label class="form-label">Référence</label><input class="form-input" type="text" name="reference" id="edit_reference"></div>
        <div class="form-group"><label class="form-label">Bailleur</label><input class="form-input" type="text" name="bailleur" id="edit_bailleur"></div>
        <div class="form-group"><label class="form-label">Entité TUNISAIR</label><input class="form-input" type="text" name="entite" id="edit_entite"></div>
        <div class="form-group"><label class="form-label">Type de bien</label><input class="form-input" type="text" name="type_bien" id="edit_type_bien"></div>
        <div class="form-group"><label class="form-label">Superficie (m²)</label><input class="form-input" type="number" step="0.01" name="superficie" id="edit_superficie"></div>
        <div class="form-group"><label class="form-label">Statut</label>
          <select class="form-input" name="statut" id="edit_statut">
            <?php foreach(['Actif','Expiré','Résilié','Renouvellé','En négociation'] as $o): ?><option><?=$o?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-section">Contrat</div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">N° Contrat</label><input class="form-input" type="text" name="num_contrat" id="edit_num_contrat"></div>
        <div class="form-group"><label class="form-label">Date début</label><input class="form-input" type="date" name="date_debut" id="edit_date_debut"></div>
        <div class="form-group"><label class="form-label">Date fin</label><input class="form-input" type="date" name="date_fin" id="edit_date_fin"></div>
        <div class="form-group"><label class="form-label">Loyer mensuel</label><input class="form-input" type="number" step="0.01" name="loyer_mensuel" id="edit_loyer_mensuel"></div>
        <div class="form-group"><label class="form-label">Budget annuel</label><input class="form-input" type="number" step="0.01" name="budget_annuel" id="edit_budget_annuel"></div>
        <div class="form-group"><label class="form-label">Devise</label>
          <select class="form-input" name="devise" id="edit_devise">
            <?php foreach(['TND','EUR','USD','GBP','MAD'] as $d): ?><option><?=$d?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Préavis résiliation (j)</label><input class="form-input" type="number" name="preavis_resiliation" id="edit_preavis"></div>
        <div class="form-group form-check-group" style="padding-top:22px;">
          <input type="checkbox" name="renouvellement_auto" value="1" id="edit_ren_auto">
          <label for="edit_ren_auto" class="form-label" style="cursor:pointer;">Renouvellement auto.</label>
        </div>
        <div class="form-group"><label class="form-label">Responsable interne</label><input class="form-input" type="text" name="resp_interne" id="edit_resp_interne"></div>
        <div class="form-group"><label class="form-label">Contact bailleur</label><input class="form-input" type="text" name="contact_bailleur" id="edit_contact_bailleur"></div>
        <div class="form-group"><label class="form-label">Tél. bailleur</label><input class="form-input" type="text" name="tel_bailleur" id="edit_tel_bailleur"></div>
        <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-input" name="notes" id="edit_notes" rows="2" style="resize:vertical;"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editModal').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-bg" id="deleteModal">
  <div class="del-inner">
    <h3>Supprimer ce contrat ?</h3>
    <p>Cette action est irréversible.<br><strong id="delName"></strong> sera définitivement supprimé.</p>
    <div class="del-actions">
      <button class="btn btn-ghost" onclick="document.getElementById('deleteModal').classList.remove('open')">Annuler</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="delId">
        <button type="submit" class="btn btn-danger">Supprimer</button>
      </form>
    </div>
  </div>
</div>

<script>
const ZONE = <?=json_encode($zone)?>;
let currentBien = null;

/* ── Close modals on backdrop ── */
document.querySelectorAll('.modal-bg').forEach(el=>{
  el.addEventListener('click',e=>{
    if(e.target===el){
      el.classList.remove('open');
      if(el.id==='pdfModal') document.getElementById('pdfFrame').src='';
    }
  });
});

/* ── Sidebar filter ── */
function filterSidebar(q){
  q = q.toLowerCase().trim();
  document.querySelectorAll('.bien-item').forEach(el=>{
    const addr = el.dataset.adresse || '';
    el.style.display = (!q || addr.includes(q)) ? '' : 'none';
  });
}

/* ── Select bien ── */
function selectBien(b){
  currentBien = b;

  document.querySelectorAll('.bien-item').forEach(el=>{
    el.classList.toggle('active', parseInt(el.dataset.id) === parseInt(b.id));
  });

  document.getElementById('emptyState').style.display = 'none';
  const panel = document.getElementById('detailPanel');
  panel.style.display = 'block';
  panel.classList.remove('detail'); void panel.offsetWidth; panel.classList.add('detail');

  document.getElementById('dp_adresse').textContent = b.adresse || '—';
  document.getElementById('dp_ref').textContent = b.reference ? 'Réf. ' + b.reference : '';

  const badge = document.getElementById('dp_statut_badge');
  const s = (b.statut||'').toLowerCase();
  badge.textContent = b.statut || '—';
  badge.className = 'statut-badge ' + (
    s.includes('actif')       ? 'badge-actif' :
    s.includes('expir')       ? 'badge-expire' :
    s.includes('résili')      ? 'badge-resilie' :
    s.includes('renouvell')   ? 'badge-renouvelle' :
    s.includes('négociation') ? 'badge-negociation' : 'badge-other'
  );

  document.getElementById('dp_bailleur').textContent   = b.bailleur   || '—';
  document.getElementById('dp_entite').textContent     = b.entite     || '—';
  document.getElementById('dp_type_bien').textContent  = b.type_bien  || '—';
  document.getElementById('dp_superficie').textContent = b.superficie ? b.superficie+' m²' : '—';

  const fmt = n => n ? parseFloat(n).toLocaleString('fr-FR') : '—';
  const dev = b.devise || 'TND';
  document.getElementById('dp_loyer').textContent   = fmt(b.loyer_mensuel)  + (b.loyer_mensuel  ? ' '+dev+'/mois' : '');
  document.getElementById('dp_budget').textContent  = fmt(b.budget_annuel) + (b.budget_annuel ? ' '+dev+'/an'   : '');
  document.getElementById('dp_devise').textContent  = dev;
  document.getElementById('dp_renouvellement').textContent = parseInt(b.renouvellement_auto||0) ? '↻ Automatique' : 'Manuel';

  // ── PDF Contrat (remplace N° Contrat) ──
  const contratEl = document.getElementById('dp_contrat_pdf_btn');
  if(b.contrat_pdf){
    const safeUrl = b.contrat_pdf.replace(/'/g,"\\'");
    const safeLbl = ('Contrat — '+(b.adresse||'')).replace(/'/g,"\\'");
    contratEl.innerHTML =
      `<button class="contrat-pdf-btn" onclick="openPDF('${safeUrl}','${safeLbl}')">
         <svg width="13" height="13" viewBox="0 0 24 24" fill="none">
           <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" fill="white" opacity=".9"/>
           <polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.5" fill="none"/>
         </svg>
         Voir le PDF
       </button>
       <a href="${b.contrat_pdf}" download style="margin-left:6px;font-size:11px;color:var(--muted);text-decoration:underline;">Télécharger</a>`;
  } else {
    const inputId = 'inp_'+b.id+'_contrat_pdf';
    contratEl.innerHTML =
      `<label class="contrat-pdf-empty" for="${inputId}">
         <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
           <path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
         </svg>
         Uploader le contrat
       </label>`;
  }

  document.getElementById('dp_preavis').textContent = b.preavis_resiliation ? b.preavis_resiliation+' jours' : '—';

  const fmtDate = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';
  document.getElementById('dp_debut').textContent = fmtDate(b.date_debut);
  document.getElementById('dp_fin').textContent   = fmtDate(b.date_fin);

  let prog = 0, progTxt = '';
  if(b.date_debut && b.date_fin){
    const start = new Date(b.date_debut).getTime();
    const end   = new Date(b.date_fin).getTime();
    const now   = Date.now();
    prog = Math.min(100, Math.max(0, Math.round((now-start)/(end-start)*100)));
    const jr = Math.round((end-now)/86400000);
    progTxt = jr > 0 ? prog+'% écoulé — '+jr+' jours restants' : 'Contrat expiré';
  }
  document.getElementById('dp_progress').style.width = prog+'%';
  document.getElementById('dp_progress_txt').textContent = progTxt;

  document.getElementById('dp_resp').textContent    = b.resp_interne     || '—';
  document.getElementById('dp_contact').textContent = b.contact_bailleur || '—';
  document.getElementById('dp_tel').textContent     = b.tel_bailleur     || '—';

  // ── Documents PDF ──
  buildDocs(b);

  // ── Map — iframe embed direct ──
  const mapEl = document.getElementById('dp_map_content');
  const query = encodeURIComponent((b.adresse||'') + ', ' + (b.localisation||'Tunisie'));
  const embedUrl  = 'https://maps.google.com/maps?q=' + query + '&output=embed&hl=fr';
  const externalUrl = 'https://www.google.com/maps/search/?api=1&query=' + query;
  mapEl.innerHTML =
    `<div class="map-iframe-wrap">
       <iframe src="${embedUrl}" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
     </div>
     <a href="${externalUrl}" target="_blank" class="map-open-link">
       <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" fill="white"/></svg>
       Ouvrir dans Google Maps
     </a>`;

  document.getElementById('dp_edit_btn').onclick = () => openEdit(b);
  document.getElementById('dp_del_btn').onclick  = () => openDelete(b.id, b.adresse);
}

/* ── buildDocs : formulaire upload par bien ── */
function buildDocs(b){
  const docsEl  = document.getElementById('dp_docs');
  const formsEl = document.getElementById('dp_upload_forms');
  docsEl.innerHTML  = '';
  formsEl.innerHTML = '';

  const labelsMap = {
    'facture_pdf'      : 'Facture',
    'bon_commande_pdf' : 'Bon cmd.'
  };

  const frm = document.createElement('form');
  frm.method      = 'post';
  frm.enctype     = 'multipart/form-data';
  frm.id          = 'upform_' + b.id;
  frm.style.display = 'none';
  frm.action      = '?zone=' + encodeURIComponent(ZONE);
  frm.innerHTML   = `<input type="hidden" name="action" value="upload_pdf">
                     <input type="hidden" name="id"     value="${b.id}">`;

  // Inclure aussi contrat_pdf dans le formulaire pour l'upload depuis la fiche contrat
  ['contrat_pdf','facture_pdf','bon_commande_pdf'].forEach(pf => {
    const inp = document.createElement('input');
    inp.type   = 'file';
    inp.name   = pf;
    inp.id     = `inp_${b.id}_${pf}`;
    inp.accept = '.pdf';
    inp.className = 'upload-hidden';
    inp.addEventListener('change', function(){
      if(!this.files || !this.files.length) return;
      document.getElementById('uploadOverlay').classList.add('show');
      frm.submit();
      setTimeout(() => { try{ this.value=''; }catch(e){} }, 200);
    });
    frm.appendChild(inp);
  });

  formsEl.appendChild(frm);

  // Rendu des cartes (Facture + Bon commande seulement — Contrat est dans la section Contrat)
  Object.keys(labelsMap).forEach(pf => {
    const lbl  = labelsMap[pf];
    const card = document.createElement('div');
    const inputId = `inp_${b.id}_${pf}`;

    if(b[pf]){
      const fname    = b[pf].split('/').pop();
      const safeUrl  = b[pf].replace(/'/g,"\\'");
      const safeLbl  = (lbl + ' — ' + (b.adresse||'')).replace(/'/g,"\\'");
      card.className = 'pdf-doc-card';
      card.innerHTML =
        `<div class="pdf-doc-icon">
           <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
             <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" fill="white" opacity=".9"/>
             <polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.5" fill="none"/>
           </svg>
         </div>
         <div class="pdf-doc-info">
           <div class="pdf-doc-title">${lbl}</div>
           <div class="pdf-doc-name">${fname}</div>
         </div>
         <div class="pdf-doc-actions">
           <button class="pdf-doc-btn" onclick="openPDF('${safeUrl}','${safeLbl}')">
             <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3"/></svg>
             Aperçu
           </button>
           <a class="pdf-doc-btn" href="${b[pf]}" download>
             <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
             Télécharger
           </a>
           <label class="pdf-doc-btn pdf-doc-btn-replace" for="${inputId}">
             <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
             Remplacer
           </label>
         </div>`;
    } else {
      card.className = 'pdf-doc-card pdf-doc-card-empty';
      card.innerHTML =
        `<div class="pdf-doc-icon pdf-doc-icon-empty">
           <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
             <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" stroke="#9CA3AF" stroke-width="1.5"/>
             <polyline points="14 2 14 8 20 8" stroke="#9CA3AF" stroke-width="1.5"/>
             <line x1="12" y1="11" x2="12" y2="17" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/>
             <line x1="9" y1="14" x2="15" y2="14" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/>
           </svg>
         </div>
         <div class="pdf-doc-info">
           <div class="pdf-doc-title" style="color:var(--muted)">${lbl}</div>
           <div class="pdf-doc-name">Aucun fichier</div>
         </div>
         <div class="pdf-doc-actions">
           <label class="pdf-doc-btn pdf-doc-btn-upload" for="${inputId}">
             <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
             Uploader
           </label>
         </div>`;
    }
    docsEl.appendChild(card);
  });
}

/* ── PDF preview ── */
function openPDF(url,title){
  document.getElementById('pdfFrame').src=url;
  document.getElementById('pdfTitle').textContent=title;
  document.getElementById('pdfModal').classList.add('open');
}
function closePDF(){
  document.getElementById('pdfModal').classList.remove('open');
  document.getElementById('pdfFrame').src='';
}

/* ── Edit ── */
function openEdit(b){
  document.getElementById('editSub').textContent=b.adresse||'';
  document.getElementById('edit_id').value=b.id||'';
  document.getElementById('edit_adresse').value=b.adresse||'';
  document.getElementById('edit_reference').value=b.reference||'';
  document.getElementById('edit_bailleur').value=b.bailleur||'';
  document.getElementById('edit_entite').value=b.entite||'';
  document.getElementById('edit_type_bien').value=b.type_bien||'';
  document.getElementById('edit_superficie').value=b.superficie||'';
  document.getElementById('edit_statut').value=b.statut||'';
  document.getElementById('edit_num_contrat').value=b.num_contrat||'';
  document.getElementById('edit_date_debut').value=b.date_debut||'';
  document.getElementById('edit_date_fin').value=b.date_fin||'';
  document.getElementById('edit_loyer_mensuel').value=b.loyer_mensuel||'';
  document.getElementById('edit_budget_annuel').value=b.budget_annuel||'';
  document.getElementById('edit_devise').value=b.devise||'TND';
  document.getElementById('edit_preavis').value=b.preavis_resiliation||'';
  document.getElementById('edit_ren_auto').checked=!!parseInt(b.renouvellement_auto||0);
  document.getElementById('edit_resp_interne').value=b.resp_interne||'';
  document.getElementById('edit_contact_bailleur').value=b.contact_bailleur||'';
  document.getElementById('edit_tel_bailleur').value=b.tel_bailleur||'';
  document.getElementById('edit_notes').value=b.notes||'';
  document.getElementById('editModal').classList.add('open');
}

/* ── Delete ── */
function openDelete(id,name){
  document.getElementById('delId').value=id;
  document.getElementById('delName').textContent=name;
  document.getElementById('deleteModal').classList.add('open');
}

<?php if($selectedId && $biens): ?>
<?php foreach($biens as $b): if($b['id']===$selectedId): ?>
selectBien(<?=json_encode($b)?>);
<?php endif; endforeach; ?>
<?php endif; ?>
</script>
</body>
</html>