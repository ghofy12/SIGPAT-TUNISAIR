<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';

$zone  = $_GET['zone'] ?? 'Nationale';
$isNat = $zone === 'Nationale';

// Colonnes réelles : id, reference, aeroport, type_concession, surface,
// date_debut, date_fin, montant_annuel, contrat_concession_pdf,
// statut, date_creation, date_modification, zone_type

// ── ADD ──
if(isset($_POST['action']) && $_POST['action']==='add'){
    $pdo->prepare("INSERT INTO concessions (reference,aeroport,type_concession,surface,date_debut,date_fin,montant_annuel,statut,zone_type) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $_POST['reference']       ?? null,
            $_POST['aeroport']        ?? null,
            $_POST['type_concession'] ?? null,
            $_POST['surface']         ? (float)$_POST['surface'] : null,
            $_POST['date_debut']      ?? null,
            $_POST['date_fin']        ?? null,
            $_POST['montant_annuel']  ? (float)$_POST['montant_annuel'] : null,
            $_POST['statut']          ?? 'Active',
            $zone,
        ]);
    $newId=$pdo->lastInsertId();
    if(!empty($_FILES['contrat_concession_pdf']['tmp_name'])){
        $dir='documents/'; if(!is_dir($dir)) mkdir($dir,0755,true);
        $fname='cc_'.$newId.'_'.time().'.pdf';
        move_uploaded_file($_FILES['contrat_concession_pdf']['tmp_name'],$dir.$fname);
        $pdo->prepare("UPDATE concessions SET contrat_concession_pdf=? WHERE id=?")->execute([$dir.$fname,$newId]);
    }
    header("Location: ?zone=".urlencode($zone)."&id=".$newId."&add_ok=1"); exit;
}

// ── EDIT ──
if(isset($_POST['action']) && $_POST['action']==='edit' && !empty($_POST['id'])){
    $pdo->prepare("UPDATE concessions SET reference=?,aeroport=?,type_concession=?,surface=?,date_debut=?,date_fin=?,montant_annuel=?,statut=? WHERE id=?")
        ->execute([
            $_POST['reference']       ?? null,
            $_POST['aeroport']        ?? null,
            $_POST['type_concession'] ?? null,
            $_POST['surface']         ? (float)$_POST['surface'] : null,
            $_POST['date_debut']      ?? null,
            $_POST['date_fin']        ?? null,
            $_POST['montant_annuel']  ? (float)$_POST['montant_annuel'] : null,
            $_POST['statut']          ?? 'Active',
            (int)$_POST['id'],
        ]);
    if(!empty($_FILES['contrat_concession_pdf']['tmp_name'])){
        $dir='documents/'; if(!is_dir($dir)) mkdir($dir,0755,true);
        $fname='cc_'.(int)$_POST['id'].'_'.time().'.pdf';
        move_uploaded_file($_FILES['contrat_concession_pdf']['tmp_name'],$dir.$fname);
        $pdo->prepare("UPDATE concessions SET contrat_concession_pdf=? WHERE id=?")->execute([$dir.$fname,(int)$_POST['id']]);
    }
    header("Location: ?zone=".urlencode($zone)."&id=".$_POST['id']); exit;
}

// ── DELETE ──
if(isset($_POST['action']) && $_POST['action']==='delete' && !empty($_POST['id'])){
    $pdo->prepare("DELETE FROM concessions WHERE id=?")->execute([(int)$_POST['id']]);
    header("Location: ?zone=".urlencode($zone)); exit;
}

// ── FETCH LIST ──
$stmt=$pdo->prepare("SELECT * FROM concessions WHERE zone_type=? ORDER BY aeroport");
$stmt->execute([$zone]); $concessions=$stmt->fetchAll();

$total=count($concessions); $actifs=0; $expiring=0; $superficie_total=0; $montant_total=0;
foreach($concessions as $c){
    if(stripos($c['statut']??'','activ')!==false) $actifs++;
    $superficie_total += (float)($c['surface']??0);
    $montant_total    += (float)($c['montant_annuel']??0);
    if(!empty($c['date_fin'])){$d=(strtotime($c['date_fin'])-time())/86400;if($d>=0&&$d<=60)$expiring++;}
}

// ── FETCH DETAIL ──
$conc=null;
$selectedId=(int)($_GET['id']??0);
if($selectedId){
    $s=$pdo->prepare("SELECT * FROM concessions WHERE id=?");
    $s->execute([$selectedId]); $conc=$s->fetch();
}

