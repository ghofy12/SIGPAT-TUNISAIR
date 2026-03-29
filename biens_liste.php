<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';

$zone = $_GET['zone'] ?? 'Tunisie';

// ── ADD ──
if(isset($_POST['action']) && $_POST['action']==='add'){
    $fields=['adresse','reference','superficie','statut','valeur_acquisition','date_acquisition',
             'type_bien','num_contrat','type_contrat','date_contrat','date_debut_contrat',
             'date_fin_contrat','duree_contrat','loyer_mensuel','locataire','notaire','caution','map_url'];
    $cols=implode(',',$fields);
    $phs=implode(',',array_fill(0,count($fields),'?'));
    $vals=array_map(fn($f)=>($_POST[$f]??null)?:null,$fields);
    $pdo->prepare("INSERT INTO biens_fonciers ($cols,localisation) VALUES ($phs,?)")->execute([...$vals,$zone]);
    $newId=$pdo->lastInsertId();
    // ── Traiter les PDFs uploadés au moment de la création ──
    $PDF_FIELDS_ADD = ['titre_foncier_pdf','plan_pdf','contrat_acquisition_pdf',
                       'rapport_historique_pdf','rapport_valeur_locative_pdf','rapport_valeur_venale_pdf'];
    foreach($PDF_FIELDS_ADD as $pf){
        if(!empty($_FILES[$pf]['tmp_name']) && $_FILES[$pf]['error']===UPLOAD_ERR_OK){
            $dir='documents/'; if(!is_dir($dir)) mkdir($dir,0755,true);
            $fname=$pf.'_'.$newId.'_'.time().'.pdf';
            move_uploaded_file($_FILES[$pf]['tmp_name'],$dir.$fname);
            $pdo->prepare("UPDATE biens_fonciers SET $pf=? WHERE id=?")->execute([$dir.$fname,$newId]);
        }
    }
    header("Location: ?zone=".urlencode($zone)."&id=".$newId); exit;
}

// ── DELETE ──
if(isset($_POST['action']) && $_POST['action']==='delete' && !empty($_POST['id'])){
    $pdo->prepare("DELETE FROM biens_fonciers WHERE id=?")->execute([$_POST['id']]);
    header("Location: ?zone=".urlencode($zone)); exit;
}

// PDF fields list
$PDF_FIELDS = [
    'titre_foncier_pdf',
    'plan_pdf',
    'contrat_acquisition_pdf',
    'rapport_historique_pdf',        // ← FIX : plus d'espace
    'rapport_valeur_locative_pdf',
    'rapport_valeur_venale_pdf',
];

// ── EDIT ──
if(isset($_POST['action']) && $_POST['action']==='edit' && !empty($_POST['id'])){
    $fields=['adresse','reference','superficie','statut','valeur_acquisition','date_acquisition',
             'type_bien','num_contrat','type_contrat','date_contrat','date_debut_contrat',
             'date_fin_contrat','duree_contrat','loyer_mensuel','locataire','notaire','caution','map_url'];
    $sets=implode(', ',array_map(fn($f)=>"$f=?",$fields));
    $vals=array_map(fn($f)=>($_POST[$f]??null)?:null,$fields);
    $vals[]=$_POST['id'];
    $pdo->prepare("UPDATE biens_fonciers SET $sets WHERE id=?")->execute($vals);
    foreach($PDF_FIELDS as $pf){
        if(!empty($_FILES[$pf]['tmp_name'])){
            $dir='documents/'; if(!is_dir($dir)) mkdir($dir,0755,true);
            $fname=$pf.'_'.$_POST['id'].'_'.time().'.pdf';
            move_uploaded_file($_FILES[$pf]['tmp_name'],$dir.$fname);
            $pdo->prepare("UPDATE biens_fonciers SET $pf=? WHERE id=?")->execute([$dir.$fname,$_POST['id']]);
        }
    }
    header("Location: ?zone=".urlencode($zone)."&id=".$_POST['id']); exit;
}

// ── UPLOAD ──
if(isset($_POST['action']) && $_POST['action']==='upload' && !empty($_POST['id'])){
    foreach($PDF_FIELDS as $pf){
        if(!empty($_FILES[$pf]['tmp_name'])){
            $dir='documents/'; if(!is_dir($dir)) mkdir($dir,0755,true);
            $fname=$pf.'_'.$_POST['id'].'_'.time().'.pdf';
            move_uploaded_file($_FILES[$pf]['tmp_name'],$dir.$fname);
            $pdo->prepare("UPDATE biens_fonciers SET $pf=? WHERE id=?")->execute([$dir.$fname,$_POST['id']]);
        }
    }
    header("Location: ?zone=".urlencode($zone)."&id=".$_POST['id']); exit;
}

// ── FETCH ──
$stmt=$pdo->prepare("SELECT * FROM biens_fonciers WHERE localisation=? ORDER BY adresse");
$stmt->execute([$zone]); $biens=$stmt->fetchAll();

$total=count($biens);
$actifs=count(array_filter($biens,fn($b)=>stripos($b['statut'],'actif')!==false));
$loues=count(array_filter($biens,fn($b)=>stripos($b['statut'],'lou')!==false));
$superficie=array_sum(array_column($biens,'superficie'));
$loyer_total=array_sum(array_column($biens,'loyer_mensuel'));

$bien=null;
if(isset($_GET['id'])){
    $s=$pdo->prepare("SELECT * FROM biens_fonciers WHERE id=?");
    $s->execute([$_GET['id']]); $bien=$s->fetch();
}

