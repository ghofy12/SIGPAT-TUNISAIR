<?php
// dashboard.php
require_once 'config.php';

if(!isLoggedIn()) {
    redirect('login.php');
}

// ── Modules accessibles au profil de l'utilisateur connecté ──
$modulesAutorises = getAccessibleModulesForUser($pdo);
$codesAutorises   = array_column($modulesAutorises, 'code');
function moduleOK($code, $arr) { return in_array($code, $arr, true); }

/* ══════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════ */
function tableExists($pdo, $table) {
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}
function colExists($pdo, $table, $col) {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 1"); return true; }
    catch (Exception $e) { return false; }
}

/* ══════════════════════════════════════════════════════════
   STATISTIQUES
══════════════════════════════════════════════════════════ */
$stats = [];
$charts = [];

if(isAdmin()) {

    /* ── Biens Fonciers ── */
    if(tableExists($pdo,'biens_fonciers')) {
        $stats['biens_fonciers'] = $pdo->query("SELECT COUNT(*) FROM biens_fonciers")->fetchColumn();
        $stats['bf_actifs']      = colExists($pdo,'biens_fonciers','statut')
            ? $pdo->query("SELECT COUNT(*) FROM biens_fonciers WHERE statut LIKE '%actif%'")->fetchColumn() : 0;
        $stats['bf_loues']       = colExists($pdo,'biens_fonciers','statut')
            ? $pdo->query("SELECT COUNT(*) FROM biens_fonciers WHERE statut LIKE '%lou%'")->fetchColumn() : 0;
        $stats['bf_superficie']  = colExists($pdo,'biens_fonciers','superficie')
            ? (float)$pdo->query("SELECT COALESCE(SUM(superficie),0) FROM biens_fonciers")->fetchColumn() : 0;
        $stats['bf_loyer_total'] = colExists($pdo,'biens_fonciers','loyer_mensuel')
            ? (float)$pdo->query("SELECT COALESCE(SUM(loyer_mensuel),0) FROM biens_fonciers")->fetchColumn() : 0;
        // Répartition statuts pour graphique
        $charts['bf_statuts'] = colExists($pdo,'biens_fonciers','statut')
            ? $pdo->query("SELECT statut, COUNT(*) as n FROM biens_fonciers GROUP BY statut")->fetchAll(PDO::FETCH_ASSOC)
            : [];
    } else {
        $stats['biens_fonciers'] = $stats['bf_actifs'] = $stats['bf_loues'] = 0;
        $stats['bf_superficie']  = $stats['bf_loyer_total'] = 0;
        $charts['bf_statuts'] = [];
    }

    /* ── Biens Loués ── */
    if(tableExists($pdo,'biens_loues_tunisair')) {
        $stats['biens_loues'] = $pdo->query("SELECT COUNT(*) FROM biens_loues_tunisair")->fetchColumn();
        $stats['bl_actifs']   = colExists($pdo,'biens_loues_tunisair','statut')
            ? $pdo->query("SELECT COUNT(*) FROM biens_loues_tunisair WHERE statut LIKE '%actif%' OR statut LIKE '%cours%'")->fetchColumn() : 0;
        $stats['bl_loyer_total'] = (colExists($pdo,'biens_loues_tunisair','loyer_mensuel') && colExists($pdo,'biens_loues_tunisair','devise'))
            ? (float)$pdo->query("SELECT COALESCE(SUM(loyer_mensuel),0) FROM biens_loues_tunisair WHERE devise='TND'")->fetchColumn()
            : (colExists($pdo,'biens_loues_tunisair','loyer_mensuel')
                ? (float)$pdo->query("SELECT COALESCE(SUM(loyer_mensuel),0) FROM biens_loues_tunisair")->fetchColumn() : 0);
        // Contrats expirant dans les 90 jours
        $stats['bl_expirant'] = colExists($pdo,'biens_loues_tunisair','date_fin')
            ? $pdo->query("SELECT COUNT(*) FROM biens_loues_tunisair WHERE date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchColumn() : 0;
        // Répartition par zone
        $charts['bl_zones'] = colExists($pdo,'biens_loues_tunisair','localisation')
            ? $pdo->query("SELECT localisation, COUNT(*) as n FROM biens_loues_tunisair GROUP BY localisation")->fetchAll(PDO::FETCH_ASSOC)
            : [];
    } else {
        $stats['biens_loues'] = $stats['bl_actifs'] = $stats['bl_expirant'] = 0;
        $stats['bl_loyer_total'] = 0;
        $charts['bl_zones'] = [];
    }

    /* ── Concessions ── */
    if(tableExists($pdo,'concessions')) {
        $stats['concessions']  = $pdo->query("SELECT COUNT(*) FROM concessions")->fetchColumn();
        $stats['conc_actives'] = colExists($pdo,'concessions','statut')
            ? $pdo->query("SELECT COUNT(*) FROM concessions WHERE statut LIKE '%activ%' OR statut LIKE '%cours%'")->fetchColumn() : 0;
        $stats['conc_montant'] = colExists($pdo,'concessions','montant_annuel')
            ? (float)$pdo->query("SELECT COALESCE(SUM(montant_annuel),0) FROM concessions")->fetchColumn() : 0;
        $stats['conc_surface'] = colExists($pdo,'concessions','surface')
            ? (float)$pdo->query("SELECT COALESCE(SUM(surface),0) FROM concessions")->fetchColumn() : 0;
    } else {
        $stats['concessions'] = $stats['conc_actives'] = 0;
        $stats['conc_montant'] = $stats['conc_surface'] = 0;
    }

    /* ── Parc Automobile ── (table réelle : parc_auto_affectation) ── */
    if(tableExists($pdo,'parc_auto_affectation')) {
        $stats['vehicules']   = $pdo->query("SELECT COUNT(*) FROM parc_auto_affectation")->fetchColumn();
        $stats['veh_bon_etat']= colExists($pdo,'parc_auto_affectation','etat')
            ? $pdo->query("SELECT COUNT(*) FROM parc_auto_affectation WHERE etat LIKE '%bon%'")->fetchColumn() : 0;
        $stats['veh_mauvais'] = colExists($pdo,'parc_auto_affectation','etat')
            ? $pdo->query("SELECT COUNT(*) FROM parc_auto_affectation WHERE etat LIKE '%mauvais%' OR etat LIKE '%réform%'")->fetchColumn() : 0;
        $charts['veh_etats']  = colExists($pdo,'parc_auto_affectation','etat')
            ? $pdo->query("SELECT etat, COUNT(*) as n FROM parc_auto_affectation WHERE etat IS NOT NULL GROUP BY etat")->fetchAll(PDO::FETCH_ASSOC)
            : [];
    } elseif(tableExists($pdo,'vehicules') && colExists($pdo,'vehicules','statut')) {
        $stats['vehicules']    = $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut='actif'")->fetchColumn();
        $stats['veh_bon_etat'] = 0;
        $stats['veh_mauvais']  = 0;
        $charts['veh_etats']   = [];
    } else {
        $stats['vehicules'] = $stats['veh_bon_etat'] = $stats['veh_mauvais'] = 0;
        $charts['veh_etats'] = [];
    }

    /* ── Siège Social ── */
    if(tableExists($pdo,'siege_bureaux') && colExists($pdo,'siege_bureaux','statut')) {
        $stats['siege_total']          = $pdo->query("SELECT COUNT(*) FROM siege_bureaux")->fetchColumn();
        $stats['siege_occupes']        = $pdo->query("SELECT COUNT(*) FROM siege_bureaux WHERE statut IN ('Occupé','Partagé')")->fetchColumn();
        $stats['siege_libres']         = $pdo->query("SELECT COUNT(*) FROM siege_bureaux WHERE statut='Libre'")->fetchColumn();
        // Taux d'occupation
        $stats['siege_taux']           = $stats['siege_total'] > 0
                                         ? round(($stats['siege_occupes']/$stats['siege_total'])*100) : 0;
        // Répartition pour graphique
        $rows = $pdo->query("SELECT statut, COUNT(*) as n FROM siege_bureaux GROUP BY statut")->fetchAll(PDO::FETCH_ASSOC);
        $charts['siege_statuts'] = $rows;
    } else {
        $stats['siege_total'] = $stats['siege_occupes'] = $stats['siege_libres'] = $stats['siege_taux'] = 0;
        $charts['siege_statuts'] = [];
    }

    /* ── Biens Mobiliers ── */
    if(tableExists($pdo,'materiel_informatique') && colExists($pdo,'materiel_informatique','statut')) {
        $stats['materiel_it']          = $pdo->query("SELECT COUNT(*) FROM materiel_informatique WHERE statut='actif'")->fetchColumn();
        $stats['mat_total']            = $pdo->query("SELECT COUNT(*) FROM materiel_informatique")->fetchColumn();
    } else {
        $stats['materiel_it'] = $stats['mat_total'] = 0;
    }
    // Table biens_mobiliers (si elle existe en plus)
    if(tableExists($pdo,'biens_mobiliers')) {
        $stats['bm_total']             = $pdo->query("SELECT COUNT(*) FROM biens_mobiliers")->fetchColumn();
    } else {
        $stats['bm_total']             = $stats['materiel_it'];
    }

    /* ── Utilisateurs ── */
    $stats['utilisateurs']             = tableExists($pdo,'users')
                                         ? $pdo->query("SELECT COUNT(*) FROM users WHERE actif=1")->fetchColumn() : 0;

    /* ── Loyer mensuel global (BF + BL) ── */
    $stats['loyer_global'] = $stats['bf_loyer_total'] + $stats['bl_loyer_total'];
}

/* ══════════════════════════════════════════════════════
   ALERTES PATRIMOINE — Règles métier TUNISAIR
   (contrats expirant, véhicules à réformer, bureaux libres)
══════════════════════════════════════════════════════ */
$alertes = [];

if (isAdmin()) {
    // Contrats biens loués expirant dans 30 jours
    if (tableExists($pdo, 'biens_loues_tunisair') && colExists($pdo,'biens_loues_tunisair','date_fin')) {
        $rows = $pdo->query("
            SELECT COALESCE(reference, CONCAT('BL-',id)) AS ref,
                   date_fin,
                   COALESCE(type_bien,'—') AS type_bien,
                   COALESCE(localisation,'—') AS localisation
            FROM biens_loues_tunisair
            WHERE date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY date_fin ASC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $alertes[] = [
                'niveau'  => 'critique',
                'icone'   => '🔑',
                'titre'   => 'Contrat expirant sous 30 jours',
                'detail'  => $r['ref'].' — '.$r['type_bien'].' ('.$r['localisation'].')',
                'info'    => 'Échéance : '.date('d/m/Y', strtotime($r['date_fin'])),
                'lien'    => 'biens_loues.php',
            ];
        }
    }
    // Contrats biens loués expirant dans 31–90 jours
    if (tableExists($pdo, 'biens_loues_tunisair') && colExists($pdo,'biens_loues_tunisair','date_fin')) {
        $cnt90 = $pdo->query("
            SELECT COUNT(*) FROM biens_loues_tunisair
            WHERE date_fin BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY)
                               AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ")->fetchColumn();
        if ($cnt90 > 0) {
            $alertes[] = [
                'niveau'  => 'attention',
                'icone'   => '📋',
                'titre'   => $cnt90.' contrat(s) à renouveler sous 90 jours',
                'detail'  => 'Biens loués par TUNISAIR (Tunisie & Étranger)',
                'info'    => 'Vérifier les délais légaux de résiliation',
                'lien'    => 'biens_loues.php',
            ];
        }
    }
    // Véhicules à réformer
    if (isset($stats['veh_mauvais']) && $stats['veh_mauvais'] > 0) {
        $alertes[] = [
            'niveau'  => 'attention',
            'icone'   => '🚗',
            'titre'   => $stats['veh_mauvais'].' véhicule(s) en mauvais état',
            'detail'  => 'Parc automobile — à soumettre à la commission de réforme',
            'info'    => 'Interfaçage : Comptabilité · COSIP · DAG',
            'lien'    => 'par_auto.php',
        ];
    }
    // Bureaux libres au siège
    if (isset($stats['siege_libres']) && $stats['siege_libres'] > 0) {
        $alertes[] = [
            'niveau'  => 'info',
            'icone'   => '🏗️',
            'titre'   => $stats['siege_libres'].' bureau(x) non affecté(s) au Siège',
            'detail'  => 'Taux d\'occupation : '.$stats['siege_taux'].'% — Optimisation possible',
            'info'    => 'Voir la charte d\'affectation des bureaux',
            'lien'    => 'siege.php',
        ];
    }
}

/* ══ Activités récentes ══ */
$activites = [];
if(tableExists($pdo,'logs_activite')) {
    $query = isAdmin()
        ? "SELECT l.*, u.nom FROM logs_activite l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.date_action DESC LIMIT 10"
        : "SELECT l.*, u.nom FROM logs_activite l LEFT JOIN users u ON l.user_id = u.id WHERE l.user_id = ? ORDER BY l.date_action DESC LIMIT 10";
    $stmt = $pdo->prepare($query);
    isAdmin() ? $stmt->execute() : $stmt->execute([$_SESSION['user_id']]);
    $activites = $stmt->fetchAll();
}

/* ══ Données JSON pour Chart.js ══ */
$json_bf_statuts   = json_encode($charts['bf_statuts']   ?? []);
$json_veh_etats    = json_encode($charts['veh_etats']    ?? []);
$json_siege        = json_encode($charts['siege_statuts'] ?? []);
$json_bl_zones     = json_encode($charts['bl_zones']     ?? []);
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

:root {
  --red:       #C8102E;
  --red-dark:  #9B0E23;
  --navy:      #0F2563;
  --navy-mid:  #1D4ED8;
  --green:     #059669;
  --amber:     #D97706;
  --purple:    #7C3AED;
  --teal:      #0891B2;
  --ink:       #1A1A18;
  --muted:     #6B7280;
  --bg:        #F4F6F9;
  --white:     #ffffff;
  --rule:      rgba(0,0,0,.07);
  --shadow:    0 4px 20px rgba(0,0,0,.07);
  --glow-red:  rgba(200,16,46,.18);
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
.siege-submenu {
  overflow:hidden; max-height:0; opacity:0;
  transition:max-height .3s ease, opacity .25s ease;
  display:flex; flex-direction:column; gap:2px; padding-left:0;
}
.siege-submenu.open { max-height:300px; opacity:1; }
.s-arrow { margin-left:auto; flex-shrink:0; color:var(--muted); transition:transform .25s ease; }
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

/* ══ ALERTE EXPIRATION ══ */
.alert-expiration {
  background:#FFF7ED; border:1px solid #FDE68A; border-left:4px solid var(--amber);
  border-radius:12px; padding:14px 20px;
  display:flex; align-items:center; gap:12px;
  font-size:13px; color:#92400E; animation:fadeUp .4s .03s ease both;
}
.alert-expiration strong { font-weight:700; }
.alert-icon { font-size:20px; flex-shrink:0; }

/* ══ SECTION TITLE ══ */
.section-title {
  font-size:10px; font-weight:700; letter-spacing:.18em; text-transform:uppercase;
  color:var(--muted); display:flex; align-items:center; gap:10px;
}
.section-title::after { content:''; flex:1; height:1px; background:var(--rule); }

/* ══ STATS GRID PRINCIPALE ══ */
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
  cursor:pointer;
}
.stat-card::before {
  content:''; position:absolute; top:-20px; right:-20px;
  width:80px; height:80px; border-radius:50%;
  background:var(--card-glow,rgba(200,16,46,.06)); pointer-events:none;
}
.stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 30px rgba(0,0,0,.11); }
.stat-icon {
  width:38px; height:38px; border-radius:10px;
  display:grid; place-items:center; font-size:18px;
  margin-bottom:14px; background:var(--card-glow,rgba(200,16,46,.08));
}
.stat-label { font-size:10px; font-weight:600; letter-spacing:.13em; text-transform:uppercase; color:var(--muted); margin-bottom:8px; }
.stat-value { font-size:32px; font-weight:700; color:var(--ink); line-height:1; }
.stat-sub   { font-size:11px; color:var(--muted); margin-top:5px; }
.stat-pills { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.pill {
  font-size:10px; font-weight:600; padding:3px 8px; border-radius:20px;
}
.pill-green  { background:rgba(5,150,105,.1);  color:var(--green); }
.pill-red    { background:rgba(200,16,46,.1);   color:var(--red); }
.pill-amber  { background:rgba(217,119,6,.1);   color:var(--amber); }
.pill-navy   { background:rgba(15,37,99,.1);    color:var(--navy); }
.pill-teal   { background:rgba(8,145,178,.1);   color:var(--teal); }
.pill-purple { background:rgba(124,58,237,.1);  color:var(--purple); }

/* ══ CHARTS ══ */
.charts-row {
  display:grid; grid-template-columns:1fr 1fr;
  gap:14px; animation:fadeUp .4s .12s ease both;
}
@media(max-width:960px){ .charts-row { grid-template-columns:1fr; } }
.chart-card {
  background:var(--white); border-radius:14px; padding:22px 24px;
  box-shadow:var(--shadow); border:1px solid var(--rule);
}
.chart-header {
  display:flex; align-items:center; gap:8px; margin-bottom:18px;
}
.chart-title { font-size:13px; font-weight:600; color:var(--ink); }
.chart-badge {
  font-size:9px; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  padding:3px 8px; border-radius:20px; background:var(--bg); color:var(--muted);
}
.chart-canvas-wrap { position:relative; height:200px; }

/* ══ ACTIVITY ══ */
.activity-section {
  background:var(--white); border-radius:14px;
  box-shadow:var(--shadow); padding:24px;
  border:1px solid var(--rule); animation:fadeUp .4s .15s ease both;
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

/* ══ ALERTES PATRIMOINE ══ */
.alertes-panel {
  background:var(--white); border-radius:16px;
  box-shadow:var(--shadow); border:1px solid var(--rule);
  padding:22px 26px; animation:fadeUp .4s .09s ease both;
}
.alertes-panel-header {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:16px;
}
.alertes-panel-title {
  display:flex; align-items:center; gap:10px;
  font-size:14px; font-weight:700; color:var(--ink);
}
.alertes-badge {
  font-size:9px; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  padding:3px 10px; border-radius:20px;
  background:rgba(200,16,46,.1); color:var(--red);
}
.alerte-row {
  display:flex; align-items:flex-start; gap:12px;
  border-radius:10px; padding:11px 14px; font-size:13px;
  margin-bottom:6px; border:1px solid transparent;
}
.alerte-row.critique  { background:#FEF2F2; border-color:#FECACA; border-left:3px solid #DC2626; }
.alerte-row.attention { background:#FFFBEB; border-color:#FDE68A; border-left:3px solid #D97706; }
.alerte-row.info      { background:#EFF6FF; border-color:#BFDBFE; border-left:3px solid #2563EB; }
.alerte-icone { font-size:18px; flex-shrink:0; margin-top:1px; }
.alerte-body  { flex:1; }
.alerte-titre { font-weight:600; color:var(--ink); margin-bottom:2px; }
.alerte-detail { font-size:12px; color:var(--muted); }
.alerte-info  { font-size:11px; color:var(--muted); margin-top:2px; font-style:italic; }
.alerte-lien  { flex-shrink:0; font-size:11px; font-weight:600; color:var(--navy); text-decoration:none; align-self:center; }
.alerte-lien:hover { text-decoration:underline; }
.alertes-ok   { display:flex; align-items:center; gap:10px; background:var(--bg); border-radius:10px; padding:14px 16px; font-size:13px; color:var(--green); font-weight:600; }

/* ══ RESPONSIVE ══ */
@media(max-width:768px){
  .sidebar  { display:none; }
  .stats-grid, .kpi-row { grid-template-columns:1fr 1fr; }
  .content  { padding:16px; }
  .welcome-banner { flex-direction:column; align-items:flex-start; }
}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
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

<!-- ══ MAIN ══ -->
<div class="main">

  <!-- ══ SIDEBAR ══ -->
  <aside class="sidebar">
    <span class="sidebar-section">Navigation</span>

    <a href="dashboard.php" class="sidebar-link active">
      <span class="s-icon">🏠</span> Tableau de bord
    </a>
    <?php if(isAdmin() || moduleOK('biens_fonciers', $codesAutorises)): ?>
    <a href="biens_fonciers.php" class="sidebar-link">
      <span class="s-icon">🏢</span> Biens Fonciers
    </a>
    <?php endif; ?>
    <?php if(isAdmin() || moduleOK('biens_loues', $codesAutorises)): ?>
    <a href="biens_loues.php" class="sidebar-link">
      <span class="s-icon">🔑</span> Biens Loués
    </a>
    <?php endif; ?>
    <?php if(isAdmin() || moduleOK('concessions', $codesAutorises)): ?>
    <a href="concessions.php" class="sidebar-link">
      <span class="s-icon">✈️</span> Concessions
    </a>
    <?php endif; ?>
    <?php if(isAdmin() || moduleOK('vehicules', $codesAutorises)): ?>
    <a href="par_auto.php" class="sidebar-link">
      <span class="s-icon">🚗</span> Parc Automobile
    </a>
    <?php endif; ?>
    <?php if(isAdmin() || moduleOK('siege', $codesAutorises)): ?>
    <a href="Siege.php" class="sidebar-link">
      <span class="s-icon">🏗️</span> Siège Social
    </a>
    <?php endif; ?>
    <?php if(isAdmin() || moduleOK('biens_mobiliers', $codesAutorises)): ?>
    <a href="biens_mobiliers.php" class="sidebar-link">
      <span class="s-icon">🪑</span> Biens Mobiliers
    </a>
    <?php endif; ?>


    <?php if(isAdmin()): ?>
    <span class="sidebar-section">Administration</span>
    <a href="gestion_profils.php" class="sidebar-link">
      <span class="s-icon">🔐</span> Profils & Accès
    </a>
    <?php endif; ?>
  </aside>

  <!-- ══ CONTENT ══ -->
  <main class="content">

    <!-- WELCOME -->
    <div class="welcome-banner">
      <div class="welcome-left">
        <h2>Bonjour, <span><?=htmlspecialchars($_SESSION['prenom'])?></span> 👋</h2>
        <p>Département : <?=htmlspecialchars($_SESSION['departement'] ?? '—')?> · Bienvenue sur votre espace de gestion du patrimoine.</p>
      </div>
      <div class="welcome-date"><?=date('l d F Y')?></div>
    </div>

    <?php if(isAdmin()): ?>

    

    <!-- ══ SECTION : PATRIMOINE IMMOBILIER ══ -->
    <div class="section-title">Patrimoine Immobilier</div>
    <div class="stats-grid">

      <!-- Biens Fonciers -->
      <a href="biens_fonciers.php" class="stat-card" style="--card-glow:rgba(200,16,46,.07);">
        <div class="stat-icon">🏢</div>
        <div class="stat-label">Biens Fonciers</div>
        <div class="stat-value"><?=$stats['biens_fonciers']?></div>
        <div class="stat-pills">
          <span class="pill pill-green"><?=$stats['bf_actifs']?> actifs</span>
          <span class="pill pill-amber"><?=$stats['bf_loues']?> loués</span>
        </div>
        <div class="stat-sub">
          <?=number_format($stats['bf_superficie'],0,',',' ')?> m² total
        </div>
      </a>

      <!-- Biens Loués -->
      <a href="biens_loues.php" class="stat-card" style="--card-glow:rgba(8,145,178,.07);">
        <div class="stat-icon">🔑</div>
        <div class="stat-label">Biens Loués</div>
        <div class="stat-value"><?=$stats['biens_loues']?></div>
        <div class="stat-pills">
          <span class="pill pill-green"><?=$stats['bl_actifs']?> en cours</span>
          <?php if($stats['bl_expirant'] > 0): ?>
          <span class="pill pill-amber"><?=$stats['bl_expirant']?> expirant</span>
          <?php endif; ?>
        </div>
        <div class="stat-sub">Locations actives</div>
      </a>

      <!-- Concessions -->
      <a href="concessions.php" class="stat-card" style="--card-glow:rgba(124,58,237,.07);">
        <div class="stat-icon">✈️</div>
        <div class="stat-label">Concessions</div>
        <div class="stat-value"><?=$stats['concessions']?></div>
        <div class="stat-pills">
          <span class="pill pill-purple"><?=$stats['conc_actives']?> actives</span>
        </div>
        <div class="stat-sub">
          <?=number_format($stats['conc_surface'],0,',',' ')?> m² concédés
        </div>
      </a>

    </div>

    <!-- ══ SECTION : ACTIFS PHYSIQUES ══ -->
    <div class="section-title">Actifs Physiques</div>
    <div class="stats-grid">

      <!-- Parc Automobile -->
      <a href="par_auto.php" class="stat-card" style="--card-glow:rgba(15,37,99,.07);">
        <div class="stat-icon">🚗</div>
        <div class="stat-label">Parc Automobile</div>
        <div class="stat-value"><?=$stats['vehicules']?></div>
        <div class="stat-pills">
          <span class="pill pill-green"><?=$stats['veh_bon_etat']?> bon état</span>
          <?php if($stats['veh_mauvais'] > 0): ?>
          <span class="pill pill-red"><?=$stats['veh_mauvais']?> à réformer</span>
          <?php endif; ?>
        </div>
        <div class="stat-sub">Véhicules enregistrés</div>
      </a>

      <!-- Siège Social -->
      <a href="siege.php" class="stat-card" style="--card-glow:rgba(5,150,105,.07);">
        <div class="stat-icon">🏗️</div>
        <div class="stat-label">Siège Social</div>
        <div class="stat-value"><?=$stats['siege_occupes']?></div>
        <div class="stat-pills">
          <span class="pill pill-green"><?=$stats['siege_occupes']?> occupés</span>
          <span class="pill pill-teal"><?=$stats['siege_libres']?> libres</span>
        </div>
        <div class="stat-sub">Sur <?=$stats['siege_total']?> bureaux (3ᵉ–5ᵉ étage)</div>
      </a>

      <!-- Biens Mobiliers / Matériel IT -->
      <a href="biens_mobiliers.php" class="stat-card" style="--card-glow:rgba(217,119,6,.07);">
        <div class="stat-icon">🪑</div>
        <div class="stat-label">Biens Mobiliers</div>
        <div class="stat-value"><?=$stats['bm_total']?></div>
        <div class="stat-pills">
          <span class="pill pill-amber"><?=$stats['materiel_it']?> IT actifs</span>
        </div>
        <div class="stat-sub">Équipements & matériel</div>
      </a>

      <!-- Utilisateurs -->
      <a href="users.php" class="stat-card" style="--card-glow:rgba(200,16,46,.07);">
        <div class="stat-icon">👥</div>
        <div class="stat-label">Utilisateurs</div>
        <div class="stat-value"><?=$stats['utilisateurs']?></div>
        <div class="stat-pills">
          <span class="pill pill-green">Actifs</span>
        </div>
        <div class="stat-sub">Comptes actifs</div>
      </a>

    </div>

    <!-- ══ GRAPHIQUES ══ -->
    <div class="section-title">Analyses Visuelles</div>
    <div class="charts-row">

      <!-- Graphique 1 : Répartition Biens Fonciers par statut -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Biens Fonciers — Répartition par statut</div>
          <span class="chart-badge">Donut</span>
        </div>
        <div class="chart-canvas-wrap">
          <canvas id="chartBF"></canvas>
        </div>
      </div>

      <!-- Graphique 2 : Parc Automobile par état -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Parc Automobile — État des véhicules</div>
          <span class="chart-badge">Barres</span>
        </div>
        <div class="chart-canvas-wrap">
          <canvas id="chartVeh"></canvas>
        </div>
      </div>

      <!-- Graphique 3 : Siège Social -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Siège Social — Occupation des bureaux</div>
          <span class="chart-badge">Donut</span>
        </div>
        <div class="chart-canvas-wrap">
          <canvas id="chartSiege"></canvas>
        </div>
      </div>

      <!-- Graphique 4 : Biens Loués par zone -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Biens Loués — Répartition par zone</div>
          <span class="chart-badge">Barres</span>
        </div>
        <div class="chart-canvas-wrap">
          <canvas id="chartBL"></canvas>
        </div>
      </div>

    </div>

    <?php endif; ?>

    <!-- ══ ACTIVITÉS RÉCENTES ══ -->
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

<!-- ══ SCRIPTS ══ -->
<script>
/* ── Sidebar Siège toggle ── */
function toggleSiege(e) {
  e.preventDefault();
  const parent  = document.getElementById('siegeParent');
  const submenu = document.getElementById('siegeSubmenu');
  const isOpen  = submenu.classList.contains('open');
  submenu.classList.toggle('open', !isOpen);
  parent.classList.toggle('open', !isOpen);
}
(function(){
  const path = window.location.pathname + window.location.search;
  if(path.includes('siege')) {
    const sm = document.getElementById('siegeSubmenu');
    const sp = document.getElementById('siegeParent');
    if(sm) sm.classList.add('open');
    if(sp) sp.classList.add('open');
  }
})();

/* ═══════════════════════════════════════════════
   CHART.JS — Couleurs TUNISAIR
═══════════════════════════════════════════════ */
const COLORS = ['#C8102E','#0F2563','#059669','#D97706','#0891B2','#7C3AED','#6B7280','#9B0E23','#1D4ED8'];

Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color        = '#6B7280';

/* ── Helper : données PHP → JS ── */
const dataBF    = <?= $json_bf_statuts ?>;
const dataVeh   = <?= $json_veh_etats ?>;
const dataSiege = <?= $json_siege ?>;
const dataBL    = <?= $json_bl_zones ?>;

function buildDonut(id, data, emptyMsg='Aucune donnée') {
  const canvas = document.getElementById(id);
  if(!canvas) return;
  if(!data || !data.length) {
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#6B7280'; ctx.font = '13px DM Sans';
    ctx.textAlign = 'center';
    ctx.fillText(emptyMsg, canvas.width/2, canvas.height/2);
    return;
  }
  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: data.map(d => d.statut || d.localisation || d.etat || 'N/A'),
      datasets: [{ data: data.map(d => d.n), backgroundColor: COLORS, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      cutout: '62%',
      plugins: {
        legend: { position: 'right', labels: { font: { size: 11 }, boxWidth: 12, padding: 10 } },
        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } }
      }
    }
  });
}

function buildBar(id, data, labelKey, emptyMsg='Aucune donnée') {
  const canvas = document.getElementById(id);
  if(!canvas) return;
  if(!data || !data.length) {
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#6B7280'; ctx.font = '13px DM Sans';
    ctx.textAlign = 'center';
    ctx.fillText(emptyMsg, canvas.width/2, canvas.height/2);
    return;
  }
  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: data.map(d => d[labelKey] || 'N/A'),
      datasets: [{
        data: data.map(d => d.n),
        backgroundColor: COLORS,
        borderRadius: 6,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ` ${ctx.raw} véhicule(s)` } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { precision: 0, font: { size: 11 } } }
      }
    }
  });
}

/* ── Rendu des graphiques ── */
buildDonut('chartBF',    dataBF,    'Aucun bien foncier enregistré');
buildBar  ('chartVeh',   dataVeh,   'etat', 'Aucun véhicule enregistré');
buildDonut('chartSiege', dataSiege, 'Aucun bureau enregistré');
buildBar  ('chartBL',    dataBL,    'localisation', 'Aucun bien loué enregistré');

</script>
</body>
</html>