$statuts_list=['Active','Expirée','Suspendue'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Concessions — <?=htmlspecialchars($zone)?> · TUNISAIR</title>
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
  --accent:<?=$isNat?'var(--red)':'var(--navy-mid)'?>;
  --accent-dark:<?=$isNat?'var(--red-dark)':'var(--navy)'?>;
  --accent-glow:<?=$isNat?'rgba(200,16,46,.18)':'rgba(29,78,216,.16)'?>;
  --green:#059669;--orange:#D97706;
  --sidebar-w:270px;
}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);overflow:hidden;}
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:200;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:16px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:7px 18px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;transition:background .2s;}
.btn-deconnexion:hover{background:var(--red-dark);}
.layout{display:flex;height:calc(100vh - 64px);margin-top:64px;}
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--white);border-right:1px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;}
.sidebar-top{padding:16px 14px;border-bottom:1px solid var(--rule);flex-shrink:0;}
.btn-retour{display:flex;align-items:center;gap:7px;padding:8px 12px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:all .18s;width:100%;margin-bottom:10px;cursor:pointer;}
.btn-retour:hover{background:var(--white);border-color:var(--accent);color:var(--accent);}
.btn-add{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px 14px;border-radius:10px;background:var(--accent);color:white;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;box-shadow:0 3px 12px var(--accent-glow);transition:opacity .2s,transform .15s;}
.btn-add:hover{opacity:.9;transform:translateY(-1px);}
.sidebar-search{padding:10px 14px;border-bottom:1px solid var(--rule);flex-shrink:0;}
.sidebar-search-input{width:100%;padding:8px 12px 8px 32px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);font-family:'DM Sans',sans-serif;font-size:12px;color:var(--ink);outline:none;transition:border-color .2s;}
.sidebar-search-input:focus{border-color:var(--accent);}
.sidebar-search-wrap{position:relative;}
.sidebar-search-icon{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.sidebar-label{padding:10px 16px 6px;font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);flex-shrink:0;}
.sidebar-list{flex:1;overflow-y:auto;padding:4px 10px 10px;}
.sidebar-list::-webkit-scrollbar{width:4px;}
.sidebar-list::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:2px;}
.conc-item{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px;cursor:pointer;transition:background .15s;margin-bottom:3px;}
.conc-item:hover{background:var(--bg);}
.conc-item.active{background:linear-gradient(135deg,var(--accent-dark),var(--accent));box-shadow:0 3px 12px var(--accent-glow);}
.conc-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#D1D5DB;}
.conc-item.active .conc-dot{background:rgba(255,255,255,.6);}
.conc-item-body{flex:1;min-width:0;}
.conc-name{font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.conc-item.active .conc-name{color:white;}
.conc-meta{font-size:10px;color:var(--muted);margin-top:2px;}
.conc-item.active .conc-meta{color:rgba(255,255,255,.7);}
.conc-num{font-size:10px;font-weight:700;color:var(--muted);flex-shrink:0;width:18px;text-align:right;}
.conc-item.active .conc-num{color:rgba(255,255,255,.6);}
.conc-status-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;margin-left:2px;}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.stats-bar{display:flex;gap:12px;padding:16px 20px;background:var(--white);border-bottom:1px solid var(--rule);flex-shrink:0;}
.stat-card{flex:1;background:var(--bg);border-radius:12px;padding:14px 16px;border-left:3px solid var(--accent);}
.stat-card.green{border-left-color:var(--green);}
.stat-card.orange{border-left-color:var(--orange);}
.stat-label{font-size:9px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.stat-value{font-size:22px;font-weight:700;color:var(--ink);}
.stat-value.accent{color:var(--accent);}
.stat-sub{font-size:10px;color:var(--muted);margin-top:3px;}
.content-area{flex:1;overflow-y:auto;padding:24px;}
.content-area::-webkit-scrollbar{width:6px;}
.content-area::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:3px;}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--muted);text-align:center;animation:fadeIn .3s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
.empty-icon{width:80px;height:80px;border-radius:20px;background:var(--white);display:flex;align-items:center;justify-content:center;border:2px dashed #D1D5DB;margin-bottom:18px;}
.empty-state h3{font-size:17px;font-weight:700;color:var(--ink);margin-bottom:7px;}
.empty-state p{font-size:13px;max-width:280px;line-height:1.6;}
.detail{animation:slideIn .28s cubic-bezier(.22,.68,0,1.1);}
@keyframes slideIn{from{opacity:0;transform:translateX(14px);}to{opacity:1;transform:none;}}
.detail-header{background:linear-gradient(135deg,var(--accent-dark),var(--accent));border-radius:16px;padding:22px 24px;margin-bottom:18px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;}
.detail-header-info{flex:1;}
.detail-badge-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.detail-zone-tag{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.7);}
.detail-titre{font-size:18px;font-weight:700;color:white;line-height:1.35;}
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
.statut-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-active{background:#DCFCE7;color:#15803D;}
.badge-expiree{background:#FEE2E2;color:#DC2626;}
.badge-suspendue{background:#FEF3C7;color:#92400E;}
.badge-other{background:#F3F4F6;color:#4B5563;}
.progress-wrap{margin-top:10px;}
.progress-bar{height:6px;background:rgba(0,0,0,.07);border-radius:3px;overflow:hidden;}
.progress-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--accent-dark),var(--accent));}
.progress-txt{font-size:10px;color:var(--muted);margin-top:5px;}
/* PDF */
.pdf-row{display:flex;gap:12px;flex-wrap:wrap;}
.pdf-doc-card{background:var(--white);border:1px solid var(--rule);border-radius:12px;padding:14px;width:160px;display:flex;flex-direction:column;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:box-shadow .15s,transform .15s;}
.pdf-doc-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-1px);}
.pdf-doc-card-empty{opacity:.65;}
.pdf-doc-icon{width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,var(--red-dark),var(--red));display:flex;align-items:center;justify-content:center;}
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
/* Modals */
.modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:24px;}
.modal-bg.open{display:flex;}
.edit-inner{background:var(--bg);border-radius:18px;width:min(720px,96vw);max-height:90vh;overflow-y:auto;padding:32px;box-shadow:0 32px 80px rgba(0,0,0,.18);}
.edit-inner h2{font-size:14px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--ink);margin-bottom:6px;}
.modal-sub{font-size:12px;color:var(--muted);margin-bottom:24px;}
.modal-section{font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--accent);margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--rule);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group.full{grid-column:1/-1;}
.form-label{font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.form-input{padding:10px 14px;border-radius:9px;border:1.5px solid var(--rule);background:white;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow);}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;}
.del-inner{background:white;border-radius:16px;padding:32px 36px;width:min(400px,92vw);text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.18);}
.del-inner h3{font-size:14px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:12px;}
.del-inner p{font-size:13.5px;color:var(--muted);margin-bottom:24px;line-height:1.6;}
.del-actions{display:flex;gap:10px;justify-content:center;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:'DM Sans',sans-serif;transition:transform .2s,background .15s;}
.btn:hover{transform:translateY(-2px);}
.btn-primary{background:var(--accent);color:white;box-shadow:0 4px 14px var(--accent-glow);}
.btn-ghost{background:var(--white);color:var(--ink);border:1.5px solid var(--rule);}
.btn-ghost:hover{background:var(--bg);}
.btn-danger{background:#FEF2F2;color:#DC2626;border:1.5px solid #FECACA;}
.btn-danger:hover{background:#FEE2E2;}
.pdf-inner{background:white;border-radius:18px;width:min(900px,95vw);height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.22);}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--rule);}
.modal-head-title{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;}
.modal-close{width:32px;height:32px;border-radius:8px;border:1px solid var(--rule);background:var(--bg);cursor:pointer;display:grid;place-items:center;}
.modal-close:hover{background:#E5E7EB;}
.pdf-frame{flex:1;border:none;width:100%;}
.toast{position:fixed;top:76px;right:24px;z-index:900;background:#ECFDF5;color:#065F46;border:1.5px solid #A7F3D0;border-radius:12px;padding:12px 20px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);animation:toastIn .3s ease;}
@keyframes toastIn{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:none;}}
@media(max-width:700px){.detail-grid{grid-template-columns:1fr;}.stats-bar{flex-wrap:wrap;}.stat-card{min-width:calc(50% - 6px);}}
</style>
</head>
<body>

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

<?php if(isset($_GET['add_ok'])): ?>
<div class="toast" id="toast">
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#059669" stroke-width="1.4"/><path d="M5 8l2.5 2.5L11 5.5" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Concession ajoutée avec succès !
</div>
<script>setTimeout(()=>document.getElementById('toast')?.remove(),3500);</script>
<?php endif; ?>

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-top">
      <a href="concessions.php" class="btn-retour">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Retour aux concessions
      </a>
      <button class="btn-add" onclick="document.getElementById('addModal').classList.add('open')">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
        Ajouter une concession
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
      <?php foreach($concessions as $i=>$c):
        $s=strtolower($c['statut']??'');
        $dotColor = str_contains($s,'activ')?'#22C55E':(str_contains($s,'expir')?'#EF4444':'#F59E0B');
        $cJson = htmlspecialchars(json_encode($c), ENT_QUOTES);
      ?>
      <div class="conc-item <?=$selectedId===$c['id']?'active':''?>"
           data-id="<?=$c['id']?>"
           data-search="<?=htmlspecialchars(strtolower(($c['aeroport']??'').' '.($c['reference']??'').' '.($c['type_concession']??'')))?>"
           onclick="selectConc(<?=$cJson?>)">
        <div class="conc-dot"></div>
        <div class="conc-item-body">
          <div class="conc-name"><?=htmlspecialchars($c['aeroport']??'—')?></div>
          <div class="conc-meta">
            <?=htmlspecialchars($c['reference']??'')?>
            <?php if(!empty($c['montant_annuel'])): ?>· <?=number_format((float)$c['montant_annuel'],0,',',' ')?> TND/an<?php endif; ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
          <div class="conc-num"><?=str_pad($i+1,2,'0',STR_PAD_LEFT)?></div>
          <div class="conc-status-dot" style="background:<?=$dotColor?>;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="stats-bar">
      <div class="stat-card">
        <div class="stat-label">Total Concessions</div>
        <div class="stat-value accent"><?=$total?></div>
        <div class="stat-sub"><?=$zone?></div>
      </div>
      <div class="stat-card green">
        <div class="stat-label">Actives</div>
        <div class="stat-value"><?=$actifs?></div>
        <div class="stat-sub">concessions actives</div>
      </div>
      <div class="stat-card orange">
        <div class="stat-label">Expirent bientôt</div>
        <div class="stat-value"><?=$expiring?></div>
        <div class="stat-sub">dans 60 jours</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Superficie</div>
        <div class="stat-value"><?=number_format($superficie_total,0,',',' ')?></div>
        <div class="stat-sub">m² total</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Budget Annuel</div>
        <div class="stat-value accent"><?=number_format($montant_total,0,',',' ')?></div>
        <div class="stat-sub">TND</div>
      </div>
    </div>

    <div class="content-area" id="contentArea">

      <div class="empty-state" id="emptyState" style="<?=$selectedId?'display:none':''?>">
        <div class="empty-icon">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><path d="M3 21h18M9 21V7l7-4v18" stroke="#9CA3AF" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 11h7M9 15h7" stroke="#9CA3AF" stroke-width="1.3" stroke-linecap="round"/></svg>
        </div>
        <h3>Sélectionnez une concession</h3>
        <p>Choisissez un aéroport dans la liste à gauche pour afficher sa fiche.</p>
      </div>

      <div class="detail" id="detailPanel" style="display:none;">

        <div class="detail-header">
          <div class="detail-header-info">
            <div class="detail-badge-row">
              <span class="detail-zone-tag"><?=htmlspecialchars($zone)?></span>
              <span class="statut-badge" id="dp_statut_badge">—</span>
            </div>
            <div class="detail-titre" id="dp_aeroport">—</div>
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
            <span class="detail-section">Occupation Domaniale</span>
            <div class="d-field-grid">
              <div class="d-field"><div class="d-label">Type de concession</div><div class="d-value" id="dp_type_concession">—</div></div>
              <div class="d-field"><div class="d-label">Surface</div><div class="d-value" id="dp_surface">—</div></div>
              <div class="d-field" style="grid-column:1/-1"><div class="d-label">Référence</div><div class="d-value" id="dp_reference">—</div></div>
            </div>
          </div>

          <div class="d-card hl">
            <span class="detail-section">Finances</span>
            <div class="d-field-grid">
              <div class="d-field hl-field" style="grid-column:1/-1"><div class="d-label">Montant annuel</div><div class="d-value big" id="dp_annuel">—</div></div>
              <div class="d-field"><div class="d-label">Date début</div><div class="d-value" id="dp_debut">—</div></div>
              <div class="d-field"><div class="d-label">Date fin</div><div class="d-value" id="dp_fin">—</div></div>
            </div>
          </div>

          <div class="d-card full">
            <span class="detail-section">Durée du Contrat</span>
            <div class="progress-wrap" style="margin-top:0;">
              <div class="progress-bar"><div class="progress-fill" id="dp_progress" style="width:0%"></div></div>
              <div class="progress-txt" id="dp_progress_txt"></div>
            </div>
          </div>

          <div class="d-card full">
            <span class="detail-section">Document PDF — Contrat de Concession</span>
            <div class="pdf-row" id="dp_docs"></div>
            <div id="dp_upload_forms"></div>
          </div>

        </div>
      </div>
    </div>
  </main>
</div>

<!-- PDF MODAL -->
<div class="modal-bg" id="pdfModal">
  <div class="pdf-inner">
    <div class="modal-head">
      <span class="modal-head-title" id="pdfTitle">Document</span>
      <button class="modal-close" onclick="closePDF()">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
      </button>
    </div>
    <iframe class="pdf-frame" id="pdfFrame"></iframe>
  </div>
</div>

<?php if($conc): ?>
<!-- EDIT MODAL -->
<div class="modal-bg" id="editModal">
  <div class="edit-inner">
    <h2>Modifier la concession</h2>
    <p class="modal-sub" id="editSub"><?=htmlspecialchars($conc['aeroport']??'')?></p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id" value="<?=$conc['id']?>">
      <div class="modal-section">Informations</div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Aéroport *</label><input class="form-input" type="text" name="aeroport" id="edit_aeroport" value="<?=htmlspecialchars($conc['aeroport']??'')?>" required></div>
        <div class="form-group"><label class="form-label">Référence</label><input class="form-input" type="text" name="reference" id="edit_reference" value="<?=htmlspecialchars($conc['reference']??'')?>"></div>
        <div class="form-group"><label class="form-label">Type de concession</label><input class="form-input" type="text" name="type_concession" id="edit_type_concession" value="<?=htmlspecialchars($conc['type_concession']??'')?>"></div>
        <div class="form-group"><label class="form-label">Surface (m²)</label><input class="form-input" type="number" step="0.01" name="surface" id="edit_surface" value="<?=htmlspecialchars($conc['surface']??'')?>"></div>
        <div class="form-group"><label class="form-label">Statut</label>
          <select class="form-input" name="statut" id="edit_statut">
            <?php foreach($statuts_list as $o): ?><option value="<?=$o?>" <?=$conc['statut']===$o?'selected':''?>><?=$o?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Date de début</label><input class="form-input" type="date" name="date_debut" id="edit_date_debut" value="<?=htmlspecialchars($conc['date_debut']??'')?>"></div>
        <div class="form-group"><label class="form-label">Date de fin</label><input class="form-input" type="date" name="date_fin" id="edit_date_fin" value="<?=htmlspecialchars($conc['date_fin']??'')?>"></div>
        <div class="form-group"><label class="form-label">Montant annuel (TND)</label><input class="form-input" type="number" step="0.01" name="montant_annuel" id="edit_montant_annuel" value="<?=htmlspecialchars($conc['montant_annuel']??'')?>"></div>
        <div class="form-group"><label class="form-label">Contrat PDF</label><input class="form-input" type="file" name="contrat_concession_pdf" accept=".pdf" style="padding:7px 12px;"></div>
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
    <h3>Supprimer cette concession ?</h3>
    <p>Cette action est irréversible.<br><strong><?=htmlspecialchars($conc['aeroport']??'')?></strong> sera définitivement supprimé.</p>
    <div class="del-actions">
      <button class="btn btn-ghost" onclick="document.getElementById('deleteModal').classList.remove('open')">Annuler</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?=$conc['id']?>">
        <button type="submit" class="btn btn-danger">Supprimer</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ADD MODAL -->
<div class="modal-bg" id="addModal">
  <div class="edit-inner">
    <h2>Nouvelle concession — <?=htmlspecialchars($zone)?></h2>
    <p class="modal-sub">Seul le champ Aéroport est obligatoire.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Aéroport *</label><input class="form-input" type="text" name="aeroport" required placeholder="Ex : Tunis-Carthage Terminal A"></div>
        <div class="form-group"><label class="form-label">Référence</label><input class="form-input" type="text" name="reference" placeholder="CC-2025-001"></div>
        <div class="form-group"><label class="form-label">Type de concession</label><input class="form-input" type="text" name="type_concession" placeholder="Bureau, Comptoir…"></div>
        <div class="form-group"><label class="form-label">Surface (m²)</label><input class="form-input" type="number" step="0.01" name="surface"></div>
        <div class="form-group"><label class="form-label">Statut</label>
          <select class="form-input" name="statut">
            <?php foreach($statuts_list as $o): ?><option><?=$o?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Date de début</label><input class="form-input" type="date" name="date_debut"></div>
        <div class="form-group"><label class="form-label">Date de fin</label><input class="form-input" type="date" name="date_fin"></div>
        <div class="form-group"><label class="form-label">Montant annuel (TND)</label><input class="form-input" type="number" step="0.01" name="montant_annuel"></div>
        <div class="form-group"><label class="form-label">Contrat PDF</label><input class="form-input" type="file" name="contrat_concession_pdf" accept=".pdf" style="padding:7px 12px;"></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addModal').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Créer
        </button>
      </div>
    </form>
  </div>
</div>

<script>
let currentConc = null;

function selectConc(c){
  currentConc = c;
  document.querySelectorAll('.conc-item').forEach(el=>{
    el.classList.toggle('active', parseInt(el.dataset.id)===parseInt(c.id));
  });
  document.getElementById('emptyState').style.display='none';
  const panel=document.getElementById('detailPanel');
  panel.style.display='block';
  panel.classList.remove('detail'); void panel.offsetWidth; panel.classList.add('detail');

  document.getElementById('dp_aeroport').textContent = c.aeroport||'—';
  document.getElementById('dp_ref').textContent = c.reference||'';

  const badge=document.getElementById('dp_statut_badge');
  const s=(c.statut||'').toLowerCase();
  badge.textContent=c.statut||'—';
  badge.className='statut-badge ';
  if(s.includes('activ'))       badge.className+='badge-active';
  else if(s.includes('expir'))  badge.className+='badge-expiree';
  else if(s.includes('suspen')) badge.className+='badge-suspendue';
  else                          badge.className+='badge-other';

  document.getElementById('dp_type_concession').textContent = c.type_concession||'—';
  document.getElementById('dp_surface').textContent         = c.surface ? parseFloat(c.surface).toLocaleString('fr-FR')+' m²' : '—';
  document.getElementById('dp_reference').textContent       = c.reference||'—';
  document.getElementById('dp_annuel').textContent          = c.montant_annuel ? parseFloat(c.montant_annuel).toLocaleString('fr-FR',{minimumFractionDigits:0})+' TND' : '—';
  document.getElementById('dp_debut').textContent           = fmtDate(c.date_debut);
  document.getElementById('dp_fin').textContent             = fmtDate(c.date_fin);

  // Progress
  if(c.date_debut && c.date_fin){
    const start=new Date(c.date_debut),end=new Date(c.date_fin),now=new Date();
    const pct=Math.min(100,Math.max(0,Math.round((now-start)/(end-start)*100)));
    document.getElementById('dp_progress').style.width=pct+'%';
    const rem=Math.round((end-now)/86400000);
    document.getElementById('dp_progress_txt').textContent=pct+'% écoulé · '+(rem>0?rem+' jours restants':'Contrat expiré');
  } else {
    document.getElementById('dp_progress').style.width='0%';
    document.getElementById('dp_progress_txt').textContent='Dates non renseignées';
  }

  // PDF
  const docsEl=document.getElementById('dp_docs');
  const formsEl=document.getElementById('dp_upload_forms');
  docsEl.innerHTML=''; formsEl.innerHTML='';
  const pf='contrat_concession_pdf';
  const fname=c[pf]?c[pf].split('/').pop():null;
  const safe=(c.aeroport||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
  const form=document.createElement('form');
  form.method='post';form.enctype='multipart/form-data';form.style.display='none';
  form.innerHTML=`<input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="${c.id}"><input type="hidden" name="aeroport" value="${(c.aeroport||'').replace(/"/g,'&quot;')}"><input type="hidden" name="reference" value="${(c.reference||'').replace(/"/g,'&quot;')}"><input type="hidden" name="type_concession" value="${(c.type_concession||'').replace(/"/g,'&quot;')}"><input type="hidden" name="surface" value="${c.surface||''}"><input type="hidden" name="date_debut" value="${c.date_debut||''}"><input type="hidden" name="date_fin" value="${c.date_fin||''}"><input type="hidden" name="montant_annuel" value="${c.montant_annuel||''}"><input type="hidden" name="statut" value="${c.statut||'Active'}"><input type="file" name="${pf}" id="inp_${c.id}_${pf}" accept=".pdf" onchange="this.closest('form').submit()">`;
  formsEl.appendChild(form);
  const card=document.createElement('div');
  if(fname){
    card.className='pdf-doc-card';
    card.innerHTML=`<div class="pdf-doc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" fill="white" opacity=".9"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.5" fill="none"/></svg></div>
      <div class="pdf-doc-info"><div class="pdf-doc-title">Contrat</div><div class="pdf-doc-name">${fname}</div></div>
      <div class="pdf-doc-actions">
        <button class="pdf-doc-btn" onclick="openPDF('${c[pf]}','Contrat — ${safe}')"><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3"/></svg> Aperçu</button>
        <a class="pdf-doc-btn" href="${c[pf]}" download><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg> Télécharger</a>
        <label class="pdf-doc-btn pdf-doc-btn-replace" for="inp_${c.id}_${pf}" style="cursor:pointer;"><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg> Remplacer</label>
      </div>`;
  } else {
    card.className='pdf-doc-card pdf-doc-card-empty';
    card.innerHTML=`<div class="pdf-doc-icon pdf-doc-icon-empty"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" stroke="#9CA3AF" stroke-width="1.5"/><polyline points="14 2 14 8 20 8" stroke="#9CA3AF" stroke-width="1.5"/><line x1="12" y1="11" x2="12" y2="17" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/><line x1="9" y1="14" x2="15" y2="14" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/></svg></div>
      <div class="pdf-doc-info"><div class="pdf-doc-title" style="color:var(--muted)">Contrat</div><div class="pdf-doc-name">Aucun fichier</div></div>
      <div class="pdf-doc-actions"><label class="pdf-doc-btn pdf-doc-btn-upload" for="inp_${c.id}_${pf}" style="cursor:pointer;"><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg> Uploader</label></div>`;
  }
  docsEl.appendChild(card);

  document.getElementById('dp_edit_btn').onclick = ()=>openEdit(c);
  document.getElementById('dp_del_btn').onclick  = ()=>document.getElementById('deleteModal').classList.add('open');
  document.getElementById('contentArea').scrollTop=0;
}

function openEdit(c){
  document.getElementById('editSub').textContent          = c.aeroport||'';
  document.getElementById('edit_id').value                = c.id||'';
  document.getElementById('edit_aeroport').value          = c.aeroport||'';
  document.getElementById('edit_reference').value         = c.reference||'';
  document.getElementById('edit_type_concession').value   = c.type_concession||'';
  document.getElementById('edit_surface').value           = c.surface||'';
  document.getElementById('edit_statut').value            = c.statut||'Active';
  document.getElementById('edit_date_debut').value        = c.date_debut||'';
  document.getElementById('edit_date_fin').value          = c.date_fin||'';
  document.getElementById('edit_montant_annuel').value    = c.montant_annuel||'';
  document.getElementById('editModal').classList.add('open');
}

function filterSidebar(q){
  const lq=q.toLowerCase();
  document.querySelectorAll('.conc-item').forEach(el=>{
    el.style.display=el.dataset.search.includes(lq)?'':'none';
  });
}

function fmtDate(d){if(!d)return'—';const p=d.split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:d;}

function openPDF(url,title){
  document.getElementById('pdfFrame').src=url;
  document.getElementById('pdfTitle').textContent=title;
  document.getElementById('pdfModal').classList.add('open');
}
function closePDF(){
  document.getElementById('pdfModal').classList.remove('open');
  document.getElementById('pdfFrame').src='';
}

document.querySelectorAll('.modal-bg').forEach(el=>{
  el.addEventListener('click',e=>{
    if(e.target===el){el.classList.remove('open');if(el.id==='pdfModal')document.getElementById('pdfFrame').src='';}
  });
});

<?php if($selectedId && $concessions): ?>
<?php foreach($concessions as $c): if($c['id']===$selectedId): ?>
selectConc(<?=json_encode($c)?>);
<?php endif; endforeach; ?>
<?php endif; ?>
</script>
</body>
</html>