$isTN = $zone === 'Tunisie';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Biens Fonciers — <?=htmlspecialchars($zone)?> · TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --red:#C8102E; --red-dark:#9B0E23;
  --navy:#0F2563; --navy-mid:#1D4ED8;
  --ink:#1A1A18; --muted:#6B7280;
  --bg:#F4F6F9; --white:#ffffff;
  --rule:rgba(0,0,0,.07);
  --shadow:0 4px 20px rgba(0,0,0,.07);
  --accent:<?=$isTN?'var(--red)':'var(--navy-mid)'?>;
  --accent-dark:<?=$isTN?'var(--red-dark)':'var(--navy)'?>;
  --accent-glow:<?=$isTN?'rgba(200,16,46,.20)':'rgba(29,78,216,.18)'?>;
}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}

/* ══ NAVBAR ══ */
.navbar{
  background:var(--white);
  border-bottom:3px solid var(--red);
  box-shadow:0 2px 10px rgba(0,0,0,.06);
  height:68px; padding:0 28px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:200;
}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:42px;width:auto;max-width:120px;object-fit:contain;flex-shrink:0;}
.nav-brand-text{font-size:15px;font-weight:700;color:var(--red);letter-spacing:.01em;}
.nav-right{display:flex;align-items:center;gap:18px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{
  background:var(--red);color:white;padding:8px 20px;border-radius:8px;
  text-decoration:none;font-size:13px;font-weight:600;
  box-shadow:0 3px 10px rgba(200,16,46,.22);
  transition:background .2s,transform .15s;
}
.btn-deconnexion:hover{background:var(--red-dark);transform:translateY(-1px);}

/* ══ LAYOUT ══ */
.main{display:flex;min-height:calc(100vh - 68px);}

/* ══ SIDEBAR ══ */
.sidebar{
  width:272px;flex-shrink:0;background:var(--white);
  border-right:1px solid var(--rule);
  padding:20px 14px;overflow-y:auto;
  display:flex;flex-direction:column;
}
.sidebar-hd{
  font-size:10px;font-weight:600;letter-spacing:.15em;text-transform:uppercase;
  color:var(--muted);padding:0 10px;margin-bottom:12px;
}
.btn-retour{
  display:flex;align-items:center;gap:8px;
  padding:9px 12px;border-radius:10px;text-decoration:none;
  color:var(--muted);font-size:12px;font-weight:600;
  border:1.5px solid var(--rule);background:var(--bg);
  transition:background .18s,color .18s,border-color .18s,transform .15s;
  margin-bottom:10px;letter-spacing:.01em;
}
.btn-retour:hover{background:var(--white);border-color:var(--accent);color:var(--accent);transform:translateX(-2px);}
.btn-retour svg{flex-shrink:0;transition:transform .15s;}
.btn-retour:hover svg{transform:translateX(-2px);}
.btn-ajouter{
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;padding:10px 12px;border-radius:10px;
  background:var(--accent);color:white;
  font-size:13px;font-weight:600;letter-spacing:.01em;
  border:none;cursor:pointer;font-family:'DM Sans',sans-serif;
  box-shadow:0 4px 14px var(--accent-glow);
  transition:background .18s,box-shadow .18s,transform .15s;
  margin-bottom:16px;
}
.btn-ajouter:hover{background:var(--accent-dark);box-shadow:0 8px 22px var(--accent-glow);transform:translateY(-1px);}
.sidebar-divider{height:1px;background:var(--rule);margin:0 10px 14px;}
.bien-link{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:10px;text-decoration:none;
  color:var(--ink);font-size:13px;font-weight:400;
  transition:background .18s;line-height:1.35;
}
.bien-link .dot{width:7px;height:7px;border-radius:50%;background:<?=$isTN?'rgba(200,16,46,.2)':'rgba(29,78,216,.2)'?>;flex-shrink:0;}
.bien-link:hover{background:rgba(0,0,0,.04);}
.bien-link.active{background:<?=$isTN?'linear-gradient(130deg,var(--red-dark),var(--red))':'linear-gradient(130deg,var(--navy),var(--navy-mid))'?>;color:white;font-weight:500;}
.bien-link.active .dot{background:rgba(255,255,255,.5);}
.bien-num{margin-left:auto;font-size:10px;opacity:.4;font-weight:500;}
.bien-link.active .bien-num{opacity:.65;}

/* ══ CONTENT ══ */
.content{flex:1;padding:28px 32px;overflow-y:auto;display:flex;flex-direction:column;gap:20px;}

/* ══ STATS ══ */
.stats-bar{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;}
.stat-card{
  background:var(--white);border-radius:14px;
  box-shadow:var(--shadow);padding:18px 20px;
  border-left:3px solid <?=$isTN?'var(--red)':'var(--navy-mid)'?>;
  transition:transform .2s,box-shadow .2s;
}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.10);}
.stat-label{font-size:10px;font-weight:600;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.stat-value{font-size:26px;font-weight:700;color:var(--ink);line-height:1;}
.stat-value.accent{color:var(--accent);}
.stat-sub{font-size:11px;color:var(--muted);margin-top:5px;}

/* ══ HEADER ══ */
.detail-header{animation:fadeUp .35s ease both;}
.detail-breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;letter-spacing:.10em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.detail-breadcrumb a{color:var(--muted);text-decoration:none;}
.detail-breadcrumb a:hover{color:var(--accent);}
.detail-breadcrumb-sep{opacity:.4;}
.detail-title{font-size:clamp(18px,2.2vw,24px);font-weight:700;letter-spacing:.01em;line-height:1.25;}
.detail-zone{display:inline-block;margin-top:8px;font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;padding:4px 12px;border-radius:20px;background:<?=$isTN?'rgba(200,16,46,.1)':'rgba(29,78,216,.1)'?>;color:var(--accent);}
.detail-actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;}

