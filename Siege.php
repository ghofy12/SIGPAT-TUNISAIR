<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';

$NORMES = [
  'PDG'=>42,'Président Directeur Général'=>42,'Directeur Général Adjoint'=>42,'DGA'=>42,
  'Secrétaire Général'=>38,'Directeur Central'=>38,'Directeur'=>24,
  'Chef de Département'=>16,'Chef de Service'=>12,'Cadre'=>12,
  'Haute Maîtrise (seul)'=>10,'Haute Maîtrise (2)'=>15,'Secrétaire'=>9,
  'Maîtrise (seul)'=>9,'Maîtrise (2)'=>12,'Maîtrise (3)'=>18,
];

$ETAGES = [
  0=>['label'=>'Rez-de-Chaussée','short'=>'RDC','sub'=>'BOC · DCSI · DCOA · DCP · DCF',  'color'=>'#6D28D9,#7C3AED'],
  1=>['label'=>'1er Étage',      'short'=>'1',  'sub'=>'DCRH · DCA · DSVP · Call Center', 'color'=>'#0F2563,#1D4ED8'],
  2=>['label'=>'2ème Étage',     'short'=>'2',  'sub'=>'DCF · DCP · Catering · DRC',      'color'=>'#701A75,#A21CAF'],
  3=>['label'=>'3ème Étage',     'short'=>'3',  'sub'=>'DCOA · DCP · DRM',                'color'=>'#C8102E,#EF4444'],
  4=>['label'=>'4ème Étage',     'short'=>'4',  'sub'=>'DCC · SPOD · DCRH · DAJ',         'color'=>'#0F2563,#1D4ED8'],
  5=>['label'=>'5ème Étage',     'short'=>'5',  'sub'=>'Direction Générale · SG',         'color'=>'#9B0E23,#C8102E'],
];

$tous = [];
try { $tous = $pdo->query("SELECT * FROM siege_bureaux ORDER BY etage ASC, ref_bureau ASC")->fetchAll(PDO::FETCH_ASSOC); }
catch(Exception $e){}

$by_etage = [];
foreach($tous as $b) $by_etage[intval($b['etage'])][] = $b;

$stats = [];
foreach($ETAGES as $n=>$_){
  $l=$by_etage[$n]??[]; $occ=count(array_filter($l,fn($b)=>!empty($b['mle'])));
  $hn=count(array_filter($l,fn($b)=>!empty($b['l_fonct'])&&isset($NORMES[$b['l_fonct']])&&!empty($b['superficie'])&&(floatval($b['superficie'])-$NORMES[$b['l_fonct']])>2));
  $stats[$n]=['total'=>count($l),'occupes'=>$occ,'libres'=>count($l)-$occ,'m2'=>array_sum(array_column($l,'superficie')),'hn'=>$hn];
}

$total_hn = array_sum(array_column($stats,'hn'));

$ta=array_sum(array_column($stats,'total'));
$to=array_sum(array_column($stats,'occupes'));
$tl=array_sum(array_column($stats,'libres'));
$tm=array_sum(array_column($stats,'m2'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Siège Social · TUNISAIR</title>
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

/* NAV */
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:68px;padding:0 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:42px;width:auto;max-width:120px;object-fit:contain;}
.nav-brand-text{font-size:15px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:18px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:8px 20px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;box-shadow:0 3px 10px rgba(200,16,46,.22);transition:all .2s;}
.btn-deconnexion:hover{background:var(--red-dark);transform:translateY(-1px);}

/* PAGE */
.page{max-width:960px;margin:0 auto;padding:48px 24px 80px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.breadcrumb a{color:var(--muted);text-decoration:none;}.breadcrumb a:hover{color:var(--red);}
.breadcrumb-sep{opacity:.4;}

/* HERO */
.hero{text-align:center;margin-bottom:40px;animation:fadeUp .4s ease;}
.hero-icon{width:64px;height:64px;background:linear-gradient(135deg,var(--red-dark),var(--red));border-radius:18px;display:grid;place-items:center;margin:0 auto 20px;box-shadow:0 8px 24px rgba(200,16,46,.28);}
.hero h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-bottom:10px;}
.hero p{font-size:14px;color:var(--muted);max-width:560px;margin:0 auto;line-height:1.7;}

.btn-retour{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:9px;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:all .18s;margin-bottom:28px;}
.btn-retour:hover{border-color:var(--red);color:var(--red);transform:translateX(-2px);}

/* STAT ROW */
.stat-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:28px;}
.stat-card{background:var(--white);border-radius:14px;border:1.5px solid var(--rule);padding:18px 18px;text-align:center;box-shadow:var(--shadow);}
.sc-val{font-size:28px;font-weight:700;color:var(--navy);}
.sc-lbl{font-size:11px;color:var(--muted);margin-top:5px;}

/* ACCORDION */
.accordion{display:flex;flex-direction:column;gap:10px;}
.acc-item{background:var(--white);border-radius:16px;border:1.5px solid var(--rule);overflow:hidden;box-shadow:var(--shadow);transition:box-shadow .2s;}
.acc-item.open{box-shadow:0 8px 32px rgba(0,0,0,.1);}
.acc-hdr{display:flex;align-items:center;gap:14px;padding:18px 22px;cursor:pointer;user-select:none;transition:background .15s;}
.acc-hdr:hover{background:var(--bg);}
.acc-num{width:50px;height:50px;border-radius:13px;display:grid;place-items:center;font-size:15px;font-weight:800;color:white;flex-shrink:0;}
.acc-info{flex:1;}
.acc-label{font-size:15px;font-weight:700;color:var(--navy);}
.acc-sub{font-size:12px;color:var(--muted);margin-top:2px;}
.acc-sr{display:flex;gap:14px;align-items:center;flex-shrink:0;}
.acc-sv{text-align:center;}
.acc-sv-n{font-size:18px;font-weight:700;color:var(--navy);}
.acc-sv-l{font-size:10px;color:var(--muted);margin-top:1px;}
.acc-pct{font-size:13px;font-weight:700;padding:4px 11px;border-radius:16px;}
.acc-arrow{flex-shrink:0;color:var(--muted);transition:transform .25s;}
.acc-item.open .acc-arrow{transform:rotate(180deg);}
.acc-body{max-height:0;overflow:hidden;transition:max-height .4s ease;}
.acc-item.open .acc-body{max-height:600px;}
.acc-inner{padding:8px 20px 20px;}

/* PROGRESS */
.pbar-track{height:6px;background:var(--bg);border-radius:3px;overflow:hidden;margin-bottom:16px;}
.pbar-fill{height:100%;border-radius:3px;transition:width .6s .1s;}

/* MINI STATS */
.ms-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:16px;}
.ms{background:var(--bg);border-radius:10px;padding:12px 10px;text-align:center;}
.ms-v{font-size:16px;font-weight:700;color:var(--navy);}
.ms-l{font-size:10px;color:var(--muted);margin-top:2px;}

/* GOTO ROW */
.acc-goto-row{display:flex;align-items:center;justify-content:flex-end;}
.goto-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;color:white;font-size:13px;font-weight:600;text-decoration:none;transition:opacity .2s;white-space:nowrap;border:none;cursor:pointer;font-family:inherit;}
.goto-btn:hover{opacity:.88;}

/* DIVIDER */
.acc-divider{height:1px;background:var(--rule);margin-bottom:16px;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:none;}}
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
    <span class="breadcrumb-sep">›</span>
    <span>Siège Social</span>
  </div>

  <div class="hero">
    <div class="hero-icon">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none">
        <rect x="3" y="3" width="18" height="18" rx="2" stroke="white" stroke-width="1.6"/>
        <path d="M3 9h18M9 9v12" stroke="white" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </div>
    <h1>Siège Social — Tunis</h1>
    <p>Vue d'ensemble des étages du siège. Cliquez sur un étage pour consulter les bureaux.</p>
  </div>

  <a href="dashboard.php" class="btn-retour">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Retour au tableau de bord
  </a>

  <!-- STATS GLOBALES -->
  <div class="stat-row" style="animation:fadeUp .4s ease .05s both;">
    <div class="stat-card"><div class="sc-val"><?=$ta?></div><div class="sc-lbl">Total bureaux</div></div>
    <div class="stat-card"><div class="sc-val" style="color:#15803D"><?=$to?></div><div class="sc-lbl">Occupés</div></div>
    <div class="stat-card"><div class="sc-val" style="color:#DC2626"><?=$tl?></div><div class="sc-lbl">Libres</div></div>
    <div class="stat-card"><div class="sc-val"><?=number_format($tm,0,',',' ')?></div><div class="sc-lbl">m² total</div></div>
    <div class="stat-card"><div class="sc-val" style="color:#D97706"><?=$total_hn?></div><div class="sc-lbl">Hors norme</div></div>
  </div>

  <!-- ACCORDION ÉTAGES -->
  <div class="accordion" style="animation:fadeUp .45s ease .15s both;">
    <?php foreach($ETAGES as $num=>$e):
      $s=$stats[$num];
      $pct=$s['total']>0?round($s['occupes']/$s['total']*100):0;
      $c1=explode(',',$e['color'])[0]; $c2=explode(',',$e['color'])[1];
    ?>
    <div class="acc-item" id="ac<?=$num?>">
      <div class="acc-hdr" onclick="tog(<?=$num?>)">
        <div class="acc-num" style="background:linear-gradient(135deg,<?=$e['color']?>)"><?=htmlspecialchars($e['short'])?></div>
        <div class="acc-info">
          <div class="acc-label"><?=htmlspecialchars($e['label'])?></div>
          <div class="acc-sub"><?=htmlspecialchars($e['sub'])?></div>
        </div>
        <div class="acc-sr">
          <div class="acc-sv"><div class="acc-sv-n"><?=$s['total']?></div><div class="acc-sv-l">Total</div></div>
          <div class="acc-sv"><div class="acc-sv-n" style="color:#15803D"><?=$s['occupes']?></div><div class="acc-sv-l">Occupés</div></div>
          <div class="acc-sv"><div class="acc-sv-n" style="color:#DC2626"><?=$s['libres']?></div><div class="acc-sv-l">Libres</div></div>
          <?php if($s['hn']>0): ?>
          <div class="acc-sv"><div class="acc-sv-n" style="color:#D97706"><?=$s['hn']?></div><div class="acc-sv-l">⚠ Norme</div></div>
          <?php endif; ?>
          <span class="acc-pct" style="background:<?=$c1?>18;color:<?=$c1?>"><?=$pct?>%</span>
        </div>
        <svg class="acc-arrow" width="18" height="18" viewBox="0 0 20 20" fill="none">
          <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="acc-body">
        <div class="acc-inner">
          <div class="pbar-track">
            <div class="pbar-fill" style="width:<?=$pct?>%;background:linear-gradient(90deg,<?=$e['color']?>)"></div>
          </div>
          <div class="ms-grid">
            <div class="ms"><div class="ms-v"><?=$s['total']?></div><div class="ms-l">Total</div></div>
            <div class="ms"><div class="ms-v" style="color:#15803D"><?=$s['occupes']?></div><div class="ms-l">Occupés</div></div>
            <div class="ms"><div class="ms-v" style="color:#DC2626"><?=$s['libres']?></div><div class="ms-l">Libres</div></div>
            <div class="ms"><div class="ms-v"><?=number_format($s['m2'],0)?></div><div class="ms-l">m²</div></div>
            <div class="ms"><div class="ms-v" style="color:<?=$s['hn']>0?'#D97706':'#15803D'?>"><?=$s['hn']?></div><div class="ms-l">Hors norme</div></div>
          </div>
          <div class="acc-goto-row">
            <a href="siege_etage.php?etage=<?=$num?>" class="goto-btn" style="background:linear-gradient(135deg,<?=$e['color']?>);">
              Gérer les bureaux
              <svg width="11" height="11" viewBox="0 0 20 20" fill="none"><path d="M8 4l6 6-6 6" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function tog(n){
  const el=document.getElementById('ac'+n);
  const w=el.classList.contains('open');
  document.querySelectorAll('.acc-item').forEach(i=>i.classList.remove('open'));
  if(!w) el.classList.add('open');
}
</script>
</body>
</html>