<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';

// Colonnes réelles: id, matricule, marque, modele, annee, type_vehicule,
// carte_grise_pdf, assurance_pdf, date_acquisition, valeur_acquisition,
// kilometrage, affectation_service, statut(actif|en_reparation|reforme),
// date_creation, date_modification

// ── ADD ──
if(isset($_POST['action']) && $_POST['action']==='add'){
    $pdo->prepare("INSERT INTO vehicules
        (matricule,marque,modele,annee,type_vehicule,date_acquisition,
         valeur_acquisition,kilometrage,affectation_service,statut)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $_POST['matricule']          ?? null,
            $_POST['marque']             ?? null,
            $_POST['modele']             ?? null,
            $_POST['annee']              ? (int)$_POST['annee'] : null,
            $_POST['type_vehicule']      ?? null,
            $_POST['date_acquisition']   ?: null,
            $_POST['valeur_acquisition'] ? (float)$_POST['valeur_acquisition'] : null,
            $_POST['kilometrage']        ? (int)$_POST['kilometrage'] : null,
            $_POST['affectation_service']?? null,
            $_POST['statut']             ?? 'actif',
        ]);
    $newId = $pdo->lastInsertId();
    foreach(['carte_grise_pdf','assurance_pdf'] as $pf){
        if(!empty($_FILES[$pf]['tmp_name'])){
            $dir='documents/vehicules/'; if(!is_dir($dir)) mkdir($dir,0755,true);
            $fname=$pf.'_'.$newId.'_'.time().'.pdf';
            move_uploaded_file($_FILES[$pf]['tmp_name'],$dir.$fname);
            $pdo->prepare("UPDATE vehicules SET $pf=? WHERE id=?")->execute([$dir.$fname,$newId]);
        }
    }
    header("Location: ?add_ok=1&id=".$newId); exit;
}

// ── EDIT ──
if(isset($_POST['action']) && $_POST['action']==='edit' && !empty($_POST['id'])){
    $pdo->prepare("UPDATE vehicules SET
        matricule=?,marque=?,modele=?,annee=?,type_vehicule=?,date_acquisition=?,
        valeur_acquisition=?,kilometrage=?,affectation_service=?,statut=?
        WHERE id=?")
        ->execute([
            $_POST['matricule']          ?? null,
            $_POST['marque']             ?? null,
            $_POST['modele']             ?? null,
            $_POST['annee']              ? (int)$_POST['annee'] : null,
            $_POST['type_vehicule']      ?? null,
            $_POST['date_acquisition']   ?: null,
            $_POST['valeur_acquisition'] ? (float)$_POST['valeur_acquisition'] : null,
            $_POST['kilometrage']        ? (int)$_POST['kilometrage'] : null,
            $_POST['affectation_service']?? null,
            $_POST['statut']             ?? 'actif',
            (int)$_POST['id'],
        ]);
    foreach(['carte_grise_pdf','assurance_pdf'] as $pf){
        if(!empty($_FILES[$pf]['tmp_name'])){
            $dir='documents/vehicules/'; if(!is_dir($dir)) mkdir($dir,0755,true);
            $fname=$pf.'_'.(int)$_POST['id'].'_'.time().'.pdf';
            move_uploaded_file($_FILES[$pf]['tmp_name'],$dir.$fname);
            $pdo->prepare("UPDATE vehicules SET $pf=? WHERE id=?")->execute([$dir.$fname,(int)$_POST['id']]);
        }
    }
    header("Location: ?id=".$_POST['id']); exit;
}

// ── DELETE ──
if(isset($_POST['action']) && $_POST['action']==='delete' && !empty($_POST['id'])){
    $pdo->prepare("DELETE FROM vehicules WHERE id=?")->execute([(int)$_POST['id']]);
    header("Location: vehicules_liste.php"); exit;
}

// ── FETCH LIST ──
$filtre = $_GET['filtre'] ?? 'tous';
if($filtre !== 'tous'){
    $stmt = $pdo->prepare("SELECT * FROM vehicules WHERE statut=? ORDER BY marque,modele");
    $stmt->execute([$filtre]);
} else {
    $stmt = $pdo->query("SELECT * FROM vehicules ORDER BY marque,modele");
}
$vehicules = $stmt->fetchAll();

// Stats
$stats = $pdo->query("SELECT
    COUNT(*) as total,
    SUM(statut='actif') as actifs,
    SUM(statut='en_reparation') as en_reparation,
    SUM(statut='reforme') as reformes,
    SUM(valeur_acquisition) as valeur_totale
    FROM vehicules")->fetch();

$selectedId = (int)($_GET['id'] ?? 0);
$veh = null;
if($selectedId){
    $s = $pdo->prepare("SELECT * FROM vehicules WHERE id=?");
    $s->execute([$selectedId]); $veh = $s->fetch();
}

$statuts_list = ['actif'=>'Actif','en_reparation'=>'En réparation','reforme'=>'Réformé'];
$types_list   = ['Berline','SUV','Utilitaire','Minibus','Camion','Moto','Véhicule de service','Pick-up'];
$pdfMeta      = [
    'carte_grise_pdf' => ['label'=>'Carte Grise', 'color'=>'#3B82F6'],
    'assurance_pdf'   => ['label'=>'Assurance',   'color'=>'#059669'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Parc Automobile · TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--red:#C8102E;--red-dark:#9B0E23;--ink:#1A1A18;--muted:#6B7280;--bg:#F4F6F9;--white:#fff;--rule:rgba(0,0,0,.07);--green:#059669;--orange:#D97706;--blue:#1D4ED8;--sidebar-w:275px;}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);overflow:hidden;}
.navbar{background:var(--white);border-bottom:3px solid var(--red);height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:200;box-shadow:0 2px 10px rgba(0,0,0,.06);}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:36px;}
.nav-title{font-size:14px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:16px;}
.nav-user{font-size:13px;color:var(--muted);font-weight:500;}
.btn-logout{background:var(--red);color:white;padding:7px 16px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;}
.layout{display:flex;height:calc(100vh - 64px);margin-top:64px;}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--white);border-right:1px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;}
.sb-top{padding:14px 12px;border-bottom:1px solid var(--rule);flex-shrink:0;}
.btn-back{display:flex;align-items:center;gap:7px;padding:8px 11px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;width:100%;margin-bottom:9px;transition:all .18s;}
.btn-back:hover{border-color:var(--red);color:var(--red);background:var(--white);}
.btn-add{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:9px;border-radius:9px;background:var(--red);color:white;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;box-shadow:0 3px 12px rgba(200,16,46,.25);transition:all .15s;}
.btn-add:hover{background:var(--red-dark);transform:translateY(-1px);}
.sb-filters{padding:9px 11px;border-bottom:1px solid var(--rule);flex-shrink:0;display:flex;gap:5px;flex-wrap:wrap;}
.filter-btn{padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;text-decoration:none;}
.filter-btn.on,.filter-btn:hover{background:var(--red);color:white;border-color:var(--red);}
.filter-btn.f-actif.on{background:var(--green);border-color:var(--green);}
.filter-btn.f-rep.on{background:var(--orange);border-color:var(--orange);}
.filter-btn.f-ref.on{background:#6B7280;border-color:#6B7280;}
.sb-search{padding:8px 12px;border-bottom:1px solid var(--rule);flex-shrink:0;position:relative;}
.sb-search-icon{position:absolute;left:20px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.sb-input{width:100%;padding:7px 12px 7px 30px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);font-family:'DM Sans',sans-serif;font-size:12px;outline:none;transition:border-color .2s;}
.sb-input:focus{border-color:var(--red);}
.sb-label{padding:9px 15px 5px;font-size:9.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);flex-shrink:0;}
.sb-list{flex:1;overflow-y:auto;padding:4px 9px 10px;}
.sb-list::-webkit-scrollbar{width:3px;}
.sb-list::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:2px;}
.veh-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:10px;cursor:pointer;transition:background .14s;margin-bottom:2px;}
.veh-item:hover{background:var(--bg);}
.veh-item.active{background:linear-gradient(135deg,var(--red-dark),var(--red));box-shadow:0 3px 12px rgba(200,16,46,.22);}
.veh-icon{width:36px;height:36px;border-radius:9px;background:var(--bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid var(--rule);}
.veh-item.active .veh-icon{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.3);}
.veh-body{flex:1;min-width:0;}
.veh-name{font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.veh-item.active .veh-name{color:white;}
.veh-meta{font-size:10px;color:var(--muted);margin-top:1px;}
.veh-item.active .veh-meta{color:rgba(255,255,255,.7);}
.veh-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;}
.veh-num{font-size:10px;font-weight:700;color:var(--muted);}
.veh-item.active .veh-num{color:rgba(255,255,255,.6);}
.veh-dot{width:7px;height:7px;border-radius:50%;}
/* MAIN */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.stats-bar{display:flex;gap:10px;padding:14px 20px;background:var(--white);border-bottom:1px solid var(--rule);flex-shrink:0;}
.stat-card{flex:1;background:var(--bg);border-radius:11px;padding:12px 14px;border-left:3px solid var(--red);}
.stat-card.g{border-left-color:var(--green);}
.stat-card.o{border-left-color:var(--orange);}
.stat-card.b{border-left-color:var(--blue);}
.stat-card.gr{border-left-color:#9CA3AF;}
.stat-label{font-size:9px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);margin-bottom:5px;}
.stat-value{font-size:20px;font-weight:700;color:var(--ink);}
.stat-value.red{color:var(--red);}
.stat-sub{font-size:9px;color:var(--muted);margin-top:2px;}
.content-area{flex:1;overflow-y:auto;padding:22px;}
.content-area::-webkit-scrollbar{width:5px;}
.content-area::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:3px;}
/* EMPTY */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;animation:fadeIn .3s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
.empty-icon{width:80px;height:80px;border-radius:20px;background:var(--white);display:flex;align-items:center;justify-content:center;border:2px dashed #D1D5DB;margin-bottom:16px;}
.empty-state h3{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:6px;}
.empty-state p{font-size:13px;color:var(--muted);max-width:260px;line-height:1.6;}
/* DETAIL */
.detail{animation:slideIn .28s cubic-bezier(.22,.68,0,1.1);}
@keyframes slideIn{from{opacity:0;transform:translateX(14px);}to{opacity:1;transform:none;}}
.detail-header{border-radius:16px;padding:20px 22px;margin-bottom:16px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;background:linear-gradient(135deg,var(--red-dark),var(--red));}
.dh-left{flex:1;}
.dh-badges{display:flex;align-items:center;gap:7px;margin-bottom:7px;}
.dh-tag{font-size:9px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:rgba(255,255,255,.7);}
.dh-title{font-size:20px;font-weight:700;color:white;line-height:1.3;}
.dh-sub{font-size:12px;color:rgba(255,255,255,.65);margin-top:4px;}
.dh-actions{display:flex;gap:7px;flex-shrink:0;}
.hbtn{display:flex;align-items:center;gap:5px;padding:8px 13px;border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;transition:all .14s;}
.hbtn-edit{background:rgba(255,255,255,.2);color:white;border:1.5px solid rgba(255,255,255,.3);}
.hbtn-edit:hover{background:rgba(255,255,255,.3);}
.hbtn-del{background:rgba(0,0,0,.2);color:white;border:1.5px solid rgba(0,0,0,.12);}
.hbtn-del:hover{background:rgba(0,0,0,.3);}
.statut-badge{display:inline-block;padding:3px 11px;border-radius:20px;font-size:10.5px;font-weight:700;}
.s-actif{background:#DCFCE7;color:#15803D;}
.s-rep{background:#FEF3C7;color:#92400E;}
.s-ref{background:#F3F4F6;color:#4B5563;}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-bottom:16px;}
.d-card{background:var(--white);border-radius:12px;border:1px solid var(--rule);padding:15px 17px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.d-card.full{grid-column:1/-1;}
.d-card.hl-blue{border-left:3px solid var(--blue);}
.d-section{font-size:9px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;margin-bottom:10px;padding-bottom:5px;border-bottom:2px solid;display:block;}
.d-section.red{color:var(--red);border-bottom-color:var(--red);}
.d-section.blue{color:var(--blue);border-bottom-color:var(--blue);}
.d-section.green{color:var(--green);border-bottom-color:var(--green);}
.d-section.violet{color:#7C3AED;border-bottom-color:#7C3AED;}
.d-section.grey{color:#6B7280;border-bottom-color:#9CA3AF;}
.d-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;}
.d-field{padding:9px 11px;background:var(--bg);border-radius:8px;border:1px solid var(--rule);}
.d-field.hl-f{border-left:3px solid var(--blue);}
.d-field.span2{grid-column:1/-1;}
.d-label{font-size:9px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:var(--muted);margin-bottom:3px;}
.d-value{font-size:13px;font-weight:600;color:var(--ink);}
.d-value.big{font-size:15px;color:var(--blue);}
.d-value.blue{color:var(--blue);}
/* INTEGRATIONS */
.integrations-grid{display:flex;gap:8px;flex-wrap:wrap;}
.integ-pill{display:flex;align-items:center;gap:6px;padding:7px 13px;border-radius:20px;font-size:11px;font-weight:600;border:1.5px solid var(--rule);background:var(--bg);}
.integ-dot{width:7px;height:7px;border-radius:50%;}
/* PDF */
.pdf-row{display:flex;gap:11px;flex-wrap:wrap;}
.pdf-card{background:var(--white);border:1px solid var(--rule);border-radius:11px;padding:13px;width:155px;display:flex;flex-direction:column;gap:9px;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:all .15s;}
.pdf-card:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.08);}
.pdf-card-empty{opacity:.65;}
.pdf-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;}
.pdf-icon-empty{background:var(--bg);border:1.5px dashed #D1D5DB;}
.pdf-title{font-size:11.5px;font-weight:700;color:var(--ink);margin-bottom:2px;}
.pdf-name{font-size:9.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:125px;}
.pdf-actions{display:flex;flex-direction:column;gap:5px;}
.pdf-btn{display:flex;align-items:center;justify-content:center;gap:5px;padding:5px 9px;border-radius:7px;font-size:10.5px;font-weight:600;cursor:pointer;border:1.5px solid var(--rule);background:var(--bg);color:var(--ink);font-family:'DM Sans',sans-serif;text-decoration:none;transition:all .13s;}
.pdf-btn:hover{background:#E5E7EB;}
.pdf-btn-upload{background:var(--blue);color:white;border-color:var(--blue);}
.pdf-btn-replace{background:#FEF2F2;color:var(--red);border-color:#FECACA;}
/* MODALS */
.modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:20px;}
.modal-bg.open{display:flex;}
.modal-inner{background:var(--bg);border-radius:18px;width:min(720px,96vw);max-height:90vh;overflow-y:auto;padding:28px 30px;box-shadow:0 32px 80px rgba(0,0,0,.18);}
.modal-inner h2{font-size:13px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--ink);margin-bottom:4px;}
.modal-sub{font-size:12px;color:var(--muted);margin-bottom:20px;}
.m-section{font-size:9px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--red);margin:18px 0 10px;padding-bottom:5px;border-bottom:1px solid var(--rule);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group.full{grid-column:1/-1;}
.form-label{font-size:9.5px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.form-input{padding:9px 13px;border-radius:9px;border:1.5px solid var(--rule);background:white;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--ink);outline:none;transition:border-color .2s;width:100%;}
.form-input:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(200,16,46,.08);}
.form-actions{display:flex;gap:9px;justify-content:flex-end;margin-top:20px;}
.del-inner{background:white;border-radius:16px;padding:30px;width:min(380px,92vw);text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.18);}
.del-inner h3{font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:10px;}
.del-inner p{font-size:13px;color:var(--muted);margin-bottom:22px;line-height:1.6;}
.del-actions{display:flex;gap:9px;justify-content:center;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 17px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:'DM Sans',sans-serif;transition:all .15s;}
.btn:hover{transform:translateY(-1px);}
.btn-primary{background:var(--red);color:white;box-shadow:0 4px 14px rgba(200,16,46,.25);}
.btn-ghost{background:var(--white);color:var(--ink);border:1.5px solid var(--rule);}
.btn-ghost:hover{background:var(--bg);}
.btn-danger{background:#FEF2F2;color:#DC2626;border:1.5px solid #FECACA;}
.btn-danger:hover{background:#FEE2E2;}
.pdf-modal-inner{background:white;border-radius:16px;width:min(900px,95vw);height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.22);}
.pdf-modal-head{display:flex;align-items:center;justify-content:space-between;padding:15px 20px;border-bottom:1px solid var(--rule);}
.pdf-modal-title{font-size:12px;font-weight:600;letter-spacing:.13em;text-transform:uppercase;}
.pdf-close{width:30px;height:30px;border-radius:7px;border:1px solid var(--rule);background:var(--bg);cursor:pointer;display:grid;place-items:center;}
.pdf-frame{flex:1;border:none;width:100%;}
.toast{position:fixed;top:74px;right:22px;z-index:900;background:#ECFDF5;color:#065F46;border:1.5px solid #A7F3D0;border-radius:12px;padding:11px 18px;font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:9px;box-shadow:0 6px 24px rgba(0,0,0,.12);animation:toastIn .3s ease;}
@keyframes toastIn{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:none;}}
@media(max-width:700px){.detail-grid{grid-template-columns:1fr;}.stats-bar{flex-wrap:wrap;}.stat-card{min-width:calc(50% - 5px);}}
</style>
</head>
<body>

<nav class="navbar">
  <a href="dashboard.php" class="nav-brand">
    <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
    <span class="nav-title">TUNISAIR &mdash; Parc Automobile</span>
  </a>
  <div class="nav-right">
    <span class="nav-user"><?=htmlspecialchars($username)?></span>
    <a href="logout.php" class="btn-logout">Déconnexion</a>
  </div>
</nav>

<?php if(isset($_GET['add_ok'])): ?>
<div class="toast" id="toast">
  <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#059669" stroke-width="1.4"/><path d="M5 8l2.5 2.5L11 5.5" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Véhicule ajouté avec succès !
</div>
<script>setTimeout(()=>document.getElementById('toast')?.remove(),3500);</script>
<?php endif; ?>

<div class="layout">

<aside class="sidebar">
  <div class="sb-top">
    <a href="dashboard.php" class="btn-back">
      <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Retour au tableau de bord
    </a>
    <button class="btn-add" onclick="document.getElementById('addModal').classList.add('open')">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
      Ajouter un véhicule
    </button>
  </div>

  <div class="sb-filters">
    <?php $filtres=['tous'=>['Tous',''],'actif'=>['Actifs','f-actif'],'en_reparation'=>['En réparation','f-rep'],'reforme'=>['Réformés','f-ref']];
    foreach($filtres as $key=>[$lbl,$cls]): ?>
    <a href="?filtre=<?=urlencode($key)?>" class="filter-btn <?=$cls?> <?=$filtre===$key?'on':''?>"><?=$lbl?></a>
    <?php endforeach; ?>
  </div>

  <div class="sb-search">
    <span class="sb-search-icon"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 10l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
    <input type="text" class="sb-input" placeholder="Rechercher..." oninput="filterList(this.value)">
  </div>

  <div class="sb-label">PARC (<?=count($vehicules)?>)</div>
  <div class="sb-list" id="sbList">
    <?php foreach($vehicules as $i=>$v):
      $s=$v['statut']??'';
      $dot=$s==='actif'?'#22C55E':($s==='en_reparation'?'#F59E0B':'#9CA3AF');
    ?>
    <div class="veh-item <?=$selectedId===$v['id']?'active':''?>"
         data-id="<?=$v['id']?>"
         data-search="<?=htmlspecialchars(strtolower(($v['matricule']??'').' '.($v['marque']??'').' '.($v['modele']??'').' '.($v['affectation_service']??'')))?>"
         onclick="selectVeh(<?=htmlspecialchars(json_encode($v),ENT_QUOTES)?>)">
      <div class="veh-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M7 17m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0M17 17m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0M5 17H3v-6l2-5h11l2 5h2v6h-2M5 17h10" stroke="<?=$selectedId===$v['id']?'white':'#9CA3AF'?>" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <div class="veh-body">
        <div class="veh-name"><?=htmlspecialchars($v['marque']??'—')?> <?=htmlspecialchars($v['modele']??'')?></div>
        <div class="veh-meta"><?=htmlspecialchars($v['matricule']??'—')?> · <?=htmlspecialchars($v['annee']??'')?></div>
      </div>
      <div class="veh-right">
        <div class="veh-num"><?=str_pad($i+1,2,'0',STR_PAD_LEFT)?></div>
        <div class="veh-dot" style="background:<?=$dot?>;"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</aside>

<main class="main">
  <div class="stats-bar">
    <div class="stat-card"><div class="stat-label">Parc Total</div><div class="stat-value red"><?=$stats['total']??0?></div><div class="stat-sub">véhicules</div></div>
    <div class="stat-card g"><div class="stat-label">Actifs</div><div class="stat-value"><?=$stats['actifs']??0?></div><div class="stat-sub">en service</div></div>
    <div class="stat-card o"><div class="stat-label">En Réparation</div><div class="stat-value"><?=$stats['en_reparation']??0?></div><div class="stat-sub">en maintenance</div></div>
    <div class="stat-card gr"><div class="stat-label">Réformés</div><div class="stat-value"><?=$stats['reformes']??0?></div><div class="stat-sub">hors service</div></div>
    <div class="stat-card b"><div class="stat-label">Valeur Totale</div><div class="stat-value" style="font-size:15px;"><?=number_format((float)($stats['valeur_totale']??0),0,',',' ')?></div><div class="stat-sub">TND</div></div>
  </div>

  <div class="content-area" id="contentArea">
    <div class="empty-state" id="emptyState" style="<?=$selectedId?'display:none':''?>">
      <div class="empty-icon">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none"><path d="M7 17m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0M17 17m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0M5 17H3v-6l2-5h11l2 5h2v6h-2M5 17h10" stroke="#D1D5DB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <h3>Sélectionnez un véhicule</h3>
      <p>Choisissez un véhicule dans la liste à gauche pour afficher sa fiche complète.</p>
    </div>

    <div class="detail" id="detailPanel" style="display:none;">
      <div class="detail-header">
        <div class="dh-left">
          <div class="dh-badges"><span class="dh-tag">PARC AUTOMOBILE TUNISAIR</span><span class="statut-badge" id="dp_statut">—</span></div>
          <div class="dh-title" id="dp_titre">—</div>
          <div class="dh-sub" id="dp_sub"></div>
        </div>
        <div class="dh-actions">
          <button class="hbtn hbtn-edit" id="dp_edit_btn">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 0 1 2 2L5 13H3v-2L11.5 2.5z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Modifier
          </button>
          <button class="hbtn hbtn-del" id="dp_del_btn">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M6 7v5M10 7v5M3 4l1 9a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1l1-9" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Supprimer
          </button>
        </div>
      </div>

      <div class="detail-grid">
        <div class="d-card">
          <span class="d-section red">Identification</span>
          <div class="d-field-grid">
            <div class="d-field"><div class="d-label">Matricule</div><div class="d-value" id="dp_matricule">—</div></div>
            <div class="d-field"><div class="d-label">Type de véhicule</div><div class="d-value" id="dp_type">—</div></div>
            <div class="d-field"><div class="d-label">Année</div><div class="d-value" id="dp_annee">—</div></div>
            <div class="d-field"><div class="d-label">Kilométrage</div><div class="d-value blue" id="dp_km">—</div></div>
          </div>
        </div>

        <div class="d-card hl-blue">
          <span class="d-section blue">Acquisition & Valeur</span>
          <div class="d-field-grid">
            <div class="d-field hl-f span2"><div class="d-label">Valeur d'acquisition</div><div class="d-value big" id="dp_valeur">—</div></div>
            <div class="d-field span2"><div class="d-label">Date d'acquisition</div><div class="d-value" id="dp_date_acq">—</div></div>
          </div>
        </div>

        <div class="d-card full">
          <span class="d-section green">Affectation</span>
          <div class="d-field-grid">
            <div class="d-field span2"><div class="d-label">Service / Affectation</div><div class="d-value" id="dp_affectation">—</div></div>
          </div>
        </div>

        <div class="d-card full">
          <span class="d-section violet">Documents PDF</span>
          <div class="pdf-row" id="dp_docs"></div>
          <div id="dp_forms"></div>
        </div>

        <div class="d-card full">
          <span class="d-section grey">Interfaçage Systèmes</span>
          <div class="integrations-grid">
            <div class="integ-pill"><div class="integ-dot" style="background:#3B82F6"></div>Comptabilité</div>
            <div class="integ-pill"><div class="integ-dot" style="background:#059669"></div>Achats</div>
            <div class="integ-pill"><div class="integ-dot" style="background:#C8102E"></div>Commission de Réforme</div>
            <div class="integ-pill"><div class="integ-dot" style="background:#D97706"></div>COSIP</div>
            <div class="integ-pill"><div class="integ-dot" style="background:#7C3AED"></div>DAG</div>
            <div class="integ-pill"><div class="integ-dot" style="background:#0F2563"></div>SIGA</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
</div>

<!-- PDF MODAL -->
<div class="modal-bg" id="pdfModal">
  <div class="pdf-modal-inner">
    <div class="pdf-modal-head">
      <span class="pdf-modal-title" id="pdfTitle">Document</span>
      <button class="pdf-close" onclick="closePDF()"><svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
    </div>
    <iframe class="pdf-frame" id="pdfFrame"></iframe>
  </div>
</div>

<?php if($veh): ?>
<!-- EDIT MODAL -->
<div class="modal-bg" id="editModal">
  <div class="modal-inner">
    <h2>Modifier le véhicule</h2>
    <p class="modal-sub" id="editSub"><?=htmlspecialchars(($veh['marque']??'').' '.($veh['modele']??''))?></p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id" value="<?=$veh['id']?>">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Marque *</label><input class="form-input" type="text" name="marque" id="edit_marque" value="<?=htmlspecialchars($veh['marque']??'')?>" required></div>
        <div class="form-group"><label class="form-label">Modèle *</label><input class="form-input" type="text" name="modele" id="edit_modele" value="<?=htmlspecialchars($veh['modele']??'')?>" required></div>
        <div class="form-group"><label class="form-label">Matricule</label><input class="form-input" type="text" name="matricule" id="edit_matricule" value="<?=htmlspecialchars($veh['matricule']??'')?>"></div>
        <div class="form-group"><label class="form-label">Type de véhicule</label>
          <select class="form-input" name="type_vehicule" id="edit_type_vehicule">
            <option value="">—</option>
            <?php foreach($types_list as $t): ?><option value="<?=$t?>" <?=($veh['type_vehicule']??'')===$t?'selected':''?>><?=$t?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Année</label><input class="form-input" type="number" name="annee" id="edit_annee" value="<?=htmlspecialchars($veh['annee']??'')?>" min="1980" max="2030"></div>
        <div class="form-group"><label class="form-label">Kilométrage (km)</label><input class="form-input" type="number" name="kilometrage" id="edit_kilometrage" value="<?=htmlspecialchars($veh['kilometrage']??'')?>"></div>
        <div class="form-group"><label class="form-label">Valeur d'acquisition (TND)</label><input class="form-input" type="number" step="0.01" name="valeur_acquisition" id="edit_valeur_acquisition" value="<?=htmlspecialchars($veh['valeur_acquisition']??'')?>"></div>
        <div class="form-group"><label class="form-label">Date d'acquisition</label><input class="form-input" type="date" name="date_acquisition" id="edit_date_acquisition" value="<?=htmlspecialchars($veh['date_acquisition']??'')?>"></div>
        <div class="form-group full"><label class="form-label">Affectation / Service</label><input class="form-input" type="text" name="affectation_service" id="edit_affectation_service" value="<?=htmlspecialchars($veh['affectation_service']??'')?>"></div>
        <div class="form-group"><label class="form-label">Statut</label>
          <select class="form-input" name="statut" id="edit_statut">
            <?php foreach($statuts_list as $val=>$lbl): ?><option value="<?=$val?>" <?=($veh['statut']??'')===$val?'selected':''?>><?=$lbl?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Carte grise PDF</label><input class="form-input" type="file" name="carte_grise_pdf" accept=".pdf" style="padding:6px 12px;"></div>
        <div class="form-group"><label class="form-label">Assurance PDF</label><input class="form-input" type="file" name="assurance_pdf" accept=".pdf" style="padding:6px 12px;"></div>
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
    <h3>Supprimer ce véhicule ?</h3>
    <p>Cette action est irréversible.<br><strong><?=htmlspecialchars(($veh['marque']??'').' '.($veh['modele']??'').' — '.($veh['matricule']??''))?></strong></p>
    <div class="del-actions">
      <button class="btn btn-ghost" onclick="document.getElementById('deleteModal').classList.remove('open')">Annuler</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?=$veh['id']?>">
        <button type="submit" class="btn btn-danger">Supprimer</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ADD MODAL -->
<div class="modal-bg" id="addModal">
  <div class="modal-inner">
    <h2>Nouveau véhicule</h2>
    <p class="modal-sub">Marque et Modèle sont obligatoires.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Marque *</label><input class="form-input" type="text" name="marque" required placeholder="Peugeot, Renault..."></div>
        <div class="form-group"><label class="form-label">Modèle *</label><input class="form-input" type="text" name="modele" required placeholder="208, Clio..."></div>
        <div class="form-group"><label class="form-label">Matricule</label><input class="form-input" type="text" name="matricule" placeholder="TU-123-AB"></div>
        <div class="form-group"><label class="form-label">Type de véhicule</label>
          <select class="form-input" name="type_vehicule"><option value="">—</option><?php foreach($types_list as $t): ?><option><?=$t?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label class="form-label">Année</label><input class="form-input" type="number" name="annee" min="1980" max="2030" placeholder="2022"></div>
        <div class="form-group"><label class="form-label">Kilométrage (km)</label><input class="form-input" type="number" name="kilometrage"></div>
        <div class="form-group"><label class="form-label">Valeur d'acquisition (TND)</label><input class="form-input" type="number" step="0.01" name="valeur_acquisition"></div>
        <div class="form-group"><label class="form-label">Date d'acquisition</label><input class="form-input" type="date" name="date_acquisition"></div>
        <div class="form-group full"><label class="form-label">Affectation / Service</label><input class="form-input" type="text" name="affectation_service" placeholder="Direction, service..."></div>
        <div class="form-group"><label class="form-label">Statut</label>
          <select class="form-input" name="statut"><?php foreach($statuts_list as $val=>$lbl): ?><option value="<?=$val?>"><?=$lbl?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label class="form-label">Carte grise PDF</label><input class="form-input" type="file" name="carte_grise_pdf" accept=".pdf" style="padding:6px 12px;"></div>
        <div class="form-group"><label class="form-label">Assurance PDF</label><input class="form-input" type="file" name="assurance_pdf" accept=".pdf" style="padding:6px 12px;"></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addModal').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>Créer</button>
      </div>
    </form>
  </div>
</div>

<script>
const pdfMeta=<?=json_encode($pdfMeta)?>;
let current=null;

function selectVeh(v){
  current=v;
  document.querySelectorAll('.veh-item').forEach(el=>el.classList.toggle('active',parseInt(el.dataset.id)===parseInt(v.id)));
  document.getElementById('emptyState').style.display='none';
  const panel=document.getElementById('detailPanel');
  panel.style.display='block'; panel.classList.remove('detail'); void panel.offsetWidth; panel.classList.add('detail');

  document.getElementById('dp_titre').textContent=(v.marque||'—')+' '+(v.modele||'');
  document.getElementById('dp_sub').textContent=[v.matricule,v.annee?v.annee+'':null,v.type_vehicule].filter(Boolean).join(' · ');

  const badge=document.getElementById('dp_statut');
  const statutLabels={'actif':'Actif','en_reparation':'En réparation','reforme':'Réformé'};
  badge.textContent=statutLabels[v.statut]||v.statut||'—';
  badge.className='statut-badge ';
  if(v.statut==='actif')badge.className+='s-actif';
  else if(v.statut==='en_reparation')badge.className+='s-rep';
  else badge.className+='s-ref';

  document.getElementById('dp_matricule').textContent=v.matricule||'—';
  document.getElementById('dp_type').textContent=v.type_vehicule||'—';
  document.getElementById('dp_annee').textContent=v.annee||'—';
  document.getElementById('dp_km').textContent=v.kilometrage?parseInt(v.kilometrage).toLocaleString('fr-FR')+' km':'—';
  document.getElementById('dp_valeur').textContent=v.valeur_acquisition?parseFloat(v.valeur_acquisition).toLocaleString('fr-FR',{minimumFractionDigits:0})+' TND':'—';
  document.getElementById('dp_date_acq').textContent=fmtDate(v.date_acquisition);
  document.getElementById('dp_affectation').textContent=v.affectation_service||'—';

  renderDocs(v);
  document.getElementById('dp_edit_btn').onclick=()=>openEdit(v);
  document.getElementById('dp_del_btn').onclick=()=>document.getElementById('deleteModal').classList.add('open');
  document.getElementById('contentArea').scrollTop=0;
}

function renderDocs(v){
  const docsEl=document.getElementById('dp_docs'),formsEl=document.getElementById('dp_forms');
  docsEl.innerHTML=''; formsEl.innerHTML='';
  Object.entries(pdfMeta).forEach(([pf,{label,color}])=>{
    const fname=v[pf]?v[pf].split('/').pop():null;
    const safe=(v.matricule||'').replace(/'/g,"\\'");
    const form=document.createElement('form');
    form.method='post';form.enctype='multipart/form-data';form.style.display='none';
    form.innerHTML=`<input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="${v.id}"><input type="hidden" name="marque" value="${(v.marque||'').replace(/"/g,'&quot;')}"><input type="hidden" name="modele" value="${(v.modele||'').replace(/"/g,'&quot;')}"><input type="hidden" name="statut" value="${v.statut||'actif'}"><input type="file" name="${pf}" id="inp_${v.id}_${pf}" accept=".pdf" onchange="this.closest('form').submit()">`;
    formsEl.appendChild(form);
    const card=document.createElement('div');
    if(fname){
      card.className='pdf-card';
      card.innerHTML=`<div class="pdf-icon" style="background:linear-gradient(135deg,${color}bb,${color})"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" fill="white" opacity=".9"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.5" fill="none"/></svg></div>
        <div><div class="pdf-title">${label}</div><div class="pdf-name">${fname}</div></div>
        <div class="pdf-actions">
          <button class="pdf-btn" onclick="openPDF('${v[pf]}','${label} — ${safe}')">👁 Aperçu</button>
          <a class="pdf-btn" href="${v[pf]}" download>⬇ Télécharger</a>
          <label class="pdf-btn pdf-btn-replace" for="inp_${v.id}_${pf}" style="cursor:pointer;">⬆ Remplacer</label>
        </div>`;
    } else {
      card.className='pdf-card pdf-card-empty';
      card.innerHTML=`<div class="pdf-icon pdf-icon-empty"><svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2z" stroke="#9CA3AF" stroke-width="1.5"/><polyline points="14 2 14 8 20 8" stroke="#9CA3AF" stroke-width="1.5"/><line x1="12" y1="11" x2="12" y2="17" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/><line x1="9" y1="14" x2="15" y2="14" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/></svg></div>
        <div><div class="pdf-title" style="color:var(--muted)">${label}</div><div class="pdf-name">Aucun fichier</div></div>
        <div class="pdf-actions"><label class="pdf-btn pdf-btn-upload" for="inp_${v.id}_${pf}" style="cursor:pointer;">⬆ Uploader</label></div>`;
    }
    docsEl.appendChild(card);
  });
}

function openEdit(v){
  document.getElementById('edit_id').value=v.id||'';
  document.getElementById('edit_marque').value=v.marque||'';
  document.getElementById('edit_modele').value=v.modele||'';
  document.getElementById('edit_matricule').value=v.matricule||'';
  document.getElementById('edit_type_vehicule').value=v.type_vehicule||'';
  document.getElementById('edit_annee').value=v.annee||'';
  document.getElementById('edit_kilometrage').value=v.kilometrage||'';
  document.getElementById('edit_valeur_acquisition').value=v.valeur_acquisition||'';
  document.getElementById('edit_date_acquisition').value=v.date_acquisition||'';
  document.getElementById('edit_affectation_service').value=v.affectation_service||'';
  document.getElementById('edit_statut').value=v.statut||'actif';
  document.getElementById('editSub').textContent=(v.marque||'')+' '+(v.modele||'');
  document.getElementById('editModal').classList.add('open');
}

function filterList(q){
  document.querySelectorAll('.veh-item').forEach(el=>{
    el.style.display=el.dataset.search.includes(q.toLowerCase())?'':'none';
  });
}

function fmtDate(d){if(!d)return'—';const p=d.split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:d;}
function openPDF(url,title){document.getElementById('pdfFrame').src=url;document.getElementById('pdfTitle').textContent=title;document.getElementById('pdfModal').classList.add('open');}
function closePDF(){document.getElementById('pdfModal').classList.remove('open');document.getElementById('pdfFrame').src='';}

document.querySelectorAll('.modal-bg').forEach(el=>{
  el.addEventListener('click',e=>{if(e.target===el){el.classList.remove('open');if(el.id==='pdfModal')closePDF();}});
});

<?php if($selectedId&&$veh): ?>selectVeh(<?=json_encode($veh)?>);<?php endif; ?>
</script>
</body>