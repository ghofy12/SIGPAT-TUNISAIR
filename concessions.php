<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
requireModuleAccess($pdo, 'concessions');
$username = $_SESSION['username'] ?? 'Utilisateur';
$canCreate = hasModulePermission($pdo, 'concessions', 'create');

// ── Fetch all airports with stats, grouped by bailleur then airport ──
$stmt = $pdo->query("
    SELECT
        aeroport,
        bailleur,
        zone_type,
        COUNT(*) AS nb_concessions,
        SUM(CASE WHEN statut='Active' THEN 1 ELSE 0 END) AS nb_actives,
        SUM(surface) AS superficie,
        SUM(montant_annuel) AS montant_total
    FROM concessions
    WHERE aeroport IS NOT NULL AND aeroport != ''
    GROUP BY aeroport, bailleur, zone_type
    ORDER BY bailleur, aeroport
");
$rows = $stmt->fetchAll();

// Group by bailleur
$byBailleur = [];
foreach($rows as $r){
    $b = trim($r['bailleur'] ?? '') ?: '—';
    $byBailleur[$b][] = $r;
}

// Stats globales
$stmtStats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN statut='Active' THEN 1 ELSE 0 END) AS actives,
        SUM(montant_annuel) AS budget,
        COUNT(DISTINCT aeroport) AS nb_aeroports
    FROM concessions
");
$gs = $stmtStats->fetch();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Concessions · TUNISAIR</title>
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
  --rule:rgba(0,0,0,.07);
  --green:#059669;--orange:#D97706;
}
html,body{min-height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}

/* ── NAV ── */
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:16px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:7px 18px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;transition:background .2s;}
.btn-deconnexion:hover{background:var(--red-dark);}

.page{max-width:1040px;margin:0 auto;padding:40px 24px 80px;}

/* ── BREADCRUMB ── */
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:8px;}
.breadcrumb a{color:var(--muted);text-decoration:none;transition:color .15s;}
.breadcrumb a:hover{color:var(--red);}

/* ── HEADER ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;animation:fadeUp .35s ease;}
.page-header-left{display:flex;align-items:center;gap:16px;}
.page-icon{width:52px;height:52px;background:linear-gradient(135deg,var(--red-dark),var(--red));border-radius:14px;display:grid;place-items:center;box-shadow:0 6px 20px rgba(200,16,46,.28);flex-shrink:0;}
.page-title{font-size:24px;font-weight:700;letter-spacing:-.01em;}
.page-sub{font-size:13px;color:var(--muted);margin-top:3px;}

/* ── GLOBAL STATS ── */
.global-stats{display:flex;gap:12px;margin-bottom:36px;animation:fadeUp .35s ease .05s both;}
.gstat{flex:1;background:var(--white);border-radius:14px;padding:16px 18px;border:1px solid var(--rule);box-shadow:0 2px 8px rgba(0,0,0,.04);}
.gstat-label{font-size:9px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.gstat-val{font-size:22px;font-weight:700;color:var(--ink);}
.gstat-val.red{color:var(--red);}
.gstat-val.green{color:var(--green);}
.gstat-val.small{font-size:17px;}

/* ── BAILLEUR TABS ── */
.bailleur-tabs{display:flex;gap:8px;margin-bottom:20px;animation:fadeUp .35s ease .08s both;}
.btab{padding:9px 18px;border-radius:10px;font-size:12px;font-weight:700;letter-spacing:.06em;cursor:pointer;border:2px solid var(--rule);background:var(--white);color:var(--muted);transition:all .2s;text-decoration:none;}
.btab:hover{border-color:currentColor;}
.btab.oaca{color:var(--red);}
.btab.oaca.active{background:linear-gradient(135deg,var(--red-dark),var(--red));color:white;border-color:transparent;box-shadow:0 4px 14px rgba(200,16,46,.25);}
.btab.tav{color:var(--navy-mid);}
.btab.tav.active{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:white;border-color:transparent;box-shadow:0 4px 14px rgba(29,78,216,.22);}
.btab.all.active{background:var(--ink);color:white;border-color:transparent;box-shadow:0 4px 14px rgba(0,0,0,.18);}

/* ── BAILLEUR SECTION ── */
.bailleur-section{margin-bottom:36px;animation:fadeUp .38s ease .1s both;}
.bailleur-header{display:flex;align-items:center;gap:14px;margin-bottom:16px;}
.bailleur-pill{height:36px;padding:0 14px;border-radius:10px;display:flex;align-items:center;gap:7px;font-size:11px;font-weight:800;letter-spacing:.1em;color:white;flex-shrink:0;}
.bailleur-pill.oaca{background:linear-gradient(135deg,var(--red-dark),var(--red));box-shadow:0 4px 12px rgba(200,16,46,.25);}
.bailleur-pill.tav{background:linear-gradient(135deg,var(--navy),var(--navy-mid));box-shadow:0 4px 12px rgba(29,78,216,.22);}
.bailleur-pill.other{background:linear-gradient(135deg,#374151,#6B7280);}
.bailleur-info{}
.bailleur-name{font-size:16px;font-weight:700;color:var(--ink);}
.bailleur-count{font-size:12px;color:var(--muted);margin-top:1px;}
.bailleur-line{flex:1;height:2px;border-radius:1px;background:var(--rule);}

/* ── AIRPORT GRID ── */
.airport-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;}

/* ── AIRPORT CARD ── */
.airport-card{background:var(--white);border-radius:16px;border:1px solid var(--rule);padding:0;text-decoration:none;display:flex;flex-direction:column;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s,border-color .2s;cursor:pointer;}
.airport-card:hover{transform:translateY(-3px);}
.airport-card.oaca-card:hover{box-shadow:0 10px 32px rgba(200,16,46,.14);border-color:transparent;}
.airport-card.tav-card:hover{box-shadow:0 10px 32px rgba(29,78,216,.14);border-color:transparent;}
.airport-card.other-card:hover{box-shadow:0 10px 32px rgba(0,0,0,.10);border-color:transparent;}

/* Stripe top */
.card-stripe{height:4px;width:100%;flex-shrink:0;}
.oaca-card .card-stripe{background:linear-gradient(90deg,var(--red-dark),var(--red));}
.tav-card .card-stripe{background:linear-gradient(90deg,var(--navy),var(--navy-mid));}
.other-card .card-stripe{background:linear-gradient(90deg,#374151,#6B7280);}

.card-body{padding:18px 18px 14px;display:flex;flex-direction:column;gap:12px;flex:1;}

.ap-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;}
.ap-icon{font-size:26px;line-height:1;}
.ap-statut{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;}
.ap-statut.ok{color:var(--green);background:#DCFCE7;}
.ap-statut.warn{color:var(--orange);background:#FEF3C7;}
.ap-statut.none{color:var(--muted);background:var(--bg);}

.ap-main{}
.ap-name{font-size:14px;font-weight:700;color:var(--ink);line-height:1.4;}
.ap-bailleur{font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;margin-top:3px;}
.oaca-card .ap-bailleur{color:var(--red);}
.tav-card .ap-bailleur{color:var(--navy-mid);}
.other-card .ap-bailleur{color:var(--muted);}

.ap-budget{font-size:11px;font-weight:600;color:var(--muted);display:flex;align-items:center;gap:5px;}
.ap-budget strong{color:var(--ink);font-size:12px;}

/* Stats row */
.ap-stats{display:flex;border-top:1px solid var(--rule);background:var(--bg);}
.ap-stat{flex:1;padding:9px 0;text-align:center;}
.ap-stat+.ap-stat{border-left:1px solid var(--rule);}
.ap-stat-val{font-size:16px;font-weight:700;color:var(--ink);}
.oaca-card .ap-stat-val.accent{color:var(--red);}
.tav-card .ap-stat-val.accent{color:var(--navy-mid);}
.ap-stat-lbl{font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:2px;}

/* Hover arrow */
.ap-arrow{position:absolute;bottom:52px;right:14px;width:26px;height:26px;border-radius:8px;background:rgba(0,0,0,.04);display:flex;align-items:center;justify-content:center;transition:all .15s;opacity:0;}
.airport-card:hover .ap-arrow{opacity:1;}
.oaca-card:hover .ap-arrow{background:rgba(200,16,46,.1);}
.tav-card:hover .ap-arrow{background:rgba(29,78,216,.1);}

/* ── EMPTY ── */
.empty-db{text-align:center;padding:60px 24px;color:var(--muted);}
.empty-db h3{font-size:18px;font-weight:700;color:var(--ink);margin-bottom:8px;}
.empty-db p{font-size:14px;line-height:1.6;}
.btn-new{display:inline-flex;align-items:center;gap:8px;margin-top:20px;padding:11px 22px;background:var(--red);color:white;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;box-shadow:0 4px 14px rgba(200,16,46,.28);}

/* ── BACK BTN ── */
.btn-retour{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:9px;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:all .18s;margin-bottom:24px;}
.btn-retour:hover{border-color:var(--red);color:var(--red);}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}
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

<div class="page">

  <div class="breadcrumb">
    <a href="dashboard.php">Accueil</a>
    <span>›</span>
    <span>Concessions</span>
  </div>

  <div class="page-header">
    <div class="page-header-left">
      <div class="page-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M3 21h18M9 21V7l7-4v18" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 11h7M9 15h7" stroke="white" stroke-width="1.3" stroke-linecap="round"/></svg>
      </div>
      <div>
        <div class="page-title">Gestion des Concessions</div>
        <div class="page-sub">Occupation domaniale · OACA · TAV · Aéroports tunisiens</div>
      </div>
    </div>
  </div>

  <a href="dashboard.php" class="btn-retour">
    <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Retour au tableau de bord
  </a>

  <!-- Stats globales -->
  <div class="global-stats">
    <div class="gstat">
      <div class="gstat-label">Total concessions</div>
      <div class="gstat-val red"><?=(int)($gs['total']??0)?></div>
    </div>
    <div class="gstat">
      <div class="gstat-label">Actives</div>
      <div class="gstat-val green"><?=(int)($gs['actives']??0)?></div>
    </div>
    <div class="gstat">
      <div class="gstat-label">Aéroports</div>
      <div class="gstat-val"><?=(int)($gs['nb_aeroports']??0)?></div>
    </div>
    <div class="gstat">
      <div class="gstat-label">Budget annuel HT</div>
      <div class="gstat-val small"><?=number_format((float)($gs['budget']??0),0,'.',' ')?> <span style="font-size:12px;color:var(--muted);font-weight:600;">TND</span></div>
    </div>
  </div>

  <?php if(empty($rows)): ?>
  <div class="empty-db">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" style="margin:0 auto 16px;display:block;"><path d="M3 21h18M9 21V7l7-4v18" stroke="#D1D5DB" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <h3>Aucune concession enregistrée</h3>
    <p><?=$canCreate ? "Commencez par ajouter une concession via la liste des aéroports." : "Aucune concession n'a encore été enregistrée."?></p>
    <?php if($canCreate): ?>
    <a href="concessions_liste.php" class="btn-new">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
      Ajouter une concession
    </a>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <?php foreach($byBailleur as $bailleur => $airports):
    $b = strtolower($bailleur);
    $isOaca = strpos($b,'oaca') !== false;
    $isTav  = strpos($b,'tav')  !== false;
    $cssClass = $isOaca ? 'oaca' : ($isTav ? 'tav' : 'other');
    $totalConcs = array_sum(array_column($airports,'nb_concessions'));
    $icon = $isOaca ? '🇹🇳' : ($isTav ? '🌍' : '🏢');
  ?>
  <div class="bailleur-section">
    <div class="bailleur-header">
      <div class="bailleur-pill <?=$cssClass?>">
        <?=$icon?> <?=htmlspecialchars($bailleur)?>
      </div>
      <div class="bailleur-info">
        <div class="bailleur-name"><?=htmlspecialchars($bailleur)?></div>
        <div class="bailleur-count">
          <?=count($airports)?> aéroport<?=count($airports)>1?'s':''?> ·
          <?=$totalConcs?> concession<?=$totalConcs>1?'s':''?>
        </div>
      </div>
      <div class="bailleur-line"></div>
    </div>

    <div class="airport-grid">
      <?php foreach($airports as $ap):
        $nbA = (int)$ap['nb_actives'];
        $nbT = (int)$ap['nb_concessions'];
        if($nbT === 0){
          $expClass='none'; $expLabel='Vide';
        } elseif($nbA === $nbT){
          $expClass='ok'; $expLabel=$nbA.' active'.($nbA>1?'s':'');
        } else {
          $expClass='warn'; $expLabel=$nbA.'/'.$nbT.' actives';
        }
        $cardClass = $isOaca ? 'oaca-card' : ($isTav ? 'tav-card' : 'other-card');
        // Link to concessions_liste.php filtered by this airport
        $url = 'concessions_liste.php?aeroport='.urlencode($ap['aeroport']);
        $arrowColor = $isOaca ? '#C8102E' : ($isTav ? '#1D4ED8' : '#6B7280');
      ?>
      <a href="<?=htmlspecialchars($url)?>" class="airport-card <?=$cardClass?>">
        <div class="card-stripe"></div>
        <div class="card-body">
          <div class="ap-top">
            <span class="ap-icon"><?=$icon?></span>
            <span class="ap-statut <?=$expClass?>"><?=$expLabel?></span>
          </div>
          <div class="ap-main">
            <div class="ap-name"><?=htmlspecialchars($ap['aeroport'])?></div>
            <div class="ap-bailleur"><?=htmlspecialchars($bailleur)?></div>
          </div>
          <?php if($ap['montant_total']): ?>
          <div class="ap-budget">
            <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 4.5v7M5.5 6.5h4a1 1 0 010 2H7a1 1 0 000 2h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
            <strong><?=number_format((float)$ap['montant_total'],0,'.',' ')?></strong>&nbsp;TND/an HT
          </div>
          <?php endif; ?>
          <!-- Hover arrow -->
          <div class="ap-arrow">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M4 8h8M9 5l3 3-3 3" stroke="<?=$arrowColor?>" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
        </div>
        <!-- Stats footer -->
        <div class="ap-stats">
          <div class="ap-stat">
            <div class="ap-stat-val accent"><?=$nbT?></div>
            <div class="ap-stat-lbl">Concessions</div>
          </div>
          <div class="ap-stat">
            <div class="ap-stat-val"><?=$ap['superficie'] ? number_format((float)$ap['superficie'],0,'.',' ') : '—'?></div>
            <div class="ap-stat-lbl">m²</div>
          </div>
          <div class="ap-stat">
            <div class="ap-stat-val"><?=$nbA?></div>
            <div class="ap-stat-lbl">Actives</div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>
</div>
</body>
</html>