/* ══ BUTTONS ══ */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:'DM Sans',sans-serif;transition:transform .2s,box-shadow .2s,background .15s;letter-spacing:.01em;}
.btn:hover{transform:translateY(-2px);}
.btn-primary{background:var(--accent);color:white;box-shadow:0 4px 14px var(--accent-glow);}
.btn-primary:hover{box-shadow:0 8px 22px var(--accent-glow);}
.btn-ghost{background:var(--white);color:var(--ink);border:1.5px solid var(--rule);box-shadow:var(--shadow);}
.btn-ghost:hover{background:var(--bg);}
.btn-danger{background:#FEF2F2;color:#DC2626;border:1.5px solid #FECACA;}
.btn-danger:hover{background:#FEE2E2;}

/* ══ SECTION ══ */
.section{background:var(--white);border-radius:14px;box-shadow:var(--shadow);padding:24px;border:1px solid var(--rule);animation:fadeUp .4s ease both;}
.section:nth-child(2){animation-delay:.04s;}
.section:nth-child(3){animation-delay:.08s;}
.section:nth-child(4){animation-delay:.12s;}
.section:nth-child(5){animation-delay:.16s;}
.section-title{font-size:10px;font-weight:600;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.section-title::after{content:'';flex:1;height:1px;background:var(--rule);}

/* ══ FIELDS ══ */
.fields-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:12px;}
.field{background:var(--bg);border-radius:10px;padding:13px 15px;border:1px solid var(--rule);}
.field-label{font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.field-value{font-size:14px;font-weight:500;color:var(--ink);line-height:1.3;}
.field.hl{border-left:3px solid var(--accent);}
.field.hl .field-value{font-size:18px;font-weight:700;color:var(--accent);}
.statut-badge{display:inline-block;padding:3px 11px;border-radius:20px;font-size:12px;font-weight:600;letter-spacing:.03em;}
.occupied{background:#DCFCE7;color:#15803D;}
.vacant{background:#FEE2E2;color:#DC2626;}
.loue{background:#DBEAFE;color:#1D4ED8;}
.other{background:#F3F4F6;color:#4B5563;}

/* ══ DOCS ══ */
.docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(225px,1fr));gap:14px;}
.doc-card{border:1px solid var(--rule);border-radius:12px;padding:16px;background:var(--white);display:flex;flex-direction:column;gap:12px;transition:box-shadow .2s,transform .2s;}
.doc-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.09);transform:translateY(-1px);}
.doc-top{display:flex;align-items:center;gap:12px;}
.doc-icon{width:40px;height:40px;border-radius:10px;flex-shrink:0;background:linear-gradient(130deg,var(--accent-dark),var(--accent));display:grid;place-items:center;}
.doc-name{font-size:13px;font-weight:600;color:var(--ink);line-height:1.3;}
.doc-sub{font-size:11px;color:var(--muted);margin-top:3px;}
.doc-actions{display:flex;gap:8px;flex-wrap:wrap;}
.doc-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;border:1.5px solid var(--rule);font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);transition:background .15s,transform .15s;}
.doc-btn:hover{background:#EBEBEB;transform:translateY(-1px);}
.doc-btn-upload{background:linear-gradient(130deg,var(--accent-dark),var(--accent));color:white;border:none;box-shadow:0 3px 10px var(--accent-glow);}
.doc-btn-upload:hover{background:var(--accent-dark);border:none;}
.upload-input{display:none;}

/* ══ RAPPORT EXPERTISE — carte groupée ══ */
.rapport-card{
  grid-column: 1 / -1;
  border:1px solid var(--rule);border-radius:12px;
  background:var(--white);overflow:hidden;
  transition:box-shadow .2s,transform .2s;
}
.rapport-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.09);}
.rapport-header{
  display:flex;align-items:center;gap:12px;
  padding:16px 20px;
  border-bottom:1px solid var(--rule);
  background:var(--bg);
}
.rapport-header .doc-icon{flex-shrink:0;}
.rapport-header-text .doc-name{font-size:14px;}
.rapport-header-text .doc-sub{font-size:12px;}
.rapport-rows{display:flex;flex-direction:column;}
.rapport-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-bottom:1px solid var(--rule);gap:16px;flex-wrap:wrap;
}
.rapport-row:last-child{border-bottom:none;}
.rapport-row-info{display:flex;align-items:center;gap:12px;min-width:0;}
.rapport-row-icon{
  width:32px;height:32px;border-radius:8px;flex-shrink:0;
  background:linear-gradient(130deg,var(--accent-dark),var(--accent));
  display:grid;place-items:center;
}
.rapport-row-label{font-size:13px;font-weight:600;color:var(--ink);}
.rapport-row-file{font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;}
.rapport-row-actions{display:flex;gap:8px;flex-shrink:0;}

/* ══ MAP ══ */
.map-container{border-radius:12px;overflow:hidden;height:280px;border:1px solid var(--rule);}
.map-container iframe{width:100%;height:100%;border:none;}
.map-placeholder{height:260px;border-radius:12px;border:1.5px dashed var(--rule);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--muted);font-size:13px;text-align:center;}

