<?php
/**
 * expertise_detail.php
 * Affiche tous les rapports PDF d'un bien pour un type donné (locative ou vénale),
 * organisés par année.
 *
 * URL : expertise_detail.php?bien_id=3&type=locative
 *       expertise_detail.php?bien_id=3&type=venale
 */
require_once 'config.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }
requireModuleAccess($pdo, 'expertises');

$canCreate = hasModulePermission($pdo, 'expertises', 'create');
$canDelete = hasModulePermission($pdo, 'expertises', 'delete');

$bien_id = (int)($_GET['bien_id'] ?? 0);
$type    = in_array($_GET['type'] ?? '', ['locative','venale']) ? $_GET['type'] : null;

if (!$bien_id || !$type) { header('Location: biens_liste.php'); exit; }

// ── Charger le bien ──────────────────────────────────────────
$bien = $pdo->prepare("SELECT * FROM biens_fonciers WHERE id = ?");
$bien->execute([$bien_id]);
$bien = $bien->fetch();
if (!$bien) { header('Location: biens_liste.php'); exit; }

// ── Charger les expertises du type choisi ───────────────────
try {
    $st = $pdo->prepare("
        SELECT * FROM bien_expertises
        WHERE bien_id = ? AND type = ?
        ORDER BY annee DESC
    ");
    $st->execute([$bien_id, $type]);
    $expertises = $st->fetchAll();
} catch (PDOException $e) {
    $expertises = [];
}

// ── Supprimer une entrée ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_expertise') {
    requireModulePermission($pdo, 'expertises', 'delete');
    $eid = (int)($_POST['expertise_id'] ?? 0);
    $pdo->prepare("DELETE FROM bien_expertises WHERE id = ? AND bien_id = ?")->execute([$eid, $bien_id]);
    header("Location: expertise_detail.php?bien_id=$bien_id&type=$type"); exit;
}

