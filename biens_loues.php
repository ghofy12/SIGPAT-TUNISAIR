<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Biens Loués — TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --red:#C8102E;--red-dark:#9B0E23;
  --navy:#0F2563;--navy-mid:#1D4ED8;
  --ink:#1A1A18;--muted:#6B7280;
  --bg:#F4F6F9;--white:#ffffff;
  --rule:rgba(0,0,0,.08);--shadow:0 4px 24px rgba(0,0,0,.08);
}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}

.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:68px;padding:0 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:42px;width:auto;max-width:120px;object-fit:contain;flex-shrink:0;}
.nav-brand-text{font-size:15px;font-weight:700;color:var(--red);letter-spacing:.01em;}
.nav-right{display:flex;align-items:center;gap:20px;}
.nav-user{font-size:13.5px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:9px 22px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;transition:background .2s,transform .15s,box-shadow .2s;box-shadow:0 3px 10px rgba(200,16,46,.25);}
.btn-deconnexion:hover{background:var(--red-dark);transform:translateY(-1px);}

.page{
  min-height:calc(100vh - 68px);display:flex;align-items:center;justify-content:center;padding:40px 20px;
  background:radial-gradient(ellipse 65% 55% at 10% 15%,rgba(200,16,46,.06) 0%,transparent 65%),
             radial-gradient(ellipse 55% 50% at 90% 85%,rgba(29,78,216,.07) 0%,transparent 65%),var(--bg);
}

.card{background:var(--white);border-radius:20px;padding:48px 44px 44px;width:min(560px,94vw);box-shadow:0 8px 48px rgba(0,0,0,.10);border:1px solid rgba(0,0,0,.05);animation:rise .6s cubic-bezier(.22,.68,0,1.2) both;}
@keyframes rise{from{opacity:0;transform:translateY(24px) scale(.98);}to{opacity:1;transform:none;}}

.card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;gap:10px;flex-wrap:wrap;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.breadcrumb a{color:var(--muted);text-decoration:none;}
.breadcrumb a:hover{color:var(--red);}
.breadcrumb-sep{opacity:.4;}

.btn-retour-login{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:9px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:border-color .18s,color .18s,background .18s,transform .15s;}
.btn-retour-login:hover{background:var(--white);border-color:var(--red);color:var(--red);transform:translateX(-2px);}

.card-title{font-size:22px;font-weight:700;color:var(--ink);letter-spacing:.01em;line-height:1.25;margin-bottom:8px;}
.card-title span{color:var(--red);}
.card-sub{font-size:13.5px;color:var(--muted);font-weight:400;line-height:1.55;margin-bottom:32px;}
.divider{height:1px;background:var(--rule);margin-bottom:28px;}

.choices{display:flex;flex-direction:column;gap:14px;}
.choice{display:flex;align-items:center;gap:16px;padding:20px 22px;border-radius:14px;text-decoration:none;color:white;position:relative;overflow:hidden;transition:transform .22s cubic-bezier(.22,.68,0,1.3),box-shadow .22s ease;}
.choice::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.06);opacity:0;transition:opacity .2s;}
.choice:hover{transform:translateY(-3px) scale(1.01);}
.choice:hover::after{opacity:1;}
.choice:active{transform:scale(.985);}

.choice-tn{background:linear-gradient(130deg,var(--red-dark) 0%,var(--red) 100%);box-shadow:0 8px 30px rgba(200,16,46,.28);}
.choice-tn:hover{box-shadow:0 14px 42px rgba(200,16,46,.38);}
.choice-int{background:linear-gradient(130deg,var(--navy) 0%,var(--navy-mid) 100%);box-shadow:0 8px 30px rgba(29,78,216,.26);}
.choice-int:hover{box-shadow:0 14px 42px rgba(29,78,216,.36);}

.flag-wrap{width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.18);display:grid;place-items:center;font-size:24px;flex-shrink:0;backdrop-filter:blur(4px);}
.flag-text{font-size:15px;font-weight:700;letter-spacing:.06em;}
.choice-body{flex:1;}
.choice-label{font-size:13px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;line-height:1;margin-bottom:5px;}
.choice-meta{font-size:12px;font-weight:300;opacity:.72;}
.arrow{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);display:grid;place-items:center;flex-shrink:0;transition:background .2s,transform .22s cubic-bezier(.22,.68,0,1.3);}
.choice:hover .arrow{background:rgba(255,255,255,.28);transform:translateX(4px);}

.card-footer{margin-top:30px;display:flex;align-items:center;gap:8px;font-size:10px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--ink);opacity:.28;}
.card-footer::before{content:'';display:block;width:18px;height:1px;background:currentColor;}
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
  <div class="card">
    <div class="card-top">
      <div class="breadcrumb">
        <a href="dashboard.php">Accueil</a>
        <span class="breadcrumb-sep">›</span>
        <span>Biens Loués</span>
      </div>
      <a href="dashboard.php" class="btn-retour-login">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Retour au tableau de bord
      </a>
    </div>

    <h1 class="card-title">Biens <span>Loués</span> par TUNISAIR</h1>
    <p class="card-sub">Gestion des baux, contrats de location, suivi budgétaire et facturation des biens immobiliers loués par TUNISAIR auprès de tiers.</p>

    <div class="divider"></div>

    <div class="choices">
      <a href="biens_loues_liste.php?zone=Tunisie" class="choice choice-tn">
        <div class="flag-wrap"><span class="flag-text">TN</span></div>
        <div class="choice-body">
          <div class="choice-label">Tunisie</div>
          <div class="choice-meta">Biens loués sur le marché national</div>
        </div>
        <div class="arrow">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
      </a>
      <a href="biens_loues_liste.php?zone=Etranger" class="choice choice-int">
        <div class="flag-wrap">🌍</div>
        <div class="choice-body">
          <div class="choice-label">Étranger</div>
          <div class="choice-meta">Biens loués sur les marchés internationaux</div>
        </div>
        <div class="arrow">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
      </a>
    </div>
    <p class="card-footer">Accès sécurisé · Session active</p>
  </div>
</div>
</body>
