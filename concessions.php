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
  --rule:rgba(0,0,0,.07);--shadow:0 4px 20px rgba(0,0,0,.07);
}
html,body{min-height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}

.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:68px;padding:0 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:42px;width:auto;max-width:120px;object-fit:contain;}
.nav-brand-text{font-size:15px;font-weight:700;color:var(--red);}
.nav-right{display:flex;align-items:center;gap:18px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:8px 20px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;box-shadow:0 3px 10px rgba(200,16,46,.22);transition:all .2s;}
.btn-deconnexion:hover{background:var(--red-dark);transform:translateY(-1px);}

.page{max-width:900px;margin:0 auto;padding:52px 24px 80px;}

.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.breadcrumb a{color:var(--muted);text-decoration:none;}
.breadcrumb a:hover{color:var(--red);}
.breadcrumb-sep{opacity:.4;}

.hero{text-align:center;margin-bottom:52px;animation:fadeUp .4s ease;}
.hero-icon{width:64px;height:64px;background:linear-gradient(135deg,var(--red-dark),var(--red));border-radius:18px;display:grid;place-items:center;margin:0 auto 20px;box-shadow:0 8px 24px rgba(200,16,46,.28);}
.hero h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-bottom:10px;}
.hero p{font-size:14px;color:var(--muted);max-width:520px;margin:0 auto;line-height:1.7;}

.zone-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:40px;}
.zone-card{border-radius:20px;padding:38px 32px;text-decoration:none;display:flex;flex-direction:column;align-items:flex-start;gap:18px;position:relative;overflow:hidden;transition:transform .22s,box-shadow .22s;border:none;cursor:pointer;}
.zone-card:hover{transform:translateY(-4px);}
.zone-card-nat{background:linear-gradient(140deg,var(--red-dark) 0%,var(--red) 100%);box-shadow:0 12px 40px rgba(200,16,46,.28);}
.zone-card-nat:hover{box-shadow:0 20px 52px rgba(200,16,46,.36);}
.zone-card-int{background:linear-gradient(140deg,var(--navy) 0%,var(--navy-mid) 100%);box-shadow:0 12px 40px rgba(29,78,216,.26);}
.zone-card-int:hover{box-shadow:0 20px 52px rgba(29,78,216,.34);}

.zone-card-icon{font-size:44px;line-height:1;}
.zone-card-content{flex:1;}
.zone-card-title{font-size:20px;font-weight:700;color:white;margin-bottom:6px;}
.zone-card-sub{font-size:13px;color:rgba(255,255,255,.72);line-height:1.55;}
.zone-card-arrow{width:38px;height:38px;background:rgba(255,255,255,.18);border-radius:10px;display:grid;place-items:center;flex-shrink:0;transition:background .18s;}
.zone-card:hover .zone-card-arrow{background:rgba(255,255,255,.28);}
.zone-card-footer{display:flex;align-items:center;justify-content:space-between;width:100%;}

/* Decorative circle */
.zone-card::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;}

.btn-retour{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:9px;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:all .18s;margin-bottom:32px;}
.btn-retour:hover{border-color:var(--red);color:var(--red);transform:translateX(-2px);}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:none;}}
.zone-grid{animation:fadeUp .45s ease .1s both;}
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
    <span>Concessions</span>
  </div>

  <div class="hero">
    <div class="hero-icon">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none"><path d="M3 21h18M9 21V7l7-4v18" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 11h7M9 15h7" stroke="white" stroke-width="1.4" stroke-linecap="round"/></svg>
    </div>
    <h1>Gestion des Concessions</h1>
    <p>Suivi des concessions aéroportuaires nationales et internationales — occupation domaniale, bons de commande, facturation et obligations contractuelles.</p>
  </div>

  <a href="dashboard.php" class="btn-retour">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Retour au tableau de bord
  </a>

  <div class="zone-grid">
    <!-- Nationale -->
    <a href="concessions_liste.php?zone=Nationale" class="zone-card zone-card-nat">
      <div class="zone-card-icon">🇹🇳</div>
      <div class="zone-card-content">
        <div class="zone-card-title">Concessions Nationales</div>
        <div class="zone-card-sub">OACA · Aéroports tunisiens<br>Locaux, superficies et occupations domaniales</div>
      </div>
      <div class="zone-card-footer">
        <span style="font-size:11px;color:rgba(255,255,255,.6);font-weight:500;">Tunis-Carthage, Sfax, Monastir, Djerba…</span>
        <div class="zone-card-arrow">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 8h8M9 5l3 3-3 3" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
      </div>
    </a>

    <!-- Internationale -->
    <a href="concessions_liste.php?zone=Internationale" class="zone-card zone-card-int">
      <div class="zone-card-icon">🌍</div>
      <div class="zone-card-content">
        <div class="zone-card-title">Concessions Internationales</div>
        <div class="zone-card-sub">TAV · Aéroports étrangers<br>Paris CDG, Rome, Frankfurt, Genève…</div>
      </div>
      <div class="zone-card-footer">
        <span style="font-size:11px;color:rgba(255,255,255,.6);font-weight:500;">Europe · Afrique · Moyen-Orient</span>
        <div class="zone-card-arrow">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 8h8M9 5l3 3-3 3" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
      </div>
    </a>
  </div>
</div>
</body>
</html>