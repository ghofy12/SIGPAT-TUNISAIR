<?php
require_once 'config.php';

if(isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if(!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, r.nom AS role_nom, r.code AS role_code, r.niveau AS role_niveau 
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.email = ? AND u.actif = 1
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $password === $user['password']) {
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['email']        = $user['email'];
                $_SESSION['nom']          = $user['nom'];
                $_SESSION['prenom']       = $user['prenom'];
                $_SESSION['role_id']      = $user['role_id'];
                $_SESSION['role_nom']     = $user['role_nom'];
                $_SESSION['role_code']    = $user['role_code'];
                $_SESSION['role_niveau']  = $user['role_niveau'];
                $_SESSION['departement']  = $user['departement'];
                $_SESSION['permissions']  = getModulePermissions($pdo, $user['role_id']);

                $stmt = $pdo->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);

                logActivity($pdo, $user['id'], 'Connexion au système', 'authentification');
                redirect('dashboard.php');
                exit;
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
<title>Connexion · TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

:root {
  --red:       #C8102E;
  --red-dark:  #9B0E23;
  --navy:      #0F2563;
  --navy-mid:  #1D4ED8;
  --ink:       #1A1A18;
  --muted:     #6B7280;
  --bg:        #F4F6F9;
  --white:     #ffffff;
  --rule:      rgba(0,0,0,.07);
  --shadow:    0 4px 24px rgba(0,0,0,.09);
  --glow-red:  rgba(200,16,46,.18);
}

html, body {
  height: 100%;
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--ink);
}

/* ══ BACKGROUND SPLIT ══ */
body {
  display: grid;
  grid-template-columns: 1fr 1fr;
  min-height: 100vh;
}

/* ══ LEFT PANEL ══ */
.panel-left {
  background: linear-gradient(145deg, var(--navy) 0%, #1E3A8A 60%, var(--navy-mid) 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 48px;
  position: relative;
  overflow: hidden;
}

/* Geometric decorations */
.panel-left::before {
  content: '';
  position: absolute;
  top: -80px; right: -80px;
  width: 320px; height: 320px;
  border-radius: 50%;
  background: rgba(255,255,255,.04);
  pointer-events: none;
}
.panel-left::after {
  content: '';
  position: absolute;
  bottom: -60px; left: -60px;
  width: 240px; height: 240px;
  border-radius: 50%;
  background: rgba(200,16,46,.12);
  pointer-events: none;
}

.panel-left-inner {
  position: relative;
  z-index: 1;
  text-align: center;
}

.brand-logo {
  width: 130px;
  height: auto;
  margin-bottom: 28px;
  filter: drop-shadow(0 8px 24px rgba(0,0,0,.3));
  animation: floatLogo 4s ease-in-out infinite;
}
@keyframes floatLogo {
  0%,100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}

.brand-title {
  font-size: 34px;
  font-weight: 700;
  color: white;
  letter-spacing: .04em;
  margin-bottom: 10px;
}
.brand-line {
  width: 48px; height: 3px;
  background: var(--red);
  border-radius: 2px;
  margin: 0 auto 18px;
}
.brand-subtitle {
  font-size: 14px;
  font-weight: 400;
  color: rgba(255,255,255,.65);
  line-height: 1.65;
  max-width: 280px;
  margin: 0 auto 40px;
}

/* Stats déco */
.panel-stats {
  display: flex;
  gap: 24px;
  justify-content: center;
  flex-wrap: wrap;
}
.panel-stat {
  text-align: center;
  padding: 16px 22px;
  border-radius: 14px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.1);
  backdrop-filter: blur(8px);
  min-width: 90px;
}
.panel-stat-val {
  font-size: 22px;
  font-weight: 700;
  color: white;
  line-height: 1;
}
.panel-stat-lbl {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: rgba(255,255,255,.45);
  margin-top: 6px;
}

/* ══ RIGHT PANEL (form) ══ */
.panel-right {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 48px;
  background: var(--bg);
}

.login-card {
  width: 100%;
  max-width: 420px;
  animation: fadeUp .45s ease both;
}
@keyframes fadeUp {
  from { opacity:0; transform:translateY(16px); }
  to   { opacity:1; transform:none; }
}

.login-heading {
  margin-bottom: 8px;
}
.login-heading h2 {
  font-size: 24px;
  font-weight: 700;
  color: var(--ink);
  letter-spacing: .01em;
}
.login-heading p {
  font-size: 13px;
  color: var(--muted);
  margin-top: 6px;
}

.login-divider {
  height: 1px;
  background: var(--rule);
  margin: 22px 0;
}

/* ── ERROR ── */
.alert-error {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  background: #FEF2F2;
  border: 1.5px solid #FECACA;
  border-left: 4px solid #DC2626;
  border-radius: 10px;
  padding: 13px 15px;
  margin-bottom: 22px;
  font-size: 13px;
  color: #B91C1C;
  animation: shake .35s ease;
}
@keyframes shake {
  0%,100% { transform: translateX(0); }
  25%      { transform: translateX(-6px); }
  75%      { transform: translateX(6px); }
}
.alert-error svg { flex-shrink: 0; margin-top: 1px; }