// ── Ajouter une entrée ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expertise') {
    requireModulePermission($pdo, 'expertises', 'create');
    $annee  = (int)($_POST['annee'] ?? date('Y'));
    $pdfPath = null;
    if (!empty($_FILES['rapport_pdf']['tmp_name']) && $_FILES['rapport_pdf']['error'] === UPLOAD_ERR_OK) {
        $dir = 'documents/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname = 'expertise_' . $type . '_' . $bien_id . '_' . $annee . '_' . time() . '.pdf';
        move_uploaded_file($_FILES['rapport_pdf']['tmp_name'], $dir . $fname);
        $pdfPath = $dir . $fname;
    }
    $pdo->prepare("
        INSERT INTO bien_expertises (bien_id, type, annee, valeur, rapport_pdf)
        VALUES (?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE rapport_pdf = VALUES(rapport_pdf)
    ")->execute([$bien_id, $type, $annee, $pdfPath]);
    header("Location: expertise_detail.php?bien_id=$bien_id&type=$type"); exit;
}

$label      = $type === 'locative' ? 'Valeur Locative' : 'Valeur Vénale';
$color      = $type === 'locative' ? '#1D4ED8' : '#C8102E';
$colorLight = $type === 'locative' ? '#DBEAFE' : '#FEE2E2';
$zone       = $bien['localisation'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $label ?> — <?= htmlspecialchars($bien['reference']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --accent:  <?= $color ?>;
    --accent-l:<?= $colorLight ?>;
    --ink:     #111827;
    --muted:   #6B7280;
    --rule:    #E5E7EB;
    --bg:      #F9FAFB;
    --white:   #FFFFFF;
    --radius:  12px;
    --shadow:  0 1px 4px rgba(0,0,0,.08);
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
  }

  /* ── TOPBAR ── */
  .topbar {
    background: var(--white);
    border-bottom: 1px solid var(--rule);
    padding: 0 28px;
    height: 58px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 10;
  }
  .topbar-left { display: flex; align-items: center; gap: 14px; }
  .back-btn {
    display: flex; align-items: center; gap: 7px;
    color: var(--muted); font-size: 13px; font-weight: 500;
    text-decoration: none; padding: 6px 12px; border-radius: 8px;
    border: 1px solid var(--rule); background: var(--white);
    transition: background .15s, color .15s;
  }
  .back-btn:hover { background: var(--bg); color: var(--ink); }
  .topbar-title { font-size: 15px; font-weight: 700; }
  .type-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--accent-l); color: var(--accent);
    border-radius: 20px; padding: 4px 13px;
    font-size: 12px; font-weight: 600;
  }
  .add-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--accent); color: white;
    border: none; border-radius: 8px;
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: inherit;
    transition: opacity .15s;
  }
  .add-btn:hover { opacity: .88; }

  /* ── BIEN INFO STRIP ── */
  .bien-strip {
    background: var(--white);
    border-bottom: 1px solid var(--rule);
    padding: 14px 28px;
    display: flex; align-items: center; gap: 28px; flex-wrap: wrap;
  }
  .strip-field { display: flex; flex-direction: column; gap: 2px; }
  .strip-label { font-size: 10px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
  .strip-val { font-size: 13px; font-weight: 600; color: var(--ink); }

  /* ── MAIN ── */
  .main { max-width: 860px; margin: 32px auto; padding: 0 20px; }

  /* ── EMPTY ── */
  .empty-state {
    text-align: center; padding: 64px 20px;
    background: var(--white); border-radius: var(--radius);
    border: 1px solid var(--rule);
    color: var(--muted); font-size: 14px;
  }
  .empty-state svg { display: block; margin: 0 auto 14px; opacity: .35; }

  /* ── YEAR BLOCK ── */
  .year-block {
    background: var(--white);
    border: 1px solid var(--rule);
    border-radius: var(--radius);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: var(--shadow);
  }
  .year-hd {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 20px;
    background: var(--bg);
    border-bottom: 1px solid var(--rule);
  }
  .year-badge {
    background: var(--accent); color: white;
    border-radius: 8px; padding: 4px 14px;
    font-size: 15px; font-weight: 700; letter-spacing: .02em;
  }
  .year-sub { font-size: 12px; color: var(--muted); }

  /* ── FILE ROW ── */
  .file-row {
    display: flex; align-items: center;
    padding: 16px 20px; gap: 14px;
    border-bottom: 1px dashed var(--rule);
  }
  .file-row:last-child { border-bottom: none; }
  .file-icon {
    width: 40px; height: 40px; border-radius: 8px;
    background: var(--accent-l); flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
  }
  .file-info { flex: 1; min-width: 0; }
  .file-name {
    font-size: 13px; font-weight: 600; color: var(--ink);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .file-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
  .file-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }

  /* ── BUTTONS ── */
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 13px; border-radius: 7px; font-size: 12px;
    font-weight: 600; cursor: pointer; font-family: inherit;
    border: 1.5px solid var(--rule); background: var(--white);
    color: var(--ink); text-decoration: none;
    transition: background .14s;
  }
  .btn:hover { background: var(--bg); }
  .btn-danger { color: #DC2626; border-color: #FECACA; }
  .btn-danger:hover { background: #FEF2F2; }
  .btn-primary { background: var(--accent); color: white; border-color: var(--accent); }
  .btn-primary:hover { opacity: .88; }

  /* ── MODAL ── */
  .modal-bg {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.4); z-index: 100;
    align-items: center; justify-content: center;
  }
  .modal-bg.open { display: flex; }
  .modal-box {
    background: var(--white); border-radius: 14px;
    padding: 28px; width: 100%; max-width: 420px;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
  }
  .modal-box h2 { font-size: 16px; font-weight: 700; margin-bottom: 20px; }
  .form-row { margin-bottom: 16px; }
  .form-label {
    display: block; font-size: 11px; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase;
    color: var(--muted); margin-bottom: 6px;
  }
  .form-input {
    width: 100%; padding: 9px 12px; border: 1.5px solid var(--rule);
    border-radius: 8px; font-size: 13px; font-family: inherit;
    outline: none; color: var(--ink); background: var(--white);
    transition: border-color .15s;
  }
  .form-input:focus { border-color: var(--accent); }
  .upload-zone {
    border: 2px dashed var(--rule); border-radius: 10px;
    padding: 20px; text-align: center; cursor: pointer;
    transition: border-color .15s, background .15s;
  }
  .upload-zone:hover, .upload-zone.has-file { border-color: var(--accent); background: var(--accent-l); }
  .upload-zone svg { display: block; margin: 0 auto 8px; }
  .upload-zone-text { font-size: 13px; color: var(--muted); }
  .upload-zone-text strong { color: var(--accent); }
  .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }

  /* ── PDF VIEWER ── */
  .pdf-modal-bg {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.7); z-index: 200;
    flex-direction: column;
  }
  .pdf-modal-bg.open { display: flex; }
  .pdf-topbar {
    background: #1F2937; color: white;
    padding: 12px 20px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .pdf-topbar-title { font-size: 14px; font-weight: 600; }
  .pdf-close {
    background: none; border: none; color: white;
    cursor: pointer; padding: 6px; border-radius: 6px;
    display: flex; align-items: center;
  }
  .pdf-close:hover { background: rgba(255,255,255,.1); }
  iframe.pdf-frame { flex: 1; border: none; }
</style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="topbar">
  <div class="topbar-left">
    <a class="back-btn" href="biens_liste.php?zone=<?= urlencode($zone) ?>&id=<?= $bien_id ?>">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
        <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      Retour au bien
    </a>
    <span class="topbar-title"><?= htmlspecialchars($bien['reference']) ?></span>
    <span class="type-chip">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
        <path d="M14 2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z" stroke="currentColor" stroke-width="1.4"/>
        <path d="M8 6h4M8 9h4M8 12h2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
      <?= $label ?>
    </span>
  </div>
  <?php if($canCreate): ?>
  <button class="add-btn" onclick="document.getElementById('addModal').classList.add('open')">
    <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
      <path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/>
    </svg>
    Ajouter un rapport
  </button>
  <?php endif; ?>
</div>

<!-- ── BIEN STRIP ── -->
<div class="bien-strip">
  <div class="strip-field">
    <div class="strip-label">Adresse</div>
    <div class="strip-val"><?= htmlspecialchars($bien['adresse']) ?></div>
  </div>
  <div class="strip-field">
    <div class="strip-label">Superficie</div>
    <div class="strip-val"><?= number_format($bien['superficie'], 2, ',', ' ') ?> m²</div>
  </div>
  <?php if (!empty($bien['date_acquisition'])): ?>
  <div class="strip-field">
    <div class="strip-label">Acquisition</div>
    <div class="strip-val"><?= date('d/m/Y', strtotime($bien['date_acquisition'])) ?></div>
  </div>
  <?php endif; ?>
  <div class="strip-field">
    <div class="strip-label">Rapports</div>
    <div class="strip-val" style="color:var(--accent)"><?= count($expertises) ?> fichier<?= count($expertises) > 1 ? 's' : '' ?></div>
  </div>
</div>

<!-- ── MAIN CONTENT ── -->
<div class="main">

  <?php if (empty($expertises)): ?>
    <div class="empty-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
        <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      Aucun rapport de <strong><?= $label ?></strong> enregistré pour ce bien.<br>
      <?php if($canCreate): ?>
      <button class="add-btn" style="margin-top:18px;" onclick="document.getElementById('addModal').classList.add('open')">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
        Ajouter le premier rapport
      </button>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <?php foreach ($expertises as $exp): ?>
    <div class="year-block">

      <!-- Entête année -->
      <div class="year-hd">
        <span class="year-badge"><?= htmlspecialchars($exp['annee']) ?></span>
        <span class="year-sub"><?= $label ?></span>
      </div>

      <!-- Ligne fichier -->
      <div class="file-row">
        <div class="file-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
                  stroke="<?= $color ?>" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="14 2 14 8 20 8" stroke="<?= $color ?>" stroke-width="1.7"
                      stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="file-info">
          <div class="file-name">
            <?= !empty($exp['rapport_pdf'])
                ? htmlspecialchars(basename($exp['rapport_pdf']))
                : '<em style="color:var(--muted)">Aucun fichier</em>' ?>
          </div>
          <div class="file-meta">
            Ajouté le <?= date('d/m/Y', strtotime($exp['date_creation'])) ?>
          </div>
        </div>
        <div class="file-actions">
          <?php if (!empty($exp['rapport_pdf'])): ?>
            <button class="btn" onclick="openPDF('<?= htmlspecialchars($exp['rapport_pdf']) ?>','<?= $label ?> <?= $exp['annee'] ?>')">
              <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                <path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z" stroke="currentColor" stroke-width="1.4"/>
                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.4"/>
              </svg>
              Aperçu
            </button>
            <a class="btn" href="<?= htmlspecialchars($exp['rapport_pdf']) ?>" download>
              <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                <path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Télécharger
            </a>
          <?php endif; ?>
          <?php if($canDelete): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce rapport ?')">
            <input type="hidden" name="action" value="delete_expertise">
            <input type="hidden" name="expertise_id" value="<?= $exp['id'] ?>">
            <button class="btn btn-danger" type="submit">
              <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                <path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"
                      stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Supprimer
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>

    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<!-- ══ MODAL AJOUTER ══ -->
<div class="modal-bg" id="addModal">
  <div class="modal-box">
    <h2>Ajouter un rapport — <?= $label ?></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_expertise">
      <input type="hidden" name="valeur" value="0">

      <div class="form-row">
        <label class="form-label">Année *</label>
        <input class="form-input" type="number" name="annee"
               min="1950" max="2099" value="<?= date('Y') ?>" required>
      </div>

      <div class="form-row">
        <label class="form-label">Rapport PDF *</label>
        <label class="upload-zone" id="uploadZone" for="pdfInput">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
                  stroke="<?= $color ?>" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="14 2 14 8 20 8" stroke="<?= $color ?>" stroke-width="1.5"
                      stroke-linecap="round" stroke-linejoin="round"/>
            <line x1="12" y1="12" x2="12" y2="18" stroke="<?= $color ?>" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="9" y1="15" x2="15" y2="15" stroke="<?= $color ?>" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <div class="upload-zone-text" id="uploadText">
            <strong>Cliquez pour choisir</strong> un fichier PDF
          </div>
        </label>
        <input type="file" name="rapport_pdf" id="pdfInput" accept=".pdf"
               style="display:none;" required
               onchange="
                 const z=document.getElementById('uploadZone');
                 const t=document.getElementById('uploadText');
                 z.classList.add('has-file');
                 t.innerHTML='<strong>'+this.files[0].name+'</strong>';
               ">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn"
                onclick="document.getElementById('addModal').classList.remove('open')">
          Annuler
        </button>
        <button type="submit" class="btn btn-primary">
          <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
            <path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Enregistrer
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ PDF VIEWER ══ -->
<div class="pdf-modal-bg" id="pdfViewer">
  <div class="pdf-topbar">
    <span class="pdf-topbar-title" id="pdfTitle"></span>
    <button class="pdf-close" onclick="document.getElementById('pdfViewer').classList.remove('open')">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
        <path d="M18 6 6 18M6 6l12 12" stroke="white" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>
  </div>
  <iframe class="pdf-frame" id="pdfFrame" src=""></iframe>
</div>

<script>
function openPDF(path, title) {
  document.getElementById('pdfTitle').textContent = title;
  document.getElementById('pdfFrame').src = path;
  document.getElementById('pdfViewer').classList.add('open');
}
// Fermer modal en cliquant le fond
document.getElementById('addModal').addEventListener('click', function(e){
  if(e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>