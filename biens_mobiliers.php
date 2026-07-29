<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
requireModuleAccess($pdo, 'biens_mobiliers');
$username = $_SESSION['username'] ?? 'Utilisateur';

// ── Étages (utilisé quand on est dans SIEGE TUNISAIR) ────────────
$ETAGES = [
  0=>['label'=>'Rez-de-Chaussée','short'=>'RDC','sub'=>'BOC · DCSI · DCOA · DCP · DCF',  'color'=>'#6D28D9,#7C3AED','icon'=>'🏢'],
  1=>['label'=>'1er Étage',      'short'=>'1er','sub'=>'DCRH · DCA · DSVP · Call Center', 'color'=>'#0F2563,#1D4ED8','icon'=>'1️⃣'],
  2=>['label'=>'2ème Étage',     'short'=>'2ème','sub'=>'DCF · DCP · Catering · DRC',     'color'=>'#701A75,#A21CAF','icon'=>'2️⃣'],
  3=>['label'=>'3ème Étage',     'short'=>'3ème','sub'=>'DCOA · DCP · DRM',               'color'=>'#C8102E,#EF4444','icon'=>'3️⃣'],
  4=>['label'=>'4ème Étage',     'short'=>'4ème','sub'=>'DCC · SPOD · DCRH · DAJ',        'color'=>'#0F2563,#1D4ED8','icon'=>'4️⃣'],
  5=>['label'=>'5ème Étage',     'short'=>'5ème','sub'=>'Direction Générale · SG',        'color'=>'#9B0E23,#C8102E','icon'=>'5️⃣'],
];

// ── Lieux physiques (depuis la table ou fallback statique) ────────
$lieux = [];
try {
  $lieux = $pdo->query("
    SELECT
        v.lieu_id      AS id,
        lp.code,
        lp.label,
        lp.site,
        lp.ville,
        lp.type_lieu,
        lp.icon,
        lp.color,
        lp.actif,
        v.nb_articles,
        v.total_quantite,
        v.nb_fonctionnel   AS nb_fonc,
        v.nb_non_fonctionnel AS nb_nfonc,
        v.nb_en_reparation AS nb_rep,
        v.nb_rebut,
        v.valeur_totale
    FROM v_mobilier_par_lieu v
    JOIN lieux_physiques lp ON lp.id = v.lieu_id
    ORDER BY
        FIELD(lp.type_lieu,'SIEGE','AEROPORT','CENTRE','REPRESENTATION','AGENCE','AUTRE'),
        v.nb_articles DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){ /* table pas encore créée */ }

// Fallback statique si DB vide
if(empty($lieux)){
  $lieux = [
    ['id'=>0,'code'=>'SIEGE_TUNISAIR',        'label'=>'Siège Tunisair',               'type_lieu'=>'SIEGE',          'ville'=>'Tunis',    'icon'=>'🏢','color'=>'#C8102E','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'SIEGE_TECHNICS',         'label'=>'Siège Technics',               'type_lieu'=>'SIEGE',          'ville'=>'Tunis',    'icon'=>'🔧','color'=>'#0F2563','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'SIEGE_AISA',             'label'=>'Siège AISA',                   'type_lieu'=>'SIEGE',          'ville'=>'Tunis',    'icon'=>'🏛️','color'=>'#6D28D9','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'APT_TUNIS_CARTHAGE',     'label'=>'Aéroport Tunis-Carthage',      'type_lieu'=>'AEROPORT',       'ville'=>'Tunis',    'icon'=>'✈️','color'=>'#1D4ED8','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'APT_MONASTIR',           'label'=>'Aéroport Monastir',            'type_lieu'=>'AEROPORT',       'ville'=>'Monastir', 'icon'=>'✈️','color'=>'#059669','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'APT_SFAX',               'label'=>'Aéroport Sfax',                'type_lieu'=>'AEROPORT',       'ville'=>'Sfax',     'icon'=>'✈️','color'=>'#D97706','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'CENTRE_FORMATION',       'label'=>'Centre de Formation',          'type_lieu'=>'CENTRE',         'ville'=>'Tunis',    'icon'=>'📚','color'=>'#7C3AED','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'CENTRE_MEDICALE',        'label'=>'Centre Médical',               'type_lieu'=>'CENTRE',         'ville'=>'Tunis',    'icon'=>'🏥','color'=>'#DC2626','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'FRET',                   'label'=>'Fret',                         'type_lieu'=>'AUTRE',          'ville'=>'Tunis',    'icon'=>'📦','color'=>'#92400E','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'REPRESENTATION_DJE',     'label'=>'Représentation Djerba',        'type_lieu'=>'REPRESENTATION', 'ville'=>'Djerba',   'icon'=>'🏪','color'=>'#065F46','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'REPRESENTATION_SOUSSE',  'label'=>'Représentation Sousse',        'type_lieu'=>'REPRESENTATION', 'ville'=>'Sousse',   'icon'=>'🏪','color'=>'#1E40AF','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'DELEGATION_AV_LIBERTE',  'label'=>'Délégation Gén. Av. Liberté', 'type_lieu'=>'AGENCE',         'ville'=>'Tunis',    'icon'=>'🏬','color'=>'#701A75','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'BTO_SFAX',               'label'=>'BTO Sfax',                     'type_lieu'=>'AGENCE',         'ville'=>'Sfax',     'icon'=>'🏬','color'=>'#B45309','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_BIZERTE',         'label'=>'Agence Bizerte',               'type_lieu'=>'AGENCE',         'ville'=>'Bizerte',  'icon'=>'🏬','color'=>'#0369A1','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_SOUSSE',          'label'=>'Agence Sousse',                'type_lieu'=>'AGENCE',         'ville'=>'Sousse',   'icon'=>'🏬','color'=>'#0284C7','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_NABEUL',          'label'=>'Agence Nabeul',                'type_lieu'=>'AGENCE',         'ville'=>'Nabeul',   'icon'=>'🏬','color'=>'#0369A1','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_LA_MARSA',        'label'=>'Agence La Marsa',              'type_lieu'=>'AGENCE',         'ville'=>'Tunis',    'icon'=>'🏬','color'=>'#0369A1','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_MONASTIR',        'label'=>'Agence Monastir',              'type_lieu'=>'AGENCE',         'ville'=>'Monastir', 'icon'=>'🏬','color'=>'#059669','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_FIDELYS',         'label'=>'Agence Fidelys',               'type_lieu'=>'AGENCE',         'ville'=>'Tunis',    'icon'=>'🏬','color'=>'#6D28D9','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_KASBA',           'label'=>'Agence Kasba',                 'type_lieu'=>'AGENCE',         'ville'=>'Tunis',    'icon'=>'🏬','color'=>'#0369A1','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
    ['id'=>0,'code'=>'AGENCE_SIEGE_TUNISAIR',  'label'=>'Agence Siège Tunisair',        'type_lieu'=>'AGENCE',         'ville'=>'Tunis',    'icon'=>'🏬','color'=>'#C8102E','nb_articles'=>0,'nb_fonc'=>0,'nb_nfonc'=>0],
  ];
}

// ── Lieu sélectionné ─────────────────────────────────────────────
$selectedLieu = $_GET['lieu'] ?? null;
$selectedLieuData = null;
foreach($lieux as $l){ if($l['code']===$selectedLieu){ $selectedLieuData=$l; break; } }

// ── Stats globales ───────────────────────────────────────────────
$total_mobilier = 0;
$total_lieux_actifs = count($lieux);
try { $total_mobilier = $pdo->query("SELECT COUNT(*) FROM biens_mobiliers")->fetchColumn(); } catch(Exception $e){}

// ── Stats par étage (Siège uniquement) ──────────────────────────
// NB : de nombreux articles importés ont bureau_id = NULL et sont rattachés
// à un bureau uniquement via code_bureau_physique (cf. Biens_mobiliers_liste.php).
// On reproduit ici la même logique de comptage (bureau_id direct + fallback
// sur code_bureau_physique normalisé) pour ne pas afficher 0 partout.
$stats_par_etage = [];
try {
  $bureaux_rows = $pdo->query("SELECT id, etage, ref_bureau FROM siege_bureaux")->fetchAll(PDO::FETCH_ASSOC);

  // Init nb_bureaux / nb_mobilier par étage
  foreach($bureaux_rows as $b){
    $et = intval($b['etage']);
    if(!isset($stats_par_etage[$et])) $stats_par_etage[$et] = ['nb_bureaux'=>0,'nb_mobilier'=>0];
    $stats_par_etage[$et]['nb_bureaux']++;
  }

  // Comptage direct via bureau_id
  $counts = [];
  foreach($pdo->query("SELECT bureau_id, COUNT(*) nb FROM biens_mobiliers WHERE bureau_id IS NOT NULL GROUP BY bureau_id")->fetchAll(PDO::FETCH_ASSOC) as $r){
    $counts[intval($r['bureau_id'])] = intval($r['nb']);
  }

  // Comptage fallback via code_bureau_physique normalisé (bureau_id NULL)
  $legacy_map = [];
  foreach($pdo->query("
    SELECT REPLACE(UPPER(REPLACE(REPLACE(REPLACE(code_bureau_physique,'/',''),'-',''),' ','')), '0','O') AS rn, COUNT(*) nb
    FROM biens_mobiliers
    WHERE bureau_id IS NULL AND code_bureau_physique IS NOT NULL
    GROUP BY rn
  ")->fetchAll(PDO::FETCH_ASSOC) as $r){
    $legacy_map[$r['rn']] = intval($r['nb']);
  }

  foreach($bureaux_rows as $b){
    $bid = intval($b['id']);
    $et  = intval($b['etage']);
    $nb  = $counts[$bid] ?? 0;
    $rn  = str_replace('0','O',strtoupper(preg_replace('/[\s\/\-\.]+/','',$b['ref_bureau'])));
    if(isset($legacy_map[$rn])) $nb += $legacy_map[$rn];
    $stats_par_etage[$et]['nb_mobilier'] += $nb;
  }
} catch(Exception $e){}

// ── Types pour affichage groupé ──────────────────────────────────
$TYPE_LABELS = [
  'SIEGE'          => ['label'=>'Sièges',           'icon'=>'🏢'],
  'AEROPORT'       => ['label'=>'Aéroports',         'icon'=>'✈️'],
  'CENTRE'         => ['label'=>'Centres',           'icon'=>'🏫'],
  'REPRESENTATION' => ['label'=>'Représentations',   'icon'=>'🏪'],
  'AGENCE'         => ['label'=>'Agences',           'icon'=>'🏬'],
  'AUTRE'          => ['label'=>'Autres',            'icon'=>'📍'],
];

// Regrouper les lieux par type
$lieux_par_type = [];
foreach($lieux as $l){ $lieux_par_type[$l['type_lieu']][] = $l; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Biens Mobiliers · TUNISAIR</title>
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
}
html,body{min-height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}

/* ── NAVBAR ── */
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:16px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:7px 18px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;}
.btn-deconnexion:hover{background:var(--red-dark);}

/* ── LAYOUT ── */
.app{display:flex;height:calc(100vh - 64px);}
::-webkit-scrollbar{width:4px;}::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:2px;}

/* ── SIDEBAR LIEU PICKER ── */
.sidebar{
  width:290px;flex-shrink:0;
  background:var(--white);
  border-right:1.5px solid var(--rule);
  display:flex;flex-direction:column;
  overflow:hidden;
  transition:width .2s;
}
.sb-header{padding:16px 14px 10px;flex-shrink:0;border-bottom:1.5px solid var(--rule);}
.sb-title{font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.sb-search{position:relative;margin-bottom:0;}
.sb-search svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.sb-search input{
  width:100%;padding:8px 10px 8px 30px;
  border:1.5px solid var(--rule);border-radius:9px;
  font-size:12px;font-family:'DM Sans',sans-serif;
  color:var(--ink);background:var(--bg);outline:none;
  transition:border-color .2s;
}
.sb-search input:focus{border-color:var(--red);}

.sb-body{flex:1;overflow-y:auto;padding:8px 8px 20px;}

.type-section{margin-bottom:4px;}
.type-label{
  display:flex;align-items:center;gap:6px;
  padding:7px 8px 4px;
  font-size:9px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;
  color:var(--muted);cursor:pointer;
  border-radius:6px;transition:background .15s;
  user-select:none;
}
.type-label:hover{background:var(--bg);}
.type-label-icon{font-size:12px;}
.type-chevron{margin-left:auto;transition:transform .2s;}
.type-section.collapsed .type-chevron{transform:rotate(-90deg);}
.type-section.collapsed .type-items{display:none;}

.lieu-item{
  display:flex;align-items:center;gap:9px;
  padding:8px 10px;border-radius:10px;
  cursor:pointer;transition:background .14s;
  margin-bottom:2px;text-decoration:none;
}
.lieu-item:hover{background:var(--bg);}
.lieu-item.active{background:var(--red);box-shadow:0 3px 12px rgba(200,16,46,.25);}
.lieu-item.active *{color:white!important;}

.li-icon{
  width:32px;height:32px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  font-size:14px;flex-shrink:0;
  background:var(--bg);transition:background .14s;
}
.lieu-item.active .li-icon{background:rgba(255,255,255,.2);}
.li-body{flex:1;min-width:0;}
.li-name{font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.li-ville{font-size:10px;color:var(--muted);margin-top:1px;}
.li-count{
  flex-shrink:0;font-size:10px;font-weight:700;
  padding:2px 7px;border-radius:20px;
  background:rgba(200,16,46,.1);color:var(--red);
}
.lieu-item.active .li-count{background:rgba(255,255,255,.25);color:white;}
.li-count.zero{background:#F3F4F6;color:#9CA3AF;}
.lieu-item.active .li-count.zero{background:rgba(255,255,255,.15);color:rgba(255,255,255,.6);}

/* ── MAIN CONTENT ── */
.main{flex:1;overflow-y:auto;background:var(--bg);}

/* ── HERO ── */
.hero-wrap{padding:32px 32px 0;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.breadcrumb a{color:var(--muted);text-decoration:none;}.breadcrumb a:hover{color:var(--red);}
.btn-retour{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:9px;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:all .18s;margin-bottom:22px;}
.btn-retour:hover{border-color:var(--red);color:var(--red);}
.hero{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:28px;animation:fadeUp .4s ease;}
.hero-left{display:flex;align-items:center;gap:18px;}
.hero-icon{width:58px;height:58px;background:linear-gradient(135deg,var(--red-dark),var(--red));border-radius:16px;display:grid;place-items:center;box-shadow:0 8px 24px rgba(200,16,46,.28);}
.hero h1{font-size:24px;font-weight:700;letter-spacing:-.01em;margin-bottom:4px;}
.hero-sub{font-size:13px;color:var(--muted);line-height:1.6;}
.hero-stats{display:flex;gap:12px;}
.hs{background:var(--white);border-radius:12px;padding:14px 18px;border:1.5px solid var(--rule);box-shadow:var(--shadow);text-align:center;min-width:90px;}
.hs-val{font-size:26px;font-weight:700;color:var(--red);}
.hs-lbl{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:3px;}

/* ── LIEU SELECTED HEADER ── */
.lieu-header{
  margin:0 32px 20px;
  background:var(--white);
  border-radius:14px;
  padding:18px 22px;
  border:1.5px solid var(--rule);
  box-shadow:var(--shadow);
  display:flex;align-items:center;gap:16px;
  animation:fadeUp .3s ease;
}
.lh-avatar{
  width:52px;height:52px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-size:22px;flex-shrink:0;
}
.lh-info{flex:1;}
.lh-name{font-size:18px;font-weight:700;color:var(--ink);}
.lh-meta{font-size:12px;color:var(--muted);margin-top:3px;}
.lh-stats{display:flex;gap:10px;flex-shrink:0;}
.lhs{text-align:center;padding:10px 16px;border-radius:10px;background:var(--bg);border:1.5px solid var(--rule);}
.lhs-v{font-size:18px;font-weight:700;}
.lhs-l{font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:2px;}

/* ── SECTION SELECTOR (Étage / Bureau) ── */
.section-nav{
  display:flex;align-items:center;gap:8px;
  margin:0 32px 20px;
}
.snav-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:9px 18px;border-radius:10px;
  border:1.5px solid var(--rule);background:var(--white);
  color:var(--muted);font-size:13px;font-weight:600;
  cursor:pointer;transition:all .15s;text-decoration:none;
}
.snav-btn.active,.snav-btn:hover{border-color:var(--red);color:var(--red);background:rgba(200,16,46,.04);}
.snav-btn.active{background:rgba(200,16,46,.08);}

/* ── ÉTAGES GRID ── */
.section-content{padding:0 32px 60px;animation:fadeUp .35s ease .05s both;}
.section-title{font-size:11px;font-weight:800;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:14px;}
.etages-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;}
.etage-card{background:var(--white);border-radius:16px;border:1.5px solid var(--rule);overflow:hidden;text-decoration:none;transition:transform .2s,box-shadow .2s,border-color .2s;display:flex;flex-direction:column;box-shadow:var(--shadow);}
.etage-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,.1);}
.ec-stripe{height:4px;}
.ec-body{padding:18px 20px 0;}
.ec-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;}
.ec-badge{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;}
.ec-nb{text-align:right;}
.ec-nb-val{font-size:22px;font-weight:700;}
.ec-nb-lbl{font-size:9px;color:var(--muted);margin-top:1px;}
.ec-name{font-size:14px;font-weight:700;color:var(--ink);margin-bottom:2px;}
.ec-sub{font-size:10px;color:var(--muted);line-height:1.5;}
.ec-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;margin-top:12px;border-top:1px solid var(--rule);background:var(--bg);}
.ec-stat{text-align:center;}
.ec-stat-val{font-size:14px;font-weight:700;}
.ec-stat-lbl{font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:1px;}
.ec-arrow{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;color:white;flex-shrink:0;}

/* ── PLACEHOLDER (aucun lieu sélectionné) ── */
.placeholder{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  height:60%;gap:18px;text-align:center;color:var(--muted);
  padding:40px;animation:fadeUp .4s ease;
}
.ph-icon{width:90px;height:90px;border-radius:24px;background:var(--white);border:2px dashed #D1D5DB;display:flex;align-items:center;justify-content:center;font-size:36px;}
.ph-title{font-size:18px;font-weight:700;color:var(--ink);}
.ph-sub{font-size:13px;max-width:300px;line-height:1.7;color:var(--muted);}
.ph-hint{display:flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--rule);border-radius:10px;padding:10px 16px;font-size:12px;font-weight:600;color:var(--muted);}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:none;}}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .sidebar{width:56px;}.sidebar:not(.expanded){
    .sb-header,.sb-body{padding:8px;}.sb-title,.sb-search,.type-label span,.li-body,.li-count{display:none;}
    .lieu-item{justify-content:center;}.li-icon{margin:0;}
  }
}
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

<div class="app">

<!-- ══ SIDEBAR : LIEU PHYSIQUE PICKER ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-title">Choisir le lieu physique</div>
    <div class="sb-search">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 10l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      <input type="text" id="searchLieu" placeholder="Rechercher un lieu…" oninput="filterLieux(this.value)" autocomplete="off">
    </div>
  </div>

  <div class="sb-body" id="sbBody">
    <?php foreach($TYPE_LABELS as $type => $tconf):
      if(empty($lieux_par_type[$type])) continue;
    ?>
    <div class="type-section" id="ts_<?=$type?>">
      <div class="type-label" onclick="toggleSection('<?=$type?>')">
        <span class="type-label-icon"><?=$tconf['icon']?></span>
        <span><?=htmlspecialchars($tconf['label'])?></span>
        <svg class="type-chevron" width="10" height="10" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <div class="type-items">
        <?php foreach($lieux_par_type[$type] as $l):
          $nb    = intval($l['nb_articles'] ?? 0);
          $isAct = ($selectedLieu === $l['code']);
        ?>
        <a class="lieu-item <?=$isAct?'active':''?>"
           href="?lieu=<?=urlencode($l['code'])?>"
           data-search="<?=htmlspecialchars(strtolower($l['label'].' '.($l['ville']??'').' '.$type))?>">
          <div class="li-icon" style="<?=$isAct?'':'background:'.($l['color']??'#ccc').'18;'?>"><?=htmlspecialchars($l['icon']??'📍')?></div>
          <div class="li-body">
            <div class="li-name"><?=htmlspecialchars($l['label'])?></div>
            <div class="li-ville"><?=htmlspecialchars($l['ville']??'')?></div>
          </div>
          <span class="li-count <?=$nb===0?'zero':''?>"><?=$nb?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main" id="main">

  <div class="hero-wrap">
    <div class="breadcrumb">
      <a href="dashboard.php">Accueil</a>
      <span>›</span>
      <span>Biens Mobiliers</span>
      <?php if($selectedLieuData): ?>
      <span>›</span>
      <span><?=htmlspecialchars($selectedLieuData['label'])?></span>
      <?php endif; ?>
    </div>
    <a href="dashboard.php" class="btn-retour">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Retour au tableau de bord
    </a>

    <div class="hero">
      <div class="hero-left">
        <div class="hero-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
            <rect x="2" y="7" width="20" height="13" rx="2" stroke="white" stroke-width="1.6"/>
            <path d="M2 11h20M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2" stroke="white" stroke-width="1.6" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <h1>Biens Mobiliers</h1>
          <div class="hero-sub">Inventaire par lieu physique · Tunisair Patrimoine</div>
        </div>
      </div>
      <div class="hero-stats">
        <div class="hs"><div class="hs-val"><?=(int)$total_mobilier?></div><div class="hs-lbl">Articles</div></div>
        <div class="hs"><div class="hs-val"><?=$total_lieux_actifs?></div><div class="hs-lbl">Lieux</div></div>
      </div>
    </div>
  </div>

  <?php if($selectedLieuData): ?>

  <!-- ── EN-TÊTE DU LIEU SÉLECTIONNÉ ── -->
  <div class="lieu-header">
    <div class="lh-avatar" style="background:<?=htmlspecialchars($selectedLieuData['color']??'#C8102E')?>18;border:2px solid <?=htmlspecialchars($selectedLieuData['color']??'#C8102E')?>33;">
      <?=htmlspecialchars($selectedLieuData['icon']??'📍')?>
    </div>
    <div class="lh-info">
      <div class="lh-name"><?=htmlspecialchars($selectedLieuData['label'])?></div>
      <div class="lh-meta">
        <?=htmlspecialchars($selectedLieuData['ville']??'')?>
        · <?=htmlspecialchars(ucfirst(strtolower($selectedLieuData['type_lieu']??'')))?>
      </div>
    </div>
    <div class="lh-stats">
      <?php
        $nb_a   = intval($selectedLieuData['nb_articles']??0);
        $nb_f   = intval($selectedLieuData['nb_fonc']??0);
        $nb_nf  = intval($selectedLieuData['nb_nfonc']??0);
        $color  = $selectedLieuData['color'] ?? '#C8102E';
      ?>
      <div class="lhs"><div class="lhs-v" style="color:<?=htmlspecialchars($color)?>"><?=$nb_a?></div><div class="lhs-l">Articles</div></div>
      <div class="lhs"><div class="lhs-v" style="color:#059669"><?=$nb_f?></div><div class="lhs-l">Fonct.</div></div>
      <div class="lhs"><div class="lhs-v" style="color:#DC2626"><?=$nb_nf?></div><div class="lhs-l">N/Fonct.</div></div>
    </div>
  </div>

  <?php if($selectedLieu === 'SIEGE_TUNISAIR'): ?>
  <!-- ── SIÈGE TUNISAIR → navigation par étage ── -->
  <div class="section-content">
    <div class="section-title">Sélectionner un étage</div>
    <div class="etages-grid">
      <?php foreach($ETAGES as $num => $e):
        $c1 = explode(',',$e['color'])[0];
        $c2 = explode(',',$e['color'])[1];
        $st = $stats_par_etage[$num] ?? ['nb_mobilier'=>0,'nb_bureaux'=>0];
      ?>
      <a href="biens_mobiliers_liste.php?etage=<?=$num?>" class="etage-card" style="border-color:<?=$c1?>22;">
        <div class="ec-stripe" style="background:linear-gradient(90deg,<?=$e['color']?>);"></div>
        <div class="ec-body">
          <div class="ec-top">
            <div class="ec-badge" style="background:linear-gradient(135deg,<?=$e['color']?>);"><?=htmlspecialchars($e['short'])?></div>
            <div class="ec-nb">
              <div class="ec-nb-val" style="color:<?=$c1?>"><?=(int)$st['nb_mobilier']?></div>
              <div class="ec-nb-lbl">articles</div>
            </div>
          </div>
          <div class="ec-name"><?=htmlspecialchars($e['label'])?></div>
          <div class="ec-sub"><?=htmlspecialchars($e['sub'])?></div>
        </div>
        <div class="ec-footer">
          <div class="ec-stat"><div class="ec-stat-val"><?=(int)$st['nb_bureaux']?></div><div class="ec-stat-lbl">Bureaux</div></div>
          <div class="ec-stat"><div class="ec-stat-val"><?=(int)$st['nb_mobilier']?></div><div class="ec-stat-lbl">Mobilier</div></div>
          <div class="ec-arrow" style="background:<?=$c1?>">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- ── AUTRE LIEU → lien direct vers la liste ── -->
  <div class="section-content">
    <div class="section-title">Inventaire du mobilier</div>
    <a href="biens_mobiliers_liste.php?lieu=<?=urlencode($selectedLieu)?>"
       style="display:inline-flex;align-items:center;gap:10px;padding:14px 22px;
              border-radius:12px;background:<?=htmlspecialchars($color)?>;color:white;
              text-decoration:none;font-size:14px;font-weight:600;
              box-shadow:0 4px 16px <?=htmlspecialchars($color)?>40;
              transition:opacity .18s,transform .15s;"
       onmouseover="this.style.opacity='.88';this.style.transform='translateY(-1px)'"
       onmouseout="this.style.opacity='1';this.style.transform='none'">
      <span style="font-size:20px;"><?=htmlspecialchars($selectedLieuData['icon']??'📍')?></span>
      Voir les <?=$nb_a?> articles de <?=htmlspecialchars($selectedLieuData['label'])?>
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
  </div>
  <?php endif; ?>

  <?php else: ?>

  <!-- ── PLACEHOLDER (aucun lieu sélectionné) ── -->
  <div class="placeholder">
    <div class="ph-icon">📍</div>
    <div>
      <div class="ph-title">Choisissez un lieu physique</div>
      <div class="ph-sub">Sélectionnez un site dans le panneau de gauche pour consulter l'inventaire correspondant.</div>
    </div>
    <div class="ph-hint">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <?=count($lieux)?> lieux disponibles · <?=(int)$total_mobilier?> articles au total
    </div>
  </div>

  <?php endif; ?>
</div><!-- /main -->
</div><!-- /app -->

<script>
// ── Filtre recherche lieux ─────────────────────────────────────
function filterLieux(q){
  const sq=(q||'').toLowerCase().trim();
  let anyVisible = {};
  document.querySelectorAll('.lieu-item').forEach(el=>{
    const match = !sq || el.dataset.search.includes(sq);
    el.style.display = match ? '' : 'none';
    if(match){
      // Identifier le type-section parent
      const ts = el.closest('.type-section');
      if(ts) anyVisible[ts.id] = true;
    }
  });
  // Afficher/masquer les sections entières
  document.querySelectorAll('.type-section').forEach(ts=>{
    ts.style.display = (!sq || anyVisible[ts.id]) ? '' : 'none';
    if(sq && anyVisible[ts.id]) ts.classList.remove('collapsed');
  });
}

// ── Toggle collapse par type ───────────────────────────────────
function toggleSection(type){
  const el = document.getElementById('ts_'+type);
  if(el) el.classList.toggle('collapsed');
}

// ── Raccourci clavier : focus recherche ───────────────────────
document.addEventListener('keydown',e=>{
  if((e.key==='/' || e.key==='f' && e.ctrlKey) && document.activeElement.tagName!=='INPUT'){
    e.preventDefault();
    document.getElementById('searchLieu')?.focus();
  }
});
</script>
</body>
</html>