/* ══ PDF MODAL ══ */
.modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:24px;}
.modal-bg.open{display:flex;}
.pdf-inner{background:white;border-radius:18px;width:min(900px,95vw);height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.22);}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--rule);}
.modal-head-title{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--ink);}
.modal-close{width:32px;height:32px;border-radius:8px;border:1px solid var(--rule);background:var(--bg);cursor:pointer;display:grid;place-items:center;transition:background .15s;}
.modal-close:hover{background:#E5E7EB;}
.pdf-frame{flex:1;border:none;width:100%;}

/* ══ EDIT / ADD MODAL ══ */
.edit-inner{background:var(--bg);border-radius:18px;width:min(700px,95vw);max-height:88vh;overflow-y:auto;padding:32px;box-shadow:0 32px 80px rgba(0,0,0,.18);}
.edit-inner h2{font-size:14px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--ink);margin-bottom:24px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group.full{grid-column:1/-1;}
.form-label{font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.form-input{padding:10px 14px;border-radius:9px;border:1.5px solid var(--rule);background:white;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow);}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;}

/* ══ DELETE MODAL ══ */
.del-inner{background:white;border-radius:16px;padding:32px 36px;width:min(400px,92vw);text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.18);}
.del-inner h3{font-size:14px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:12px;}
.del-inner p{font-size:13.5px;color:var(--muted);margin-bottom:24px;line-height:1.6;}
.del-actions{display:flex;gap:10px;justify-content:center;}

/* ══ EMPTY ══ */
.empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;color:var(--muted);}
.empty-state h3{font-size:18px;font-weight:600;color:var(--ink);}
.empty-state p{font-size:13px;}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}

/* ══ PDF UPLOAD IN FORM ══ */
.form-section-title{
  grid-column:1/-1;
  font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;
  color:var(--accent);padding-bottom:8px;border-bottom:1.5px solid var(--accent);
  margin-top:8px;
}
.pdf-upload-field{
  grid-column:1/-1;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:11px 14px;background:var(--white);border-radius:10px;
  border:1.5px solid var(--rule);transition:border-color .2s;
}
.pdf-upload-field:hover{border-color:var(--accent);}
.pdf-upload-field-info{display:flex;align-items:center;gap:10px;min-width:0;flex:1;}
.pdf-upload-field-icon{
  width:30px;height:30px;border-radius:7px;flex-shrink:0;
  background:linear-gradient(130deg,var(--accent-dark),var(--accent));
  display:grid;place-items:center;
}
.pdf-upload-field-icon.empty{background:var(--bg);border:1.5px dashed #D1D5DB;}
.pdf-upload-field-label{font-size:12px;font-weight:600;color:var(--ink);}
.pdf-upload-field-status{font-size:10px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;}
.pdf-upload-field-status.ok{color:#15803D;}
.pdf-upload-label{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 13px;border-radius:8px;font-size:11px;font-weight:600;
  cursor:pointer;white-space:nowrap;flex-shrink:0;
  background:var(--accent);color:white;border:none;
  font-family:'DM Sans',sans-serif;transition:opacity .15s,transform .15s;
}
.pdf-upload-label:hover{opacity:.88;transform:translateY(-1px);}
.pdf-upload-label.replace{background:var(--bg);color:var(--ink);border:1.5px solid var(--rule);}
.pdf-upload-label.replace:hover{background:#E5E7EB;}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
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

<!-- ══ MAIN ══ -->
<div class="main">

<!-- SIDEBAR -->
<div class="sidebar">
  <a href="biens_fonciers.php" class="btn-retour">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
      <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    Retour aux biens fonciers
  </a>
  <button class="btn-ajouter" onclick="document.getElementById('addModal').classList.add('open')">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
      <path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/>
    </svg>
    Ajouter un bien
  </button>
  <div class="sidebar-divider"></div>
  <p class="sidebar-hd">Portefeuille (<?=$total?>)</p>
  <?php foreach($biens as $i=>$b): ?>
    <a href="?zone=<?=urlencode($zone)?>&id=<?=$b['id']?>"
       class="bien-link <?=(isset($_GET['id'])&&$_GET['id']==$b['id'])?'active':''?>">
      <span class="dot"></span>
      <?=htmlspecialchars($b['adresse'])?>
      <span class="bien-num"><?=str_pad($i+1,2,'0',STR_PAD_LEFT)?></span>
    </a>
  <?php endforeach; ?>
</div>

<!-- CONTENT -->
<div class="content">

<!-- STATS -->
<div class="stats-bar">
  <div class="stat-card"><div class="stat-label">Total biens</div><div class="stat-value accent"><?=$total?></div><div class="stat-sub"><?=$zone?></div></div>
  <div class="stat-card"><div class="stat-label">Exploité</div><div class="stat-value"><?=$actifs?></div><div class="stat-sub">biens actifs</div></div>
  <div class="stat-card"><div class="stat-label">Loués</div><div class="stat-value"><?=$loues?></div><div class="stat-sub">en location</div></div>
  <div class="stat-card"><div class="stat-label">Superficie</div><div class="stat-value"><?=number_format($superficie,0,',',' ')?></div><div class="stat-sub">m² total</div></div>
</div>

<?php if($bien): ?>

<!-- HEADER -->
<div class="detail-header">
  <div class="detail-breadcrumb">
    <a href="biens_fonciers.php">Biens Fonciers</a>
    <span class="detail-breadcrumb-sep">›</span>
    <span><?=htmlspecialchars($zone)?></span>
    <span class="detail-breadcrumb-sep">›</span>
    <span><?=htmlspecialchars($bien['adresse'])?></span>
  </div>
  <h1 class="detail-title"><?=htmlspecialchars($bien['adresse'])?></h1>
  <span class="detail-zone"><?=$isTN?'🇹🇳':'🌍'?> <?=htmlspecialchars($zone)?></span>
  <div class="detail-actions">
    <button class="btn btn-ghost" onclick="document.getElementById('editModal').classList.add('open')">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 0 1 2 2L5 13H3v-2L11.5 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Modifier
    </button>
    <button class="btn btn-danger" onclick="document.getElementById('deleteModal').classList.add('open')">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M6 7v5M10 7v5M3 4l1 9a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1l1-9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Supprimer
    </button>
  </div>
</div>

<!-- GÉNÉRAL -->
<div class="section">
  <p class="section-title">Informations générales</p>
  <div class="fields-grid">
    <?php if(!empty($bien['reference'])): ?><div class="field"><div class="field-label">Référence</div><div class="field-value"><?=htmlspecialchars($bien['reference'])?></div></div><?php endif; ?>
    <?php if(!empty($bien['superficie'])): ?><div class="field hl"><div class="field-label">Superficie</div><div class="field-value"><?=number_format($bien['superficie'],2,',',' ')?> m²</div></div><?php endif; ?>
    <?php if(!empty($bien['statut'])): ?>
    <div class="field"><div class="field-label">Statut</div><div class="field-value">
      <?php $s=strtolower($bien['statut']);
        $cls=str_contains($s,'actif')?'occupied':(str_contains($s,'lou')?'loue':(str_contains($s,'vacant')||str_contains($s,'libre')?'vacant':'other')); ?>
      <span class="statut-badge <?=$cls?>"><?=htmlspecialchars($bien['statut'])?></span>
    </div></div><?php endif; ?>
    <?php if(!empty($bien['valeur_acquisition'])): ?><div class="field hl"><div class="field-label">Valeur d'acquisition</div><div class="field-value"><?=number_format($bien['valeur_acquisition'],0,',',' ')?> TND</div></div><?php endif; ?>
    <?php if(!empty($bien['date_acquisition'])): ?><div class="field"><div class="field-label">Date d'acquisition</div><div class="field-value"><?=date('d/m/Y',strtotime($bien['date_acquisition']))?></div></div><?php endif; ?>
    <?php if(!empty($bien['type_bien'])): ?><div class="field"><div class="field-label">Type de bien</div><div class="field-value"><?=htmlspecialchars($bien['type_bien'])?></div></div><?php endif; ?>
  </div>
</div>

<!-- DOCUMENTS PDF -->
<div class="section">
  <p class="section-title">Documents PDF</p>
  <div class="docs-grid">

    <?php
    // ── FIX : suppression de l'espace dans les clés ──
    $simpleDocs = [
      'titre_foncier_pdf'       => 'Titre Foncier',
      'plan_pdf'                => 'Plan du Bien',
      'contrat_acquisition_pdf' => "Contrat d'Acquisition",
      'rapport_historique_pdf'  => 'Historique des Évolutions',   // ← FIX : plus d'espace
    ];
    foreach($simpleDocs as $col=>$label):
      $path=$bien[$col]??null;
    ?>
    <div class="doc-card">
      <div class="doc-top">
        <div class="doc-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div>
          <div class="doc-name"><?=$label?></div>
          <div class="doc-sub"><?=$path?basename($path):'Aucun document'?></div>
        </div>
      </div>
      <div class="doc-actions">
        <?php if($path): ?>
          <button class="doc-btn" onclick="openPDF('<?=htmlspecialchars($path)?>', '<?=htmlspecialchars($label)?>')">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.4"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.4"/></svg>
            Aperçu
          </button>
          <a class="doc-btn" href="<?=htmlspecialchars($path)?>" download>
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Télécharger
          </a>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="display:inline">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="id" value="<?=$bien['id']?>">
          <input type="file" name="<?=$col?>" accept=".pdf" class="upload-input" id="up_<?=$col?>" onchange="this.form.submit()">
          <label for="up_<?=$col?>" class="doc-btn doc-btn-upload" style="cursor:pointer;">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="white" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?=$path?'Remplacer':'Uploader'?>
          </label>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- ── Rapport d'Expertise — carte groupée ── -->
    <?php
    $rapportSubs = [
      'rapport_valeur_locative_pdf' => 'Valeur Locative',
      'rapport_valeur_venale_pdf'   => 'Valeur Vénale',
    ];
    // FIX : compteur corrigé (2 sous-docs, plus "/ 3")
    $rapportCount = count(array_filter(array_keys($rapportSubs), fn($col) => !empty($bien[$col])));
    $rapportTotal = count($rapportSubs);
    ?>
    <div class="rapport-card">
      <div class="rapport-header">
        <div class="doc-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="rapport-header-text">
          <div class="doc-name">Rapport d'Expertise</div>
          <div class="doc-sub"><?=$rapportCount?> / <?=$rapportTotal?> document<?=$rapportCount>1?'s':''?> uploadé<?=$rapportCount>1?'s':''?></div>
        </div>
      </div>
      <div class="rapport-rows">
        <?php foreach($rapportSubs as $col=>$label):
          $path=$bien[$col]??null;
        ?>
        <div class="rapport-row">
          <div class="rapport-row-info">
            <div class="rapport-row-icon">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="white" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div>
              <div class="rapport-row-label"><?=htmlspecialchars($label)?></div>
              <div class="rapport-row-file"><?=$path?basename($path):'Aucun document'?></div>
            </div>
          </div>
          <div class="rapport-row-actions">
            <?php if($path): ?>
              <button class="doc-btn" onclick="openPDF('<?=htmlspecialchars($path)?>', 'Rapport — <?=htmlspecialchars($label)?>')">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.4"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.4"/></svg>
                Aperçu
              </button>
              <a class="doc-btn" href="<?=htmlspecialchars($path)?>" download>
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Télécharger
              </a>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="display:inline">
              <input type="hidden" name="action" value="upload">
              <input type="hidden" name="id" value="<?=$bien['id']?>">
              <input type="file" name="<?=$col?>" accept=".pdf" class="upload-input" id="up_<?=$col?>" onchange="this.form.submit()">
              <label for="up_<?=$col?>" class="doc-btn doc-btn-upload" style="cursor:pointer;">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="white" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?=$path?'Remplacer':'Uploader'?>
              </label>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /docs-grid -->
</div>

<!-- CARTE -->
<div class="section">
  <p class="section-title">Localisation</p>
  <?php
    // ── Construire l'URL d'embed et le lien d'ouverture ──
    $mapUrl      = $bien['map_url'] ?? '';
    $adresseQ    = urlencode(($bien['adresse']??'') . ', ' . $zone . ', Tunisie');
    $embedUrl    = '';
    $openUrl     = '';

    if(!empty($mapUrl)){
      // Cas 1 : coordonnées dans l'URL  (/@lat,lng)
      if(preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $mapUrl, $m)){
        $embedUrl = "https://maps.google.com/maps?q={$m[1]},{$m[2]}&z=16&output=embed";
      }
      // Cas 2 : URL /place/NomLieu
      elseif(preg_match('/place\/([^\/]+)/', $mapUrl, $m2)){
        $embedUrl = "https://maps.google.com/maps?q=".urlencode(urldecode($m2[1]))."&output=embed";
      }
      // Cas 3 : autre URL Google Maps valide → embed générique
      else {
        $embedUrl = "https://maps.google.com/maps?q={$adresseQ}&output=embed";
      }
      $openUrl = $mapUrl;
    } else {
      // Pas d'URL saisie → recherche automatique par adresse
      $embedUrl = "https://maps.google.com/maps?q={$adresseQ}&output=embed";
      $openUrl  = "https://www.google.com/maps/search/?api=1&query={$adresseQ}";
    }
  ?>
  <div style="display:flex;flex-direction:column;gap:14px;">

    <!-- Carte cliquable -->
    <div style="position:relative;border-radius:12px;overflow:hidden;height:300px;border:1px solid var(--rule);cursor:pointer;" onclick="window.open('<?=htmlspecialchars($openUrl)?>', '_blank')">
      <iframe
        src="<?=htmlspecialchars($embedUrl)?>"
        style="width:100%;height:100%;border:none;pointer-events:none;"
        allowfullscreen loading="lazy"
        referrerpolicy="no-referrer-when-downgrade">
      </iframe>
      <!-- Overlay transparent pour rendre toute la carte cliquable -->
      <div style="position:absolute;inset:0;cursor:pointer;" title="Ouvrir sur Google Maps" onclick="window.open('<?=htmlspecialchars($openUrl)?>', '_blank')"></div>
    </div>

    <!-- Bouton d'ouverture + info adresse -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div style="font-size:13px;color:var(--muted);">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:5px;"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" fill="currentColor"/></svg>
        <?=htmlspecialchars($bien['adresse']??'')?>
        <?php if(!empty($bien['map_url'])): ?>
          <span style="color:#22C55E;margin-left:8px;font-size:11px;font-weight:600;">✓ URL personnalisée</span>
        <?php else: ?>
          <span style="color:var(--muted);margin-left:8px;font-size:11px;">(recherche automatique)</span>
        <?php endif; ?>
      </div>
      <a href="<?=htmlspecialchars($openUrl)?>" target="_blank" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 2C7.24 2 5 4.24 5 7c0 4.25 5 11 5 11s5-6.75 5-11c0-2.76-2.24-5-5-5zm0 6.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" fill="white"/></svg>
        Ouvrir sur Google Maps
      </a>
    </div>

  </div>
</div>

<?php else: ?>
<div class="empty-state">
  <svg width="52" height="52" viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  <h3>Sélectionnez un bien</h3>
  <p>Choisissez une adresse dans la liste à gauche pour afficher sa fiche.</p>
</div>
<?php endif; ?>

</div><!-- /content -->
</div><!-- /main -->

<!-- ══ PDF MODAL ══ -->
<div class="modal-bg" id="pdfModal">
  <div class="pdf-inner">
    <div class="modal-head">
      <span class="modal-head-title" id="pdfTitle">Document</span>
      <button class="modal-close" onclick="closePDF()">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      </button>
    </div>
    <iframe class="pdf-frame" id="pdfFrame" src=""></iframe>
  </div>
</div>

<?php if($bien): ?>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-bg" id="editModal">
  <div class="edit-inner">
    <h2>Modifier le bien</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" value="<?=$bien['id']?>">
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Adresse</label><input class="form-input" type="text" name="adresse" value="<?=htmlspecialchars($bien['adresse']??'')?>"></div>
        <div class="form-group"><label class="form-label">Référence</label><input class="form-input" type="text" name="reference" value="<?=htmlspecialchars($bien['reference']??'')?>"></div>
        <div class="form-group"><label class="form-label">Superficie (m²)</label><input class="form-input" type="number" step="0.01" name="superficie" value="<?=$bien['superficie']??''?>"></div>
        <div class="form-group"><label class="form-label">Statut</label><input class="form-input" type="text" name="statut" value="<?=htmlspecialchars($bien['statut']??'')?>"></div>
        <div class="form-group"><label class="form-label">Type de bien</label><input class="form-input" type="text" name="type_bien" value="<?=htmlspecialchars($bien['type_bien']??'')?>"></div>
        <div class="form-group"><label class="form-label">Valeur acquisition (TND)</label><input class="form-input" type="number" name="valeur_acquisition" value="<?=$bien['valeur_acquisition']??''?>"></div>
        <div class="form-group"><label class="form-label">Date d'acquisition</label><input class="form-input" type="date" name="date_acquisition" value="<?=$bien['date_acquisition']??''?>"></div>
        <div class="form-group"><label class="form-label">N° Contrat</label><input class="form-input" type="text" name="num_contrat" value="<?=htmlspecialchars($bien['num_contrat']??'')?>"></div>
        <div class="form-group"><label class="form-label">Type de contrat</label><input class="form-input" type="text" name="type_contrat" value="<?=htmlspecialchars($bien['type_contrat']??'')?>"></div>
        <div class="form-group"><label class="form-label">Date contrat</label><input class="form-input" type="date" name="date_contrat" value="<?=$bien['date_contrat']??''?>"></div>
        <div class="form-group"><label class="form-label">Début contrat</label><input class="form-input" type="date" name="date_debut_contrat" value="<?=$bien['date_debut_contrat']??''?>"></div>
        <div class="form-group"><label class="form-label">Fin contrat</label><input class="form-input" type="date" name="date_fin_contrat" value="<?=$bien['date_fin_contrat']??''?>"></div>
        <div class="form-group"><label class="form-label">Durée (mois)</label><input class="form-input" type="number" name="duree_contrat" value="<?=$bien['duree_contrat']??''?>"></div>
        <div class="form-group"><label class="form-label">Loyer mensuel (TND)</label><input class="form-input" type="number" name="loyer_mensuel" value="<?=$bien['loyer_mensuel']??''?>"></div>
        <div class="form-group"><label class="form-label">Locataire</label><input class="form-input" type="text" name="locataire" value="<?=htmlspecialchars($bien['locataire']??'')?>"></div>
        <div class="form-group"><label class="form-label">Notaire</label><input class="form-input" type="text" name="notaire" value="<?=htmlspecialchars($bien['notaire']??'')?>"></div>
        <div class="form-group"><label class="form-label">Caution (TND)</label><input class="form-input" type="number" name="caution" value="<?=$bien['caution']??''?>"></div>
        <div class="form-group full"><label class="form-label">URL Google Maps</label><input class="form-input" type="url" name="map_url" value="<?=htmlspecialchars($bien['map_url']??'')?>"></div>

        <!-- ══ Documents PDF ══ -->
        <div class="form-section-title">Documents PDF <span style="font-size:9px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted);">(optionnel — remplace le fichier existant)</span></div>
        <?php
        $editPdfDocs = [
          'titre_foncier_pdf'          => 'Titre Foncier',
          'plan_pdf'                   => 'Plan du Bien',
          'contrat_acquisition_pdf'    => "Contrat d'Acquisition",
          'rapport_historique_pdf'     => 'Historique des Évolutions',
          'rapport_valeur_locative_pdf'=> 'Valeur Locative',
          'rapport_valeur_venale_pdf'  => 'Valeur Vénale',
        ];
        foreach($editPdfDocs as $col=>$lbl):
          $hasFile = !empty($bien[$col]);
        ?>
        <div class="pdf-upload-field">
          <div class="pdf-upload-field-info">
            <div class="pdf-upload-field-icon <?=$hasFile?'':'empty'?>">
              <?php if($hasFile): ?>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <?php else: ?>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="11" x2="12" y2="17" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/><line x1="9" y1="14" x2="15" y2="14" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/></svg>
              <?php endif; ?>
            </div>
            <div>
              <div class="pdf-upload-field-label"><?=htmlspecialchars($lbl)?></div>
              <div class="pdf-upload-field-status <?=$hasFile?'ok':''?>">
                <?=$hasFile ? '✓ '.basename($bien[$col]) : 'Aucun fichier'?>
              </div>
            </div>
          </div>
          <input type="file" name="<?=$col?>" id="edit_pdf_<?=$col?>" accept=".pdf" style="display:none;" onchange="updatePdfLabel(this)">
          <label for="edit_pdf_<?=$col?>" class="pdf-upload-label <?=$hasFile?'replace':''?>">
            <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?=$hasFile?'Remplacer':'Uploader'?>
          </label>
        </div>
        <?php endforeach; ?>

      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editModal').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ DELETE MODAL ══ -->
<div class="modal-bg" id="deleteModal">
  <div class="del-inner">
    <h3>Supprimer ce bien ?</h3>
    <p>Cette action est irréversible.<br><strong><?=htmlspecialchars($bien['adresse'])?></strong> sera définitivement supprimé.</p>
    <div class="del-actions">
      <button class="btn btn-ghost" onclick="document.getElementById('deleteModal').classList.remove('open')">Annuler</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?=$bien['id']?>">
        <button type="submit" class="btn btn-danger">Supprimer</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ══ ADD MODAL ══ -->
<div class="modal-bg" id="addModal">
  <div class="edit-inner">
    <h2>Nouveau bien — <?=htmlspecialchars($zone)?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group full">
          <label class="form-label">Adresse <span style="color:var(--accent)">*</span></label>
          <input class="form-input" type="text" name="adresse" required placeholder="Ex : 15 Avenue Habib Bourguiba">
        </div>
        <div class="form-group"><label class="form-label">Référence</label><input class="form-input" type="text" name="reference" placeholder="TF-001"></div>
        <div class="form-group"><label class="form-label">Superficie (m²)</label><input class="form-input" type="number" step="0.01" name="superficie" placeholder="0.00"></div>
        <div class="form-group">
          <label class="form-label">Statut</label>
          <select class="form-input" name="statut">
            <option value="">-- Choisir --</option>
            <option>Actif</option>
            <option>Loué</option>
            <option>Vacant</option>
            <option>En travaux</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Valeur acquisition (TND)</label><input class="form-input" type="number" name="valeur_acquisition" placeholder="0"></div>
        <div class="form-group"><label class="form-label">Date d'acquisition</label><input class="form-input" type="date" name="date_acquisition"></div>
        <div class="form-group"><label class="form-label">N° Contrat</label><input class="form-input" type="text" name="num_contrat"></div>
        <div class="form-group"><label class="form-label">Type de contrat</label><input class="form-input" type="text" name="type_contrat"></div>
        <div class="form-group"><label class="form-label">Date contrat</label><input class="form-input" type="date" name="date_contrat"></div>
        <div class="form-group"><label class="form-label">Début contrat</label><input class="form-input" type="date" name="date_debut_contrat"></div>
        <div class="form-group"><label class="form-label">Fin contrat</label><input class="form-input" type="date" name="date_fin_contrat"></div>
        <div class="form-group"><label class="form-label">Durée (mois)</label><input class="form-input" type="number" name="duree_contrat" placeholder="12"></div>
        <div class="form-group"><label class="form-label">Loyer mensuel (TND)</label><input class="form-input" type="number" name="loyer_mensuel" placeholder="0"></div>
        <div class="form-group"><label class="form-label">Locataire</label><input class="form-input" type="text" name="locataire"></div>
        <div class="form-group"><label class="form-label">Notaire</label><input class="form-input" type="text" name="notaire"></div>
        <div class="form-group"><label class="form-label">Caution (TND)</label><input class="form-input" type="number" name="caution" placeholder="0"></div>
        <div class="form-group full"><label class="form-label">URL Google Maps</label><input class="form-input" type="url" name="map_url" placeholder="https://maps.google.com/…"></div>

        <!-- ══ Documents PDF ══ -->
        <div class="form-section-title">Documents PDF <span style="font-size:9px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted);">(optionnel)</span></div>
        <?php
        $addPdfDocs = [
          'titre_foncier_pdf'          => 'Titre Foncier',
          'plan_pdf'                   => 'Plan du Bien',
          'contrat_acquisition_pdf'    => "Contrat d'Acquisition",
          'rapport_historique_pdf'     => 'Historique des Évolutions',
          'rapport_valeur_locative_pdf'=> 'Valeur Locative',
          'rapport_valeur_venale_pdf'  => 'Valeur Vénale',
        ];
        foreach($addPdfDocs as $col=>$lbl):
        ?>
        <div class="pdf-upload-field">
          <div class="pdf-upload-field-info">
            <div class="pdf-upload-field-icon empty" id="add_icon_<?=$col?>">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="11" x2="12" y2="17" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/><line x1="9" y1="14" x2="15" y2="14" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="pdf-upload-field-label"><?=htmlspecialchars($lbl)?></div>
              <div class="pdf-upload-field-status" id="add_status_<?=$col?>">Aucun fichier</div>
            </div>
          </div>
          <input type="file" name="<?=$col?>" id="add_pdf_<?=$col?>" accept=".pdf" style="display:none;" onchange="updatePdfLabel(this)">
          <label for="add_pdf_<?=$col?>" class="pdf-upload-label" id="add_lbl_<?=$col?>">
            <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Uploader
          </label>
        </div>
        <?php endforeach; ?>

      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addModal').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Créer le bien
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Mise à jour visuelle après sélection d'un PDF ──
function updatePdfLabel(input) {
  if (!input.files || !input.files.length) return;
  const file = input.files[0];
  const name = file.name;
  // Trouver les éléments frères dans le même .pdf-upload-field
  const field = input.closest ? input.nextElementSibling : null;
  const wrap = input.parentElement;
  // Chercher le label, status, icon par id ou data
  const id = input.id; // ex: add_pdf_titre_foncier_pdf ou edit_pdf_titre_foncier_pdf
  const col = id.replace('add_pdf_','').replace('edit_pdf_','');
  const prefix = id.startsWith('add_') ? 'add' : 'edit';

  // Status
  const statusEl = wrap.querySelector('.pdf-upload-field-status');
  if(statusEl){ statusEl.textContent = '✓ ' + name; statusEl.classList.add('ok'); }

  // Icon
  const iconEl = wrap.querySelector('.pdf-upload-field-icon');
  if(iconEl){
    iconEl.classList.remove('empty');
    iconEl.style.background = 'linear-gradient(130deg,var(--accent-dark),var(--accent))';
    iconEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><polyline points="14 2 14 8 20 8" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  // Label bouton
  const lbl = wrap.querySelector('.pdf-upload-label');
  if(lbl){ lbl.classList.add('replace'); lbl.innerHTML = '<svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Changer'; }
}

function openPDF(url, title) {
  document.getElementById('pdfFrame').src = url;
  document.getElementById('pdfTitle').textContent = title;
  document.getElementById('pdfModal').classList.add('open');
}
function closePDF() {
  document.getElementById('pdfModal').classList.remove('open');
  document.getElementById('pdfFrame').src = '';
}
document.querySelectorAll('.modal-bg').forEach(el => {
  el.addEventListener('click', function(e) {
    if (e.target === this) {
      this.classList.remove('open');
      if (this.id === 'pdfModal') document.getElementById('pdfFrame').src = '';
    }
  });
});
</script>
</body>
</html>