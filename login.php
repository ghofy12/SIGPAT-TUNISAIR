<?php
require_once 'config.php';

if(isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
$user  = null;

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if(!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM users
                WHERE email = ? AND actif = 1
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $password === $user['password']) {

                $roleStmt = $pdo->prepare("SELECT * FROM roles WHERE code = ? AND actif = 1");
                $roleStmt->execute([$user['role_id']]);
                $roleInfo = $roleStmt->fetch(PDO::FETCH_ASSOC);

                if (!$roleInfo) {
                    $error = "Votre profil n'est pas configuré ou est inactif. Contactez un administrateur.";
                } else {
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['email']       = $user['email'];
                    $_SESSION['nom']         = $user['nom'];
                    $_SESSION['prenom']      = $user['prenom'];
                    $_SESSION['username']    = trim($user['prenom'] . ' ' . $user['nom']);
                    $_SESSION['role_id']     = $roleInfo['code'];
                    $_SESSION['role_nom']    = $roleInfo['nom'];
                    $_SESSION['role_niveau'] = $roleInfo['niveau'];
                    $_SESSION['departement'] = $user['departement'] ?? '';

                    $stmt2 = $pdo->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?");
                    $stmt2->execute([$user['id']]);

                    logActivity($pdo, $user['id'], 'Connexion au système', 'authentification');
                    redirect('dashboard.php');
                    exit;
                }
            } else {
                $error = "Email ou mot de passe incorrect.";
            }
        } catch(PDOException $e) {
            error_log("Erreur connexion: " . $e->getMessage());
            $error = "Erreur système. Veuillez réessayer.";
        }
    } else {
        $error = "Veuillez remplir tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — TUNISAIR Patrimoine</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --red:       #C8102E;
  --red-dark:  #9B0E23;
  --red-light: #F5E6E9;
  --navy:      #0F2563;
  --navy-2:    #1D4ED8;
  --ink:       #1A1A18;
  --sub:       #374151;
  --muted:     #6B7280;
  --border:    rgba(0,0,0,.07);
  --bg:        #F4F6F9;
  --white:     #ffffff;
  --shadow:    0 4px 20px rgba(0,0,0,.07);
  --glow-red:  rgba(200,16,46,.18);
}

html, body {
  height: 100%;
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--ink);
}

/* ══ LAYOUT ══ */
.layout {
  display: grid;
  grid-template-columns: 480px 1fr;
  min-height: 100vh;
}

/* ══ PANNEAU GAUCHE ══ */
.panel-left {
  background: var(--navy);
  display: flex;
  flex-direction: column;
  padding: 0;
  position: relative;
  overflow: hidden;
}

/* Lignes décoratives géométriques */
.panel-left::before {
  content: '';
  position: absolute;
  top: -120px; right: -120px;
  width: 400px; height: 400px;
  border: 1px solid rgba(255,255,255,.06);
  border-radius: 50%;
}
.panel-left::after {
  content: '';
  position: absolute;
  bottom: -80px; left: -80px;
  width: 280px; height: 280px;
  border: 1px solid rgba(200,16,46,.15);
  border-radius: 50%;
}

.panel-left-inner {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 52px 48px;
  position: relative;
  z-index: 1;
}

/* Logo + marque */
.brand {
  display: flex;
  align-items: center;
  gap: 16px;
}
.brand-logo {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  background: var(--white);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}
.brand-logo img {
  width: 48px;
  height: 48px;
  object-fit: contain;
}
.brand-text {}
.brand-name {
  font-size: 18px;
  font-weight: 700;
  color: var(--white);
  letter-spacing: .04em;
  line-height: 1;
}
.brand-sub {
  font-size: 11px;
  font-weight: 400;
  color: rgba(255,255,255,.45);
  margin-top: 4px;
  letter-spacing: .06em;
  text-transform: uppercase;
}

/* Texte central */
.panel-center {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 48px 0;
}
.panel-tagline {
  font-size: 28px;
  font-weight: 700;
  color: var(--white);
  line-height: 1.35;
  letter-spacing: -.01em;
  margin-bottom: 16px;
  max-width: 320px;
}
.panel-tagline em {
  color: var(--red);
  font-style: normal;
}
.panel-desc {
  font-size: 13.5px;
  color: rgba(255,255,255,.5);
  line-height: 1.7;
  max-width: 300px;
}

/* Séparateur rouge */
.red-bar {
  width: 40px;
  height: 3px;
  background: var(--red);
  border-radius: 2px;
  margin-bottom: 24px;
}

/* Indicateurs bas */
.panel-indicators {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}
.indicator {
  padding: 16px 14px;
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 10px;
  background: rgba(255,255,255,.04);
}
.indicator-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(255,255,255,.08);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 10px;
}
.indicator-icon svg {
  color: rgba(255,255,255,.7);
}
.indicator-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: rgba(255,255,255,.35);
}
.indicator-value {
  font-size: 13px;
  font-weight: 600;
  color: rgba(255,255,255,.8);
  margin-top: 3px;
}

/* ══ PANNEAU DROIT ══ */
.panel-right {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 40px;
  background: var(--bg);
}

.login-box {
  width: 100%;
  max-width: 380px;
  background: var(--white);
  border-radius: 16px;
  box-shadow: var(--shadow);
  border: 1px solid var(--border);
  border-left: 4px solid var(--red);
  padding: 36px 34px;
}

/* En-tête formulaire */
.login-header {
  margin-bottom: 36px;
}
.login-title {
  font-size: 22px;
  font-weight: 700;
  color: var(--ink);
  letter-spacing: -.01em;
  margin-bottom: 6px;
}
.login-subtitle {
  font-size: 13.5px;
  color: var(--muted);
  font-weight: 400;
  line-height: 1.55;
}

/* Ligne de séparation */
.login-divider {
  height: 1px;
  background: var(--border);
  margin-bottom: 28px;
}

/* Erreur */
.alert {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 8px;
  margin-bottom: 22px;
  font-size: 13px;
  font-weight: 500;
  border-left: 3px solid;
}
.alert-error {
  background: #FEF2F2;
  border-color: var(--red);
  color: #991B1B;
}
.alert svg { flex-shrink: 0; margin-top: 1px; }

/* Champs */
.field {
  margin-bottom: 18px;
}
.field-label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--sub);
  margin-bottom: 7px;
}
.field-wrap {
  position: relative;
}
.field-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  pointer-events: none;
  display: flex;
}
.field-input {
  width: 100%;
  padding: 11px 14px 11px 38px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--bg);
  font-family: 'DM Sans', sans-serif;
  font-size: 13.5px;
  color: var(--ink);
  outline: none;
  transition: border-color .2s, box-shadow .2s, background .2s;
}
.field-input:focus {
  border-color: var(--red);
  background: var(--white);
  box-shadow: 0 0 0 3px rgba(200,16,46,.08);
}
.field-input::placeholder { color: #C4C9D4; }

.toggle-pw {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: var(--muted);
  padding: 0;
  display: flex;
  align-items: center;
  transition: color .15s;
}
.toggle-pw:hover { color: var(--ink); }

/* Bouton */
.btn-submit {
  width: 100%;
  padding: 12px;
  background: var(--red);
  color: white;
  border: none;
  border-radius: 10px;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: .01em;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  transition: background .2s, transform .15s, box-shadow .2s;
  box-shadow: 0 3px 10px var(--glow-red);
  margin-top: 8px;
}
.btn-submit:hover {
  background: var(--red-dark);
  transform: translateY(-1px);
  box-shadow: 0 5px 16px var(--glow-red);
}
.btn-submit:active { transform: none; }

/* Pied de page */
.login-footer {
  margin-top: 36px;
  padding-top: 22px;
  border-top: 1px solid var(--border);
  text-align: center;
}
.login-footer p {
  font-size: 11.5px;
  color: var(--muted);
}
.login-footer strong { color: var(--sub); font-weight: 600; }

/* Responsive */
@media (max-width: 820px) {
  .layout { grid-template-columns: 1fr; }
  .panel-left { display: none; }
  .panel-right { padding: 40px 24px; background: var(--bg); }
}
</style>
</head>
<body>

<div class="layout">

  <!-- ══ PANNEAU GAUCHE ══ -->
  <div class="panel-left">
    <div class="panel-left-inner">

      <!-- Marque -->
      <div class="brand">
        <div class="brand-logo">
          <img src="logo.webp" alt="TUNISAIR">
        </div>
        <div class="brand-text">
          <div class="brand-name">TUNISAIR</div>
          <div class="brand-sub">Gestion du Patrimoine</div>
        </div>
      </div>

      <!-- Texte central -->
      <div class="panel-center">
        <div class="red-bar"></div>
        <h1 class="panel-tagline">
          Système de gestion<br>
          du <em>patrimoine</em><br>
        </h1>
        <p class="panel-desc">
          Plateforme centralisée de suivi des biens fonciers, mobiliers, concessions et du parc automobile de TUNISAIR.
        </p>
      </div>

      <!-- Indicateurs -->
      <div class="panel-indicators">
        <div class="indicator">
          <div class="indicator-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
              <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
          </div>
          <div class="indicator-label">Périmètre</div>
          <div class="indicator-value">National</div>
        </div>
        <div class="indicator">
          <div class="indicator-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <line x1="2" y1="12" x2="22" y2="12"/>
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
          </div>
          <div class="indicator-label">Couverture</div>
          <div class="indicator-value">International</div>
        </div>
        <div class="indicator">
          <div class="indicator-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
          </div>
          <div class="indicator-label">Accès</div>
          <div class="indicator-value">Sécurisé</div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ PANNEAU DROIT ══ -->
  <div class="panel-right">
    <div class="login-box">

      <div class="login-header">
        <h2 class="login-title">Connexion</h2>
        <p class="login-subtitle">Entrez vos identifiants pour accéder à votre espace.</p>
      </div>

      <div class="login-divider"></div>

      <?php if($error): ?>
      <div class="alert alert-error">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="7" stroke="#991B1B" stroke-width="1.5"/>
          <path d="M8 5v3M8 10.5v.5" stroke="#991B1B" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">

        <div class="field">
          <label class="field-label" for="email">Adresse e-mail</label>
          <div class="field-wrap">
            <span class="field-icon">
              <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
                <path d="M2.5 5.5A1.5 1.5 0 0 1 4 4h12a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 16 16H4a1.5 1.5 0 0 1-1.5-1.5v-9z" stroke="currentColor" stroke-width="1.3"/>
                <path d="M2.5 6l7.5 5 7.5-5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
              </svg>
            </span>
            <input
              type="email" id="email" name="email"
              class="field-input"
              placeholder="prenom.nom@tunisair.com.tn"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              autocomplete="email"
              required>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="password">Mot de passe</label>
          <div class="field-wrap">
            <span class="field-icon">
              <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
                <rect x="3" y="9" width="14" height="9" rx="1.5" stroke="currentColor" stroke-width="1.3"/>
                <path d="M6.5 9V6.5a3.5 3.5 0 0 1 7 0V9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
              </svg>
            </span>
            <input
              type="password" id="password" name="password"
              class="field-input"
              placeholder="••••••••••"
              autocomplete="current-password"
              required>
            <button type="button" class="toggle-pw" onclick="togglePw()" title="Afficher / masquer">
              <svg id="eye" width="16" height="16" viewBox="0 0 20 20" fill="none">
                <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.3"/>
                <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.3"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-submit">
          <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
            <path d="M3 10h14M11 4l6 6-6 6" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Se connecter
        </button>

      </form>

      <div class="login-footer">
        <p>© <?= date('Y') ?> <strong>TUNISAIR</strong> · Gestion du Patrimoine · v<?= APP_VERSION ?></p>
      </div>

    </div>
  </div>

</div>

<script>
function togglePw() {
  const inp = document.getElementById('password');
  const ico = document.getElementById('eye');
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.innerHTML = show
    ? `<path d="M3 3l14 14M8.5 8.7A2.5 2.5 0 0 0 12 12M5.3 5.5C3.8 6.8 2.7 8.6 2 10c1.7 3.3 5 6 8 6a8.5 8.5 0 0 0 4.7-1.5M9 4.1C9.3 4 9.7 4 10 4c3.3 0 6.3 2.7 8 6-.6 1.1-1.4 2.3-2.4 3.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>`
    : `<path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.3"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.3"/>`;
}
</script>
</body>
</html>