/* ── FORM ── */
.form-group {
  display: flex;
  flex-direction: column;
  gap: 7px;
  margin-bottom: 18px;
}
.form-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
}
.input-wrap {
  position: relative;
}
.input-icon {
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  pointer-events: none;
}
.form-input {
  width: 100%;
  padding: 11px 14px 11px 40px;
  border-radius: 10px;
  border: 1.5px solid var(--rule);
  background: var(--white);
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  color: var(--ink);
  outline: none;
  box-shadow: var(--shadow);
  transition: border-color .2s, box-shadow .2s;
}
.form-input:focus {
  border-color: var(--red);
  box-shadow: 0 0 0 3px var(--glow-red);
}
.form-input::placeholder { color: #C4C9D4; }

/* toggle password */
.toggle-pw {
  position: absolute;
  right: 13px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: var(--muted);
  padding: 0;
  display: grid;
  place-items: center;
  transition: color .15s;
}
.toggle-pw:hover { color: var(--ink); }

/* ── SUBMIT BUTTON ── */
.btn-login {
  width: 100%;
  padding: 13px;
  background: linear-gradient(130deg, var(--red-dark), var(--red));
  color: white;
  border: none;
  border-radius: 10px;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: .02em;
  cursor: pointer;
  box-shadow: 0 6px 20px var(--glow-red);
  transition: transform .2s, box-shadow .2s;
  margin-top: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-login:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px var(--glow-red);
}
.btn-login:active { transform: translateY(0); }

/* ── FOOTER ── */
.login-footer {
  margin-top: 32px;
  text-align: center;
  font-size: 11px;
  color: var(--muted);
  border-top: 1px solid var(--rule);
  padding-top: 20px;
}
.login-footer strong { color: var(--ink); }

/* ══ RESPONSIVE ══ */
@media (max-width: 768px) {
  body { grid-template-columns: 1fr; }
  .panel-left { display: none; }
  .panel-right { padding: 40px 24px; justify-content: flex-start; padding-top: 60px; }
}
</style>
</head>
<body>

<!-- ══ LEFT PANEL ══ -->
<div class="panel-left">
  <div class="panel-left-inner">
    <img src="logo.webp" alt="TUNISAIR" class="brand-logo">
    <div class="brand-title">TUNISAIR</div>
    <div class="brand-line"></div>
    <p class="brand-subtitle">
      Système de Gestion du Patrimoine <br>
      Accès sécurisé aux équipes autorisées
    </p>
    <div class="panel-stats">
      <div class="panel-stat">
        <div class="panel-stat-val">🇹🇳</div>
        <div class="panel-stat-lbl">Tunisie</div>
      </div>
      <div class="panel-stat">
        <div class="panel-stat-val">🌍</div>
        <div class="panel-stat-lbl">International</div>
      </div>
      <div class="panel-stat">
        <div class="panel-stat-val">🔒</div>
        <div class="panel-stat-lbl">Sécurisé</div>
      </div>
    </div>
  </div>
</div>

<!-- ══ RIGHT PANEL ══ -->
<div class="panel-right">
  <div class="login-card">

    <div class="login-heading">
      <h2>Bienvenue 👋</h2>
      <p>Connectez-vous pour accéder à votre espace de gestion.</p>
    </div>

    <div class="login-divider"></div>

    <?php if($error): ?>
    <div class="alert-error">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <circle cx="8" cy="8" r="7" stroke="#DC2626" stroke-width="1.5"/>
        <path d="M8 5v3.5M8 10.5v.5" stroke="#DC2626" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <?=htmlspecialchars($error)?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="on">

      <div class="form-group">
        <label class="form-label" for="email">Adresse Email</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
              <path d="M2.5 5.5A1.5 1.5 0 0 1 4 4h12a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 16 16H4a1.5 1.5 0 0 1-1.5-1.5v-9z" stroke="currentColor" stroke-width="1.3"/>
              <path d="M2.5 6l7.5 5 7.5-5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
          </span>
          <input type="email" id="email" name="email" class="form-input"
                 placeholder="votre.email@tunisair.tn"
                 value="<?=htmlspecialchars($_POST['email']??'')?>"
                 autocomplete="email">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Mot de passe</label>
        <div class="input-wrap">
          <span class="input-icon">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
              <rect x="3" y="9" width="14" height="9" rx="1.5" stroke="currentColor" stroke-width="1.3"/>
              <path d="M6.5 9V6.5a3.5 3.5 0 0 1 7 0V9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
          </span>
          <input type="password" id="password" name="password" class="form-input"
                 placeholder="••••••••"
                 autocomplete="current-password">
          <button type="button" class="toggle-pw" onclick="togglePassword()" title="Afficher / masquer">
            <svg id="eyeIcon" width="16" height="16" viewBox="0 0 20 20" fill="none">
              <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.3"/>
              <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.3"/>
            </svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login">
        <svg width="15" height="15" viewBox="0 0 20 20" fill="none">
          <path d="M3 10h14M11 4l6 6-6 6" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Se connecter
      </button>

    </form>

    <div class="login-footer">
      <p>© <?=date('Y')?> <strong>TUNISAIR</strong> · Gestion du Patrimoine · v<?=APP_VERSION?></p>
    </div>

  </div>
</div>

<script>
function togglePassword() {
  const input = document.getElementById('password');
  const icon  = document.getElementById('eyeIcon');
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  icon.innerHTML = show
    ? `<path d="M3 3l14 14M8.5 8.7A2.5 2.5 0 0 0 12 12M5.3 5.5C3.8 6.8 2.7 8.6 2 10c1.7 3.3 5 6 8 6a8.5 8.5 0 0 0 4.7-1.5M9 4.1C9.3 4 9.7 4 10 4c3.3 0 6.3 2.7 8 6-.6 1.1-1.4 2.3-2.4 3.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>`
    : `<path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z" stroke="currentColor" stroke-width="1.3"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.3"/>`;
}
</script>
</body>
</html>