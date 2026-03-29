<?php
// dashboard.php
require_once 'config.php';

if(!isLoggedIn()) {
    redirect('login.php');
}

function tableExists($pdo, $table) {
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}
function colExists($pdo, $table, $col) {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}

$stats = [];
if(isAdmin()) {
    $stats['biens_fonciers']  = tableExists($pdo,'biens_fonciers')
                                ? $pdo->query("SELECT COUNT(*) FROM biens_fonciers")->fetchColumn() : 0;
    $stats['biens_loues']     = tableExists($pdo,'biens_loues_tunisair')
                                ? $pdo->query("SELECT COUNT(*) FROM biens_loues_tunisair")->fetchColumn() : 0;
    $stats['vehicules']       = (tableExists($pdo,'vehicules') && colExists($pdo,'vehicules','statut'))
                                ? $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut = 'actif'")->fetchColumn() : 0;
   
    $stats['siege_occupes']   = (tableExists($pdo,'siege_bureaux') && colExists($pdo,'siege_bureaux','statut'))
                                ? $pdo->query("SELECT COUNT(*) FROM siege_bureaux WHERE statut IN ('Occupé','Partagé')")->fetchColumn() : 0;
    $stats['materiel_it']     = (tableExists($pdo,'materiel_informatique') && colExists($pdo,'materiel_informatique','statut'))
                                ? $pdo->query("SELECT COUNT(*) FROM materiel_informatique WHERE statut = 'actif'")->fetchColumn() : 0;
    $stats['utilisateurs']    = tableExists($pdo,'users')
                                ? $pdo->query("SELECT COUNT(*) FROM users WHERE actif = 1")->fetchColumn() : 0;
}

$activites = [];
if(tableExists($pdo,'logs_activite')) {
    $query = isAdmin()
        ? "SELECT l.*, u.nom FROM logs_activite l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.date_action DESC LIMIT 10"
        : "SELECT l.*, u.nom FROM logs_activite l LEFT JOIN users u ON l.user_id = u.id WHERE l.user_id = ? ORDER BY l.date_action DESC LIMIT 10";
    $stmt = $pdo->prepare($query);
    isAdmin() ? $stmt->execute() : $stmt->execute([$_SESSION['user_id']]);
    $activites = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de bord · TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

:root {
  --red:      #C8102E;
  --red-dark: #9B0E23;
  --navy:     #0F2563;
  --navy-mid: #1D4ED8;
  --ink:      #1A1A18;
  --muted:    #6B7280;
  --bg:       #F4F6F9;
  --white:    #ffffff;
  --rule:     rgba(0,0,0,.07);
  --shadow:   0 4px 20px rgba(0,0,0,.07);
  --glow-red: rgba(200,16,46,.18);
}

html,body { height:100%; font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--ink); }

/* ══ NAVBAR ══ */
.navbar {
  background:var(--white);
  border-bottom:3px solid var(--red);
  box-shadow:0 2px 10px rgba(0,0,0,.06);
  height:68px; padding:0 28px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:200;
}
.nav-brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
.nav-logo  { height:42px; width:auto; max-width:120px; object-fit:contain; flex-shrink:0; }
.nav-brand-text { font-size:15px; font-weight:700; color:var(--red); letter-spacing:.01em; }
.nav-right { display:flex; align-items:center; gap:16px; }
.nav-user  { font-size:13px; font-weight:500; color:var(--muted); }
.nav-role-badge {
  font-size:10px; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
  padding:4px 10px; border-radius:20px;
  background:rgba(200,16,46,.1); color:var(--red);
}
.btn-deconnexion {
  background:var(--red); color:white; padding:8px 20px; border-radius:8px;
  text-decoration:none; font-size:13px; font-weight:600;
  box-shadow:0 3px 10px var(--glow-red);
  transition:background .2s,transform .15s,box-shadow .2s;
}
.btn-deconnexion:hover { background:var(--red-dark); transform:translateY(-1px); box-shadow:0 5px 16px var(--glow-red); }

/* ══ LAYOUT ══ */
.main { display:flex; min-height:calc(100vh - 68px); }

/* ══ SIDEBAR ══ */
.sidebar {
  width:260px; flex-shrink:0;
  background:var(--white);
  border-right:1px solid var(--rule);
  padding:24px 14px;
  display:flex; flex-direction:column; gap:4px;
  overflow-y:auto;
}
.sidebar-section {
  font-size:10px; font-weight:600; letter-spacing:.15em; text-transform:uppercase;
  color:var(--muted); padding:0 12px; margin:14px 0 8px;
}
.sidebar-section:first-child { margin-top:0; }
.sidebar-link {
  display:flex; align-items:center; gap:11px;
  padding:10px 12px; border-radius:10px;
  text-decoration:none; color:var(--ink);
  font-size:13px; font-weight:400;
  transition:background .18s,color .18s;
  line-height:1.3;
}
.sidebar-link .s-icon {
  width:32px; height:32px; border-radius:8px; flex-shrink:0;
  background:var(--bg); display:grid; place-items:center;
  font-size:15px; transition:background .18s;
}
.sidebar-link:hover { background:rgba(0,0,0,.04); color:var(--ink); }
.sidebar-link:hover .s-icon { background:#ECEEF2; }
.sidebar-link.active {
  background:linear-gradient(130deg,var(--red-dark),var(--red));
  color:white; font-weight:500;
}
.sidebar-link.active .s-icon { background:rgba(255,255,255,.18); }

/* Sub-links (indented) */
.sidebar-sub {
  display:flex; align-items:center; gap:11px;
  padding:8px 12px 8px 22px; border-radius:10px;
  text-decoration:none; color:var(--muted);
  font-size:12px; font-weight:400;
  transition:background .18s,color .18s;
}
.sidebar-sub .s-icon {
  width:26px; height:26px; border-radius:7px; flex-shrink:0;
  background:var(--bg); display:grid; place-items:center; font-size:13px;
}
.sidebar-sub:hover { background:rgba(0,0,0,.04); color:var(--ink); }

/* Collapsible */
.siege-submenu {
  overflow:hidden;
  max-height:0;
  opacity:0;
  transition:max-height .3s ease, opacity .25s ease;
  display:flex;
  flex-direction:column;
  gap:2px;
  padding-left:0;
}
.siege-submenu.open {
  max-height:300px;
  opacity:1;
}
.s-arrow {
  margin-left:auto;
  flex-shrink:0;
  color:var(--muted);
  transition:transform .25s ease;
}
.siege-parent.open .s-arrow { transform:rotate(180deg); }

/* ══ CONTENT ══ */
.content { flex:1; padding:28px 32px; overflow-y:auto; display:flex; flex-direction:column; gap:22px; }

/* ══ WELCOME ══ */
.welcome-banner {
  background:var(--white); border-radius:16px; padding:28px 30px;
  box-shadow:var(--shadow); border:1px solid var(--rule);
  display:flex; align-items:center; justify-content:space-between; gap:20px;
  animation:fadeUp .4s ease both; border-left:4px solid var(--red);
}
.welcome-left h2 { font-size:22px; font-weight:700; color:var(--ink); margin-bottom:6px; }
.welcome-left h2 span { color:var(--red); }
.welcome-left p { font-size:13px; color:var(--muted); font-weight:400; }
.welcome-date {
  font-size:11px; font-weight:600; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted); background:var(--bg); padding:8px 16px;
  border-radius:20px; border:1px solid var(--rule); white-space:nowrap;
}

/* ══ STATS ══ */
.stats-grid {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(185px,1fr));
  gap:14px; animation:fadeUp .4s .06s ease both;
}
.stat-card {
  background:var(--white); border-radius:14px;
  box-shadow:var(--shadow); padding:20px 22px;
  border:1px solid var(--rule);
  transition:transform .2s,box-shadow .2s;
  position:relative; overflow:hidden;
  text-decoration:none; color:inherit; display:block;
}
.stat-card::before {
  content:''; position:absolute; top:-20px; right:-20px;
  width:80px; height:80px; border-radius:50%;
  background:var(--card-glow,rgba(200,16,46,.06)); pointer-events:none;
}
.stat-card:hover { transform:translateY(-3px); box-shadow:0 10px 32px rgba(0,0,0,.10); }
.stat-card:nth-child(1) { --card-glow:rgba(200,16,46,.07);  border-left:3px solid var(--red); }
.stat-card:nth-child(2) { --card-glow:rgba(29,78,216,.07);  border-left:3px solid var(--navy-mid); }
.stat-card:nth-child(3) { --card-glow:rgba(5,150,105,.07);  border-left:3px solid #059669; }
.stat-card:nth-child(4) { --card-glow:rgba(217,119,6,.07);  border-left:3px solid #D97706; }
.stat-card:nth-child(5) { --card-glow:rgba(15,37,99,.07);   border-left:3px solid var(--navy); }
.stat-card:nth-child(6) { --card-glow:rgba(124,58,237,.07); border-left:3px solid #7C3AED; }
.stat-card:nth-child(7) { --card-glow:rgba(16,185,129,.07); border-left:3px solid #10B981; }
.stat-icon {
  width:38px; height:38px; border-radius:10px;
  display:grid; place-items:center; font-size:18px;
  margin-bottom:14px; background:var(--card-glow,rgba(200,16,46,.08));
}
.stat-label { font-size:10px; font-weight:600; letter-spacing:.13em; text-transform:uppercase; color:var(--muted); margin-bottom:8px; }
.stat-value { font-size:32px; font-weight:700; color:var(--ink); line-height:1; }
.stat-sub   { font-size:11px; color:var(--muted); margin-top:5px; }

/* ══ ACTIVITY ══ */
.activity-section {
  background:var(--white); border-radius:14px;
  box-shadow:var(--shadow); padding:24px;
  border:1px solid var(--rule); animation:fadeUp .4s .1s ease both;
}
.section-header {
  display:flex; align-items:center; gap:8px;
  font-size:10px; font-weight:600; letter-spacing:.15em; text-transform:uppercase;
  color:var(--muted); margin-bottom:18px;
}
.section-header::after { content:''; flex:1; height:1px; background:var(--rule); }
.activity-list { display:flex; flex-direction:column; gap:2px; }
.activity-item {
  display:flex; align-items:flex-start; gap:14px;
  padding:13px 14px; border-radius:10px; transition:background .15s;
}
.activity-item:hover { background:var(--bg); }
.activity-dot {
  width:8px; height:8px; border-radius:50%;
  background:var(--red); flex-shrink:0; margin-top:5px;
  box-shadow:0 0 0 3px rgba(200,16,46,.15);
}
.activity-body { flex:1; min-width:0; }
.activity-action {
  font-size:13px; font-weight:500; color:var(--ink);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.activity-meta { font-size:11px; color:var(--muted); margin-top:3px; display:flex; gap:10px; flex-wrap:wrap; }
.activity-time { margin-left:auto; font-size:11px; color:var(--muted); white-space:nowrap; padding-top:2px; flex-shrink:0; }
.empty-activity {
  padding:32px; text-align:center; color:var(--muted); font-size:13px;
  border:1.5px dashed var(--rule); border-radius:10px;
}

/* ══ ANIMATIONS ══ */
@keyframes fadeUp { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;} }

/* ══ RESPONSIVE ══ */
@media(max-width:768px){
  .sidebar{display:none;}
  .stats-grid{grid-template-columns:1fr 1fr;}
  .content{padding:16px;}
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <a href="dashboard.php" class="nav-brand">
    <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
    <span class="nav-brand-text">TUNISAIR — Gestion du Patrimoine</span>
  </a>
  <div class="nav-right">
    <span class="nav-user"><?=htmlspecialchars($_SESSION['prenom'].' '.$_SESSION['nom'])?></span>
    <?php if(!empty($_SESSION['role_nom'])): ?>
      <span class="nav-role-badge"><?=htmlspecialchars($_SESSION['role_nom'])?></span>
    <?php endif; ?>
    <a href="logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</nav>

<!-- MAIN -->
<div class="main">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <span class="sidebar-section">Navigation</span>

    <a href="dashboard.php" class="sidebar-link active">
      <span class="s-icon">🏠</span> Tableau de bord
    </a>
    <a href="biens_fonciers.php" class="sidebar-link">
      <span class="s-icon">🏢</span> Biens Fonciers
    </a>
    <a href="biens_loues.php" class="sidebar-link">
      <span class="s-icon">🔑</span> Biens Loués
    </a>
    <a href="concessions.php" class="sidebar-link">
      <span class="s-icon">✈️</span> Concessions
    </a>
    <a href="vehicules.php" class="sidebar-link">
      <span class="s-icon">🚗</span> Parc Automobile
    </a>

          <a href="siege.php" class="sidebar-link">
      <span class="s-icon">🏗️</span> Siège Social
    </a>

   
    

    <a href="biens_mobiliers_liste.php" class="sidebar-link">
      <span class="s-icon">🪑</span> Biens Mobiliers
    </a>
    <a href="materiel_it.php" class="sidebar-link">
      <span class="s-icon">💻</span> Matériel Informatique
    </a>
    <a href="VisionIA.php" class="sidebar-link">
      <span class="s-icon">📊</span> VisionIA
    </a>

    <?php if(isAdmin()): ?>
    <span class="sidebar-section">Administration</span>
    <a href="users.php" class="sidebar-link">
      <span class="s-icon">👥</span> Utilisateurs
    </a>
    <?php endif; ?>
  </aside>

  <!-- CONTENT -->
  <main class="content">

    <!-- WELCOME -->
    <div class="welcome-banner">
      <div class="welcome-left">
        <h2>Bonjour, <span><?=htmlspecialchars($_SESSION['prenom'])?></span> 👋</h2>
        <p>Département : <?=htmlspecialchars($_SESSION['departement'] ?? '—')?> · Bienvenue sur votre espace de gestion.</p>
      </div>
      <div class="welcome-date"><?=date('l d F Y')?></div>
    </div>

    <!-- STATS -->
    <?php if(isAdmin()): ?>
    <div class="stats-grid">
      <a href="biens_fonciers.php" class="stat-card">
        <div class="stat-icon">🏢</div>
        <div class="stat-label">Biens Fonciers</div>
        <div class="stat-value"><?=$stats['biens_fonciers']?></div>
        <div class="stat-sub">Total enregistrés</div>
      </a>
      <a href="biens_loues.php" class="stat-card">
        <div class="stat-icon">🔑</div>
        <div class="stat-label">Biens Loués</div>
        <div class="stat-value"><?=$stats['biens_loues']?></div>
        <div class="stat-sub">En location</div>
      </a>
      <a href="vehicules.php" class="stat-card">
        <div class="stat-icon">🚗</div>
        <div class="stat-label">Véhicules Actifs</div>
        <div class="stat-value"><?=$stats['vehicules']?></div>
        <div class="stat-sub">Parc automobile</div>
      </a>
    
      <a href="siege.php" class="stat-card">
        <div class="stat-icon">🏗️</div>
        <div class="stat-label">Siège Social</div>
        <div class="stat-value"><?=$stats['siege_occupes']?></div>
        <div class="stat-sub">Bureaux occupés (3ᵉ–5ᵉ)</div>
      </a>
      <a href="materiel_it.php" class="stat-card">
        <div class="stat-icon">💻</div>
        <div class="stat-label">Matériel IT</div>
        <div class="stat-value"><?=$stats['materiel_it']?></div>
        <div class="stat-sub">Actif en service</div>
      </a>
      <a href="users.php" class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-label">Utilisateurs</div>
        <div class="stat-value"><?=$stats['utilisateurs']?></div>
        <div class="stat-sub">Comptes actifs</div>
      </a>
    </div>
    <?php endif; ?>

    <!-- ACTIVITY -->
    <div class="activity-section">
      <div class="section-header">Activités récentes</div>
      <div class="activity-list">
        <?php if(empty($activites)): ?>
          <div class="empty-activity">Aucune activité enregistrée pour le moment.</div>
        <?php else: ?>
          <?php foreach($activites as $act): ?>
          <div class="activity-item">
            <div class="activity-dot"></div>
            <div class="activity-body">
              <div class="activity-action"><?=htmlspecialchars($act['action'])?> — <?=htmlspecialchars($act['module'])?></div>
              <div class="activity-meta">
                <span>👤 <?=htmlspecialchars($act['nom'] ?? '—')?></span>
              </div>
            </div>
            <div class="activity-time"><?=date('d/m/Y H:i',strtotime($act['date_action']))?></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div>

<script>
function toggleSiege(e) {
  e.preventDefault();
  const parent  = document.getElementById('siegeParent');
  const submenu = document.getElementById('siegeSubmenu');
  const isOpen  = submenu.classList.contains('open');
  submenu.classList.toggle('open', !isOpen);
  parent.classList.toggle('open', !isOpen);
}

// Auto-ouvrir si on est sur une page siège
(function(){
  const path = window.location.pathname + window.location.search;
  if(path.includes('siege')) {
    document.getElementById('siegeSubmenu').classList.add('open');
    document.getElementById('siegeParent').classList.add('open');
  }
})();
</script>
</body>
</html>