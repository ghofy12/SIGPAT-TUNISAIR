<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
requireModuleAccess($pdo, 'vehicules');
$username = $_SESSION['username'] ?? 'Utilisateur';

$canCreate = hasModulePermission($pdo, 'vehicules', 'create');
$canUpdate = hasModulePermission($pdo, 'vehicules', 'update');
$canDelete = hasModulePermission($pdo, 'vehicules', 'delete');

// Librairie PDF parser (installée via Composer : php composer.phar require smalot/pdfparser)
if(file_exists(__DIR__.'/vendor/autoload.php')){
  require_once __DIR__.'/vendor/autoload.php';
}

/* ══ POST ACTIONS ══ */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $action = $_POST['action']??'';

  if($action==='upload_decision'){
    requireModulePermission($pdo, 'vehicules', 'update');
    $id = intval($_POST['vid']);
    $dir='documents/parc_auto/decisions/';
    if(!is_dir($dir)) mkdir($dir,0755,true);

    // Cas 1 : fichier envoyé via upload classique (si jamais)
    $fullPath = null; $origName = null;
    if(!empty($_FILES['decision_file']['tmp_name'])){
      $origName = $_FILES['decision_file']['name'];
      $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
      $fn='decision_'.$id.'_'.time().'.'.$ext;
      $fullPath=$dir.$fn;
      move_uploaded_file($_FILES['decision_file']['tmp_name'], $fullPath);
    }
    // Cas 2 : fichier envoyé en base64 depuis le modal de confirmation
    elseif(!empty($_POST['decision_base64']) && !empty($_POST['decision_filename'])){
      $origName = basename($_POST['decision_filename']);
      $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
      $decoded=base64_decode($_POST['decision_base64'],true);
      if($decoded!==false){
        $fn='decision_'.$id.'_'.time().'.'.$ext;
        $fullPath=$dir.$fn;
        file_put_contents($fullPath,$decoded);
      }
    }

    if($fullPath){
      // Historique décisions
      $pdo->prepare("INSERT INTO parc_auto_decisions (affectation_id, filepath, original_name) VALUES (?,?,?)")
          ->execute([$id, $fullPath, $origName]);
      $pdo->prepare("UPDATE parc_auto_affectation SET decision_pdf=? WHERE id=?")->execute([$fullPath,$id]);

      // Mettre à jour la fiche véhicule avec les données confirmées
      if(!empty($_POST['update_vehicle'])){
        $updates=[]; $params=[];
        $fields=[
          'c_num_pe'     => 'num_pe',
          'c_marque'     => 'marque',
          'c_genre'      => 'genre',
          'c_affectation'=> 'affectation',
          'c_nature'     => 'nature',
        ];
        foreach($fields as $post=>$col){
          if(!empty($_POST[$post])){
            $updates[]=$col.'=?'; $params[]=trim($_POST[$post]);
          }
        }
        // Notes : carburant + dates
        $noteParts=[];
        if(!empty($_POST['c_carburant'])) $noteParts[]='Carburant: '.intval($_POST['c_carburant']).' L/mois';
        if(!empty($_POST['c_date_debut'])) $noteParts[]='Début: '.$_POST['c_date_debut'];
        if(!empty($_POST['c_date_fin']))   $noteParts[]='Fin: '.$_POST['c_date_fin'];
        if(!empty($_POST['c_fonction']))   $noteParts[]='Fonction: '.$_POST['c_fonction'];
        if(!empty($_POST['c_entite']))     $noteParts[]='Entité: '.$_POST['c_entite'];
        if(!empty($_POST['c_direction']))  $noteParts[]='Direction: '.$_POST['c_direction'];
        if($noteParts){
          $cur=$pdo->prepare("SELECT notes FROM parc_auto_affectation WHERE id=?");
          $cur->execute([$id]);
          $existNotes=$cur->fetchColumn();
          $newNote=($existNotes?$existNotes."\n":'').implode(' | ',$noteParts).' [décision]';
          $updates[]='notes=?'; $params[]=$newNote;
        }
        if($updates){
          $params[]=$id;
          $pdo->prepare("UPDATE parc_auto_affectation SET ".implode(',',$updates)." WHERE id=?")->execute($params);
        }
        // Message flash avec résumé
        $det=[];
        if(!empty($_POST['c_num_pe']))      $det[]='N° '.$_POST['c_num_pe'];
        if(!empty($_POST['c_affectation'])) $det[]=$_POST['c_affectation'];
        if(!empty($_POST['c_nature']))      $det[]=$_POST['c_nature'];
        if(!empty($_POST['c_carburant']))   $det[]=intval($_POST['c_carburant']).' L/mois';
        $msg='Décision enregistrée — Données mises à jour';
        if($det) $msg.=' : '.implode(', ',$det);
        setFlashMessage('success',$msg.'.');
      } else {
        setFlashMessage('success','Décision enregistrée.');
      }
    } else {
      setFlashMessage('error','Aucun fichier reçu.');
    }
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $id); exit;
  }

  if($action==='upload_visite'){
    requireModulePermission($pdo, 'vehicules', 'update');
    $id = intval($_POST['vid']);
    if(!empty($_FILES['visite_file']['tmp_name'])){
      $dir='documents/parc_auto/visites/';
      if(!is_dir($dir)) mkdir($dir,0755,true);
      $ext=strtolower(pathinfo($_FILES['visite_file']['name'],PATHINFO_EXTENSION));
      $fn='visite_'.$id.'_'.time().'.'.$ext;
      move_uploaded_file($_FILES['visite_file']['tmp_name'],$dir.$fn);
      $pdo->prepare("UPDATE parc_auto_affectation SET visite_pdf=? WHERE id=?")->execute([$dir.$fn,$id]);
    }
    setFlashMessage('success','Carte de visite technique enregistrée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $id); exit;
  }

  if($action==='upload_carte_grise'){
    requireModulePermission($pdo, 'vehicules', 'update');
    $id = intval($_POST['vid']);
    if(!empty($_FILES['carte_grise_file']['tmp_name'])){
      $dir='documents/parc_auto/cartes_grises/';
      if(!is_dir($dir)) mkdir($dir,0755,true);
      $ext=strtolower(pathinfo($_FILES['carte_grise_file']['name'],PATHINFO_EXTENSION));
      $fn='carte_grise_'.$id.'_'.time().'.'.$ext;
      move_uploaded_file($_FILES['carte_grise_file']['tmp_name'],$dir.$fn);
      $pdo->prepare("UPDATE parc_auto_affectation SET carte_grise_pdf=? WHERE id=?")->execute([$dir.$fn,$id]);
    }
    setFlashMessage('success','Carte grise enregistrée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $id); exit;
  }

  if($action==='upload_vignette_doc'){
    requireModulePermission($pdo, 'vehicules', 'update');
    $id   = intval($_POST['vid']);
    $annee = intval($_POST['annee_vignette'] ?? date('Y'));
    if(!empty($_FILES['vignette_file']['tmp_name'])){
      $dir='documents/parc_auto/vignettes/';
      if(!is_dir($dir)) mkdir($dir,0755,true);
      $origName = $_FILES['vignette_file']['name'];
      $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
      $fn='vignette_'.$id.'_'.$annee.'_'.time().'.'.$ext;
      $fullPath = $dir.$fn;
      move_uploaded_file($_FILES['vignette_file']['tmp_name'], $fullPath);
      // Historique vignettes par année
      $pdo->prepare("INSERT INTO parc_auto_vignettes_hist (affectation_id, annee, filepath, original_name) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE filepath=VALUES(filepath), original_name=VALUES(original_name), uploaded_at=NOW()")
          ->execute([$id, $annee, $fullPath, $origName]);
      // Colonne legacy (dernière vignette)
      $pdo->prepare("UPDATE parc_auto_affectation SET vignette_pdf=? WHERE id=?")->execute([$fullPath,$id]);
    }
    setFlashMessage('success','Vignette '.$annee.' enregistrée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $id); exit;
  }

  if($action==='delete_vignette'){
    requireModulePermission($pdo, 'vehicules', 'delete');
    $hid = intval($_POST['hid']);
    $vid = intval($_POST['vid']);
    $row = $pdo->prepare("SELECT filepath FROM parc_auto_vignettes_hist WHERE id=?");
    $row->execute([$hid]);
    $path = $row->fetchColumn();
    if($path && file_exists($path)) @unlink($path);
    $pdo->prepare("DELETE FROM parc_auto_vignettes_hist WHERE id=?")->execute([$hid]);
    // mettre à jour colonne legacy
    $latest = $pdo->prepare("SELECT filepath FROM parc_auto_vignettes_hist WHERE affectation_id=? ORDER BY annee DESC LIMIT 1");
    $latest->execute([$vid]);
    $lpath = $latest->fetchColumn();
    $pdo->prepare("UPDATE parc_auto_affectation SET vignette_pdf=? WHERE id=?")->execute([$lpath?:null,$vid]);
    setFlashMessage('success','Vignette supprimée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $vid); exit;
  }

  if($action==='upload_assurance'){
    requireModulePermission($pdo, 'vehicules', 'update');
    $id   = intval($_POST['vid']);
    $annee = intval($_POST['annee_assurance'] ?? date('Y'));
    $date_exp = ($_POST['date_exp_assurance']??'')?:null;
    if(!empty($_FILES['assurance_file']['tmp_name'])){
      $dir='documents/parc_auto/assurances/';
      if(!is_dir($dir)) mkdir($dir,0755,true);
      $origName = $_FILES['assurance_file']['name'];
      $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
      $fn='assurance_'.$id.'_'.$annee.'_'.time().'.'.$ext;
      $fullPath = $dir.$fn;
      move_uploaded_file($_FILES['assurance_file']['tmp_name'], $fullPath);
      // Historique assurances par année
      $pdo->prepare("INSERT INTO parc_auto_assurances_hist (affectation_id, annee, filepath, original_name, date_expiration) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE filepath=VALUES(filepath), original_name=VALUES(original_name), date_expiration=VALUES(date_expiration), uploaded_at=NOW()")
          ->execute([$id, $annee, $fullPath, $origName, $date_exp]);
      // Colonne legacy
      $pdo->prepare("UPDATE parc_auto_affectation SET assurance_pdf=? WHERE id=?")->execute([$fullPath,$id]);
    }
    setFlashMessage('success','Assurance '.$annee.' enregistrée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $id); exit;
  }

  if($action==='delete_assurance'){
    requireModulePermission($pdo, 'vehicules', 'delete');
    $hid = intval($_POST['hid']);
    $vid = intval($_POST['vid']);
    $row = $pdo->prepare("SELECT filepath FROM parc_auto_assurances_hist WHERE id=?");
    $row->execute([$hid]);
    $path = $row->fetchColumn();
    if($path && file_exists($path)) @unlink($path);
    $pdo->prepare("DELETE FROM parc_auto_assurances_hist WHERE id=?")->execute([$hid]);
    $latest = $pdo->prepare("SELECT filepath FROM parc_auto_assurances_hist WHERE affectation_id=? ORDER BY annee DESC LIMIT 1");
    $latest->execute([$vid]);
    $lpath = $latest->fetchColumn();
    $pdo->prepare("UPDATE parc_auto_affectation SET assurance_pdf=? WHERE id=?")->execute([$lpath?:null,$vid]);
    setFlashMessage('success','Assurance supprimée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $vid); exit;
  }

  if($action==='delete_decision'){
    requireModulePermission($pdo, 'vehicules', 'delete');
    $did=intval($_POST['did']); // decision row id
    $vid=intval($_POST['vid']); // vehicle id
    $row=$pdo->prepare("SELECT filepath FROM parc_auto_decisions WHERE id=?");
    $row->execute([$did]);
    $path=$row->fetchColumn();
    if($path && file_exists($path)) @unlink($path);
    $pdo->prepare("DELETE FROM parc_auto_decisions WHERE id=?")->execute([$did]);
    // update legacy badge column to latest remaining decision (or null)
    $latest=$pdo->prepare("SELECT filepath FROM parc_auto_decisions WHERE affectation_id=? ORDER BY uploaded_at DESC LIMIT 1");
    $latest->execute([$vid]);
    $lpath=$latest->fetchColumn();
    $pdo->prepare("UPDATE parc_auto_affectation SET decision_pdf=? WHERE id=?")->execute([$lpath?:null,$vid]);
    setFlashMessage('success','Décision supprimée.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $vid); exit;
  }

  if($action==='update'){
    requireModulePermission($pdo, 'vehicules', 'update');
    $id=intval($_POST['vid']);
    $pdo->prepare("UPDATE parc_auto_affectation SET
      num_pe=?,marque=?,genre=?,localite=?,nature=?,affectation=?,
      date_mise=?,valeur_achat=?,date_visite=?,etat=?,replacement=?,notes=?
      WHERE id=?")
    ->execute([
      $_POST['num_pe']??null,
      $_POST['marque']??null,
      $_POST['genre']??null,
      $_POST['localite']??null,
      $_POST['nature']??null,
      ($_POST['affectation']??null)?:null,
      ($_POST['date_mise']??null)?:null,
      ($_POST['valeur_achat']??null)?:null,
      ($_POST['date_visite']??null)?:null,
      ($_POST['etat']??null)?:null,
      ($_POST['replacement']??null)?:null,
      ($_POST['notes']??null)?:null,
      $id
    ]);
    setFlashMessage('success','Véhicule mis à jour.');
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?open=' . $id); exit;
  }

  if($action==='add'){
    requireModulePermission($pdo, 'vehicules', 'create');
    $pdo->prepare("INSERT INTO parc_auto_affectation
      (num_pe,marque,genre,localite,nature,affectation,date_mise,valeur_achat,date_visite,etat,replacement,notes)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
      $_POST['num_pe']??null,$_POST['marque']??null,$_POST['genre']??null,
      $_POST['localite']??null,$_POST['nature']??null,($_POST['affectation']??null)?:null,
      ($_POST['date_mise']??null)?:null,($_POST['valeur_achat']??null)?:null,
      ($_POST['date_visite']??null)?:null,$_POST['etat']??null,
      ($_POST['replacement']??null)?:null,($_POST['notes']??null)?:null,
    ]);
    setFlashMessage('success','Véhicule ajouté.');
    header('Location: ' . basename($_SERVER['PHP_SELF'])); exit;
  }

  if($action==='delete'){
    requireModulePermission($pdo, 'vehicules', 'delete');
    $pdo->prepare("DELETE FROM parc_auto_affectation WHERE id=?")->execute([intval($_POST['vid'])]);
    setFlashMessage('success','Véhicule supprimé.');
    header('Location: ' . basename($_SERVER['PHP_SELF'])); exit;
  }

  header('Location: ' . basename($_SERVER['PHP_SELF'])); exit;
}

/* ══ FETCH ══ */
try {
  // add decision_pdf col if missing
  try { $pdo->exec("ALTER TABLE parc_auto_affectation ADD COLUMN decision_pdf VARCHAR(500) NULL"); } catch(Exception $e){}
  try { $pdo->exec("ALTER TABLE parc_auto_affectation ADD COLUMN notes TEXT NULL"); } catch(Exception $e){}
  try { $pdo->exec("ALTER TABLE parc_auto_affectation ADD COLUMN visite_pdf VARCHAR(500) NULL"); } catch(Exception $e){}
  try { $pdo->exec("ALTER TABLE parc_auto_affectation ADD COLUMN carte_grise_pdf VARCHAR(500) NULL"); } catch(Exception $e){}
  try { $pdo->exec("ALTER TABLE parc_auto_affectation ADD COLUMN vignette_pdf VARCHAR(500) NULL"); } catch(Exception $e){}
  try { $pdo->exec("ALTER TABLE parc_auto_affectation ADD COLUMN assurance_pdf VARCHAR(500) NULL"); } catch(Exception $e){}
  // decisions history table
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS parc_auto_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affectation_id INT NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    original_name VARCHAR(300) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aff (affectation_id)
  )"); } catch(Exception $e){}

  // TABLE HISTORIQUE VIGNETTES PAR ANNEE
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS parc_auto_vignettes_hist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affectation_id INT NOT NULL,
    annee SMALLINT NOT NULL DEFAULT 0,
    filepath VARCHAR(500) NOT NULL,
    original_name VARCHAR(300) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_veh_annee (affectation_id, annee),
    INDEX idx_vaff (affectation_id)
  )"); } catch(Exception $e){}

  // TABLE HISTORIQUE ASSURANCES PAR ANNEE
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS parc_auto_assurances_hist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affectation_id INT NOT NULL,
    annee SMALLINT NOT NULL DEFAULT 0,
    filepath VARCHAR(500) NOT NULL,
    original_name VARCHAR(300) NOT NULL,
    date_expiration DATE NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ass_annee (affectation_id, annee),
    INDEX idx_aaff (affectation_id)
  )"); } catch(Exception $e){}

  // Migration vignette_pdf existant vers historique (une seule fois)
  try {
    $ex2 = $pdo->query("SELECT id, vignette_pdf FROM parc_auto_affectation WHERE vignette_pdf IS NOT NULL AND vignette_pdf!=''"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach($ex2 as $row){
      $cnt=$pdo->prepare("SELECT COUNT(*) FROM parc_auto_vignettes_hist WHERE affectation_id=? AND filepath=?");
      $cnt->execute([$row['id'],$row['vignette_pdf']]);
      if(!$cnt->fetchColumn()){
        $pdo->prepare("INSERT IGNORE INTO parc_auto_vignettes_hist (affectation_id,annee,filepath,original_name) VALUES (?,?,?,?)")
            ->execute([$row['id'], (int)date('Y'), $row['vignette_pdf'], basename($row['vignette_pdf'])]);
      }
    }
  } catch(Exception $e){}

  // Migration assurance_pdf existant vers historique (une seule fois)
  try {
    $ex3 = $pdo->query("SELECT id, assurance_pdf FROM parc_auto_affectation WHERE assurance_pdf IS NOT NULL AND assurance_pdf!=''"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach($ex3 as $row){
      $cnt=$pdo->prepare("SELECT COUNT(*) FROM parc_auto_assurances_hist WHERE affectation_id=? AND filepath=?");
      $cnt->execute([$row['id'],$row['assurance_pdf']]);
      if(!$cnt->fetchColumn()){
        $pdo->prepare("INSERT IGNORE INTO parc_auto_assurances_hist (affectation_id,annee,filepath,original_name) VALUES (?,?,?,?)")
            ->execute([$row['id'], (int)date('Y'), $row['assurance_pdf'], basename($row['assurance_pdf'])]);
      }
    }
  } catch(Exception $e){}

  // Migrate existing decision_pdf into the history table (run once)
  try {
    $existing = $pdo->query("SELECT id, decision_pdf FROM parc_auto_affectation WHERE decision_pdf IS NOT NULL AND decision_pdf!='' ")->fetchAll(PDO::FETCH_ASSOC);
    foreach($existing as $row){
      $cnt=$pdo->prepare("SELECT COUNT(*) FROM parc_auto_decisions WHERE affectation_id=? AND filepath=?");
      $cnt->execute([$row['id'],$row['decision_pdf']]);
      if(!$cnt->fetchColumn()){
        $pdo->prepare("INSERT INTO parc_auto_decisions (affectation_id,filepath,original_name) VALUES (?,?,?)")
            ->execute([$row['id'],$row['decision_pdf'],basename($row['decision_pdf'])]);
      }
    }
  } catch(Exception $e){}

  // Load all decisions grouped by vehicle id
  $all_decisions_raw = $pdo->query("SELECT * FROM parc_auto_decisions ORDER BY uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);
  $decisions_by_vehicle = [];
  foreach($all_decisions_raw as $d){ $decisions_by_vehicle[$d['affectation_id']][] = $d; }

  // Load vignettes history grouped by vehicle id
  $all_vignettes_hist = $pdo->query("SELECT * FROM parc_auto_vignettes_hist ORDER BY annee DESC")->fetchAll(PDO::FETCH_ASSOC);
  $vignettes_by_vehicle = [];
  foreach($all_vignettes_hist as $vh){ $vignettes_by_vehicle[$vh['affectation_id']][] = $vh; }

  // Load assurances history grouped by vehicle id
  $all_assurances_hist = $pdo->query("SELECT * FROM parc_auto_assurances_hist ORDER BY annee DESC")->fetchAll(PDO::FETCH_ASSOC);
  $assurances_by_vehicle = [];
  foreach($all_assurances_hist as $ah){ $assurances_by_vehicle[$ah['affectation_id']][] = $ah; }

  $vehicles = $pdo->query("
    SELECT a.*,
      TIMESTAMPDIFF(YEAR, a.date_mise, CURDATE()) AS age_ans,
      v.montant   AS vignette_montant,
      v.energie,
      v.puissance
    FROM parc_auto_affectation a
    LEFT JOIN parc_auto_vignette v
      ON REPLACE(REPLACE(a.num_pe,' ',''),'-','')
       = REPLACE(REPLACE(v.num_pe,' ',''),'-','')
    ORDER BY a.marque, a.genre, a.num_pe
  ")->fetchAll(PDO::FETCH_ASSOC);

  $stats = $pdo->query("
    SELECT
      COUNT(*) total,
      SUM(etat='FONCTIONNEL') fonctionnels,
      SUM(etat='Reforme') reformes,
      SUM(etat LIKE '%PANNE%' OR etat LIKE '%panne%') en_panne,
      SUM(etat LIKE '%REPARATION%') en_reparation,
      SUM(etat LIKE '%ACCIDENT%') accidentes,
      SUM(nature='V. Service') v_service,
      SUM(nature='V. Fonction') v_fonction,
      SUM(date_visite IS NOT NULL AND date_visite < CURDATE()) visite_exp,
      SUM(decision_pdf IS NOT NULL AND decision_pdf!='') avec_decision,
      SUM(visite_pdf IS NOT NULL AND visite_pdf!='') avec_visite,
      SUM(carte_grise_pdf IS NOT NULL AND carte_grise_pdf!='') avec_carte_grise,
      SUM(vignette_pdf IS NOT NULL AND vignette_pdf!='') avec_vignette_doc,
      SUM(assurance_pdf IS NOT NULL AND assurance_pdf!='') avec_assurance,
      ROUND(SUM(IFNULL(valeur_achat,0)),2) valeur_totale
    FROM parc_auto_affectation
  ")->fetch(PDO::FETCH_ASSOC);

  $vignette_total = $pdo->query("SELECT ROUND(SUM(montant),2) total FROM parc_auto_vignette")->fetchColumn();

} catch(PDOException $e){
  $vehicles=[]; $stats=[]; $vignette_total=0;
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parc Automobile · TUNISAIR</title>
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
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);}
::-webkit-scrollbar{width:5px;height:5px;}::-webkit-scrollbar-track{background:var(--bg);}::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;}

/* NAVBAR */
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;max-width:110px;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-bc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
.nav-bc a{color:var(--muted);text-decoration:none;}.nav-bc a:hover{color:var(--red);}
.nav-bc .sep{opacity:.4;}
.nav-right{display:flex;align-items:center;gap:14px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deco{background:var(--red);color:white;padding:7px 18px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;}
.btn-deco:hover{background:var(--red-dark);}

.app-wrap{display:flex;flex-direction:column;height:100vh;}
.app{display:flex;flex:1;overflow:hidden;min-height:0;}

/* ── COL LIST ── */
.col-list{width:360px;min-width:320px;background:var(--white);border-right:1.5px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;}
.cl-top{padding:12px;border-bottom:1.5px solid var(--rule);flex-shrink:0;}
.sw{position:relative;margin-bottom:8px;}
.sw svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.si{width:100%;padding:8px 10px 8px 30px;border:1.5px solid var(--rule);border-radius:9px;font-size:12px;font-family:inherit;color:var(--ink);background:var(--bg);outline:none;}
.si:focus{border-color:var(--red);}
.si::placeholder{color:var(--muted);}
.fr{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;}
.fs{flex:1;min-width:80px;padding:6px 8px;border:1.5px solid var(--rule);border-radius:8px;font-size:11px;font-family:inherit;color:var(--ink);background:var(--white);outline:none;cursor:pointer;}
.fs:focus{border-color:var(--red);}
.btn-add-v{width:100%;padding:8px;color:white;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;background:linear-gradient(135deg,var(--red-dark),var(--red));transition:opacity .2s;}
.btn-add-v:hover{opacity:.88;}
.rc{font-size:10px;color:var(--muted);font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:5px 12px 2px;}
.vlist{flex:1;overflow-y:auto;padding:6px;}

/* Vehicle card */
.vc{padding:10px 12px;border-radius:11px;border:1.5px solid transparent;cursor:pointer;margin-bottom:4px;background:var(--white);box-shadow:0 1px 4px rgba(0,0,0,.05);transition:all .15s;}
.vc:hover{border-color:var(--rule);box-shadow:0 3px 10px rgba(0,0,0,.08);}
.vc.sel{border-color:var(--red);}
.vct{display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;}
.vpe{font-family:monospace;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:#F4F6F9;color:var(--navy);}
.vst{font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;margin-left:auto;}
.ok{background:#DCFCE7;color:#15803D;}
.panne{background:#FEE2E2;color:#DC2626;}
.ref{background:#F3F4F6;color:#6B7280;}
.rep{background:#FEF3C7;color:#D97706;}
.acc{background:#FEE2E2;color:#9B0E23;}
.aut{background:#EFF6FF;color:#1D4ED8;}
.vnat{font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700;}
.ns{background:#DBEAFE;color:#1E40AF;}
.nf{background:#EDE9FE;color:#5B21B6;}
.vpdf{font-size:9px;background:#DCFCE7;color:#15803D;padding:1px 5px;border-radius:4px;font-weight:700;}
.vname{font-size:13px;font-weight:600;color:var(--ink);}
.vmeta{display:flex;align-items:center;gap:6px;margin-top:3px;font-size:11px;color:var(--muted);flex-wrap:wrap;}
.dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;}
.dt{background:#1D4ED8;}.dm{background:#059669;}.dd{background:#D97706;}.ds{background:#7C3AED;}.dz{background:#C8102E;}
.nores{padding:36px 14px;text-align:center;color:var(--muted);font-size:13px;}

/* ── COL DETAIL ── */
.col-det{flex:1;min-width:0;overflow-y:auto;background:var(--bg);}
.empty-state{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;color:var(--muted);padding:40px;text-align:center;}
.empty-state svg{opacity:.18;}
.empty-state p{font-size:14px;line-height:1.7;max-width:300px;}

/* Stats cards */
.stats-wrap{padding:20px 24px 4px;}
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:16px;}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(3,1fr);}}
.sc{background:var(--white);border-radius:11px;padding:11px 14px;border:1.5px solid var(--rule);text-align:center;}
.scv{font-size:20px;font-weight:700;color:var(--navy);}
.scl{font-size:10px;color:var(--muted);margin-top:2px;}
.totals-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px;}
.tot{background:var(--white);border-radius:9px;padding:9px 14px;border:1.5px solid var(--rule);font-size:12px;color:var(--muted);}
.tot strong{color:var(--navy);}

/* Detail wrap */
.dw{padding:18px 24px 60px;max-width:820px;}
@keyframes sIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.dw{animation:sIn .18s ease;}

/* Detail header */
.dh{background:var(--white);border-radius:16px;border:1.5px solid var(--rule);padding:18px 22px;margin-bottom:12px;box-shadow:var(--shadow);position:relative;overflow:hidden;}
.dh::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--red-dark),var(--red));}
.dh-bc{font-size:11px;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;gap:4px;}
.dh-bc span{color:var(--red);}
.dh-row{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;}
.dh-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;}
.dh-info{flex:1;}
.dh-title{font-size:21px;font-weight:700;color:var(--navy);letter-spacing:-.2px;}
.dh-sub{font-size:12px;color:var(--muted);margin-top:3px;}
.dh-badges{display:flex;align-items:center;gap:7px;margin-top:9px;flex-wrap:wrap;}
.bdg{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;border:1px solid transparent;}
.bdg-ok{background:#DCFCE7;color:#15803D;border-color:#BBF7D0;}
.bdg-warn{background:#FEF3C7;color:#D97706;border-color:#FDE68A;}
.bdg-exp{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.bdg-none{background:#F3F4F6;color:#6B7280;border-color:#E5E7EB;}
.bdg-svc{background:#DBEAFE;color:#1E40AF;}
.bdg-fnc{background:#EDE9FE;color:#5B21B6;}
.bdg-pdf{background:#DCFCE7;color:#15803D;border-color:#BBF7D0;}
.dh-actions{display:flex;gap:7px;flex-shrink:0;flex-wrap:wrap;}

/* Sections */
.sec{background:var(--white);border-radius:13px;padding:16px 18px;margin-bottom:11px;border:1.5px solid var(--rule);box-shadow:var(--shadow);}
.sec.sec-aff{border-color:rgba(15,37,99,.2);background:rgba(15,37,99,.02);}
.sec.sec-vig{border-color:rgba(200,16,46,.18);background:rgba(200,16,46,.02);}
.sec.sec-dec{border-color:#BBF7D0;background:#F0FDF4;}
.sec-t{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--rule);display:flex;align-items:center;gap:6px;}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;}
.g4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;}
.f{background:var(--bg);border-radius:9px;padding:9px 12px;}
.f.full{grid-column:1/-1;}
.f.hi{background:rgba(200,16,46,.04);border:1px solid rgba(200,16,46,.12);}
.f.hi-navy{background:rgba(15,37,99,.04);border:1px solid rgba(15,37,99,.12);}
.f.hi-green{background:rgba(21,128,61,.04);border:1px solid rgba(21,128,61,.15);}
.fl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:2px;}
.fv{font-size:13px;font-weight:600;color:var(--ink);}
.fv.mono{font-family:monospace;}
.fv.g{color:#15803D;}.fv.r{color:#DC2626;}.fv.o{color:#D97706;}.fv.gr{color:var(--muted);font-weight:400;}
.fv.b{color:var(--navy);}
.fv.lg{font-size:16px;}

/* Affectation banner */
.aff-banner{display:flex;align-items:center;gap:14px;padding:14px 18px;background:linear-gradient(135deg,rgba(15,37,99,.05),rgba(29,78,216,.04));border-radius:11px;border:1.5px solid rgba(15,37,99,.15);margin-bottom:8px;}
.aff-icon{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,var(--navy),var(--navy-mid));display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.aff-text{flex:1;}
.aff-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:3px;}
.aff-val{font-size:15px;font-weight:700;color:var(--navy);}
.aff-sub{font-size:11px;color:var(--muted);margin-top:2px;}

/* Decision card */
.dec-card{display:flex;align-items:center;gap:12px;background:var(--bg);border-radius:10px;padding:10px 14px;margin-bottom:10px;}
.dec-ico{width:38px;height:38px;border-radius:9px;background:#DCFCE7;display:grid;place-items:center;flex-shrink:0;}
.dec-info{flex:1;min-width:0;}
.dec-name{font-size:12px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.dec-sub{font-size:10px;color:var(--muted);margin-top:1px;}
.dec-acts{display:flex;gap:6px;flex-shrink:0;}
.upload-z{border:2px dashed var(--rule);border-radius:9px;padding:16px;text-align:center;cursor:pointer;transition:border-color .2s;}
.upload-z:hover{border-color:var(--red);}
.upload-z input{display:none;}
.upload-z-lbl{font-size:12px;color:var(--muted);margin-top:5px;}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none;white-space:nowrap;}
.btn-g{background:transparent;color:var(--muted);border:1.5px solid var(--rule);}.btn-g:hover{background:var(--bg);}
.btn-r{background:var(--red);color:white;}.btn-r:hover{background:var(--red-dark);}
.btn-navy{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:white;}.btn-navy:hover{opacity:.88;}
.btn-danger{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}
.btn-sm{padding:5px 10px;font-size:11px;}
.spin{display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,.3);border-top-color:white;border-radius:50%;animation:sp .7s linear infinite;}
@keyframes sp{to{transform:rotate(360deg)}}

/* Modal */
.modal-bg{display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.mi{background:var(--white);border-radius:18px;width:700px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.2);}
.mi-hd{padding:18px 24px 0;display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
.mi-hd h2{font-size:16px;font-weight:700;color:var(--navy);}
.mi-close{width:28px;height:28px;border-radius:7px;border:none;background:var(--bg);cursor:pointer;display:grid;place-items:center;color:var(--muted);font-size:14px;}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:10px 24px;}
.fg-g{display:flex;flex-direction:column;gap:4px;}.fg-g.full{grid-column:1/-1;}
.fg-sec{grid-column:1/-1;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:4px 0 2px;border-bottom:1.5px solid var(--rule);margin-top:4px;}
.fg-lbl{font-size:12px;font-weight:600;color:var(--ink);}
.fg-i{padding:9px 11px;background:var(--bg);border:1.5px solid var(--rule);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);outline:none;transition:border-color .2s;}
.fg-i:focus{border-color:var(--red);}
.fg-acts{padding:10px 24px 18px;display:flex;gap:8px;justify-content:flex-end;}

/* Tabs in modal */
.tabs{display:flex;border-bottom:1.5px solid var(--rule);margin:0 24px;}
.tab{padding:9px 15px;font-size:12px;font-weight:600;cursor:pointer;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1.5px;transition:all .15s;}
.tab.on{color:var(--red);border-bottom-color:var(--red);}
.tp{display:none;padding:14px 24px;}.tp.on{display:block;}
.pdf-prev{background:#f5f5f5;border-radius:9px;padding:12px;margin-top:10px;}
.pdf-prev iframe{width:100%;height:330px;border:none;border-radius:6px;}

/* Flash */
.flash{position:fixed;top:72px;right:20px;z-index:500;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.12);}
.flash.success{background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0;}
mark{background:#FEF3C7;border-radius:2px;padding:0 1px;}
</style>
</head>
<body>
<div class="app-wrap">

<nav class="navbar">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="index.php" class="nav-brand">
      <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
      <span class="nav-brand-text">TUNISAIR — Patrimoine</span>
    </a>
    <span style="opacity:.3;font-size:18px;">|</span>
    <nav class="nav-bc">
      <a href="dashboard.php">Accueil</a><span class="sep">›</span>
      <strong style="color:var(--ink);">Parc Automobile</strong>
    </nav>
  </div>
  <div class="nav-right">
    <?php if($canCreate): ?>
    <button class="btn btn-navy btn-sm" onclick="openAnalyseDecision()" style="gap:6px;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M9 2H4a2 2 0 00-2 2v16a2 2 0 002 2h16a2 2 0 002-2V9L14 2H9z" stroke="white" stroke-width="1.5"/><path d="M14 2v7h7M9 13h6M9 17h4" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
      Analyser une décision PDF
    </button>
    <?php endif; ?>
    <span class="nav-user"><?=htmlspecialchars($username)?></span>
    <a href="logout.php" class="btn-deco">Déconnexion</a>
  </div>
</nav>

<?php if($flash): ?>
<div class="flash <?=htmlspecialchars($flash['type'])?>" id="fm"><?=htmlspecialchars($flash['message'])?></div>
<script>setTimeout(()=>{const f=document.getElementById('fm');if(f)f.remove();},3000);</script>
<?php endif; ?>

<div class="app">

<!-- ══ COL LIST ══ -->
<div class="col-list">
  <div class="cl-top">
    <div class="sw">
      <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M15 15l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      <input class="si" id="srch" placeholder="N° PE, marque, affectation…" oninput="filter()">
    </div>
    <div class="fr">
      <select class="fs" id="fE" onchange="filter()">
        <option value="">Tous états</option>
        <option value="FONCTIONNEL">Fonctionnel</option>
        <option value="Reforme">Réformé</option>
        <option value="panne">En panne</option>
        <option value="REPARATION">En réparation</option>
        <option value="ACCIDENT">Accidenté</option>
      </select>
      <select class="fs" id="fN" onchange="filter()">
        <option value="">Tout type</option>
        <option value="V. Service">V. Service</option>
        <option value="V. Fonction">V. Fonction</option>
      </select>
    </div>
    <div class="fr">
      <select class="fs" id="fL" onchange="filter()">
        <option value="">Toutes villes</option>
        <option value="Tunis">Tunis</option>
        <option value="Monastir">Monastir</option>
        <option value="Djerba">Djerba</option>
        <option value="Sfax">Sfax</option>
        <option value="Tozeur">Tozeur</option>
      </select>
      <select class="fs" id="fD" onchange="filter()">
        <option value="">Toutes décisions</option>
        <option value="avec">Avec décision</option>
        <option value="sans">Sans décision</option>
      </select>
    </div>
    <?php if($canCreate): ?>
    <button class="btn-add-v" onclick="openAdd()">
      <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
      Ajouter un véhicule
    </button>
    <?php endif; ?>
  </div>
  <div class="rc" id="rc"><?=count($vehicles)?> véhicules</div>
  <div class="vlist" id="vlist"></div>
</div>

<!-- ══ COL DETAIL ══ -->
<div class="col-det">
  <div id="emptyState">
    <!-- Stats Board -->
    <?php if($stats): ?>
    <div class="stats-wrap">
      <div class="stats-grid">
        <div class="sc"><div class="scv"><?=intval($stats['total'])?></div><div class="scl">Total</div></div>
        <div class="sc"><div class="scv" style="color:#15803D"><?=intval($stats['fonctionnels'])?></div><div class="scl">Fonctionnels</div></div>
        <div class="sc"><div class="scv" style="color:#DC2626"><?=intval($stats['en_panne'])+intval($stats['en_reparation'])?></div><div class="scl">Panne / Rép.</div></div>
        <div class="sc"><div class="scv" style="color:#6B7280"><?=intval($stats['reformes'])?></div><div class="scl">Réformés</div></div>
        <div class="sc"><div class="scv" style="color:#D97706"><?=intval($stats['visite_exp'])?></div><div class="scl">Visites exp.</div></div>
        <div class="sc"><div class="scv" style="color:#15803D"><?=intval($stats['avec_decision'])?></div><div class="scl">Décisions PDF</div></div>
      </div>
      <div class="totals-row">
        <div class="tot">🚗 V. Service : <strong><?=intval($stats['v_service'])?></strong></div>
        <div class="tot">💼 V. Fonction : <strong><?=intval($stats['v_fonction'])?></strong></div>
        <div class="tot">🏷 Vignettes 2026 : <strong><?=number_format(floatval($vignette_total),0,'.','')?> DT</strong></div>
        <div class="tot">💰 Valeur totale : <strong><?=number_format(floatval($stats['valeur_totale']),0,'.',' ')?> TND</strong></div>
        <div class="tot">🔍 Visites PDF : <strong><?=intval($stats['avec_visite']??0)?></strong></div>
        <div class="tot">🪪 Cartes grises : <strong><?=intval($stats['avec_carte_grise']??0)?></strong></div>
        <div class="tot">🛡 Assurances : <strong><?=intval($stats['avec_assurance']??0)?></strong></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="empty-state">
      <svg width="52" height="52" viewBox="0 0 64 64" fill="none">
        <rect x="4" y="16" width="56" height="36" rx="6" stroke="currentColor" stroke-width="2"/>
        <path d="M14 28h8M14 36h20M38 36h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <circle cx="14" cy="48" r="4" stroke="currentColor" stroke-width="2"/>
        <circle cx="50" cy="48" r="4" stroke="currentColor" stroke-width="2"/>
      </svg>
      <p>Sélectionnez un véhicule pour afficher tous ses détails — affectation, visite technique, vignette et décision PDF.</p>
    </div>
  </div>
  <div id="detCont" style="display:none;"></div>
</div>

</div>
</div>

<!-- ══ MODAL ADD/EDIT ══ -->
<div class="modal-bg" id="formModal">
  <div class="mi">
    <div class="mi-hd">
      <h2 id="fmTitle">Ajouter un véhicule</h2>
      <button class="mi-close" onclick="closeM('formModal')">✕</button>
    </div>
    <form method="post" id="vForm">
      <input type="hidden" name="action" id="fAction" value="add">
      <input type="hidden" name="vid" id="fVid">
      <div class="fg">
        <div class="fg-sec">Identification</div>
        <div class="fg-g"><label class="fg-lbl">N° PE / Matricule *</label><input class="fg-i" name="num_pe" id="f_pe" required style="font-family:monospace"></div>
        <div class="fg-g"><label class="fg-lbl">Marque</label><input class="fg-i" name="marque" id="f_marque"></div>
        <div class="fg-g"><label class="fg-lbl">Modèle / Genre</label><input class="fg-i" name="genre" id="f_genre"></div>
        <div class="fg-g"><label class="fg-lbl">Valeur d'achat (TND)</label><input class="fg-i" type="number" step="0.01" name="valeur_achat" id="f_val"></div>
        <div class="fg-g"><label class="fg-lbl">Date mise en circulation</label><input class="fg-i" type="date" name="date_mise" id="f_dm"></div>
        <div class="fg-g"><label class="fg-lbl">Expiration visite technique</label><input class="fg-i" type="date" name="date_visite" id="f_dv"></div>
        <div class="fg-sec">Affectation</div>
        <div class="fg-g"><label class="fg-lbl">Localité</label>
          <select class="fg-i" name="localite" id="f_loc">
            <option value="Tunis">Tunis</option><option value="Monastir">Monastir</option>
            <option value="Djerba">Djerba</option><option value="Sfax">Sfax</option><option value="Tozeur">Tozeur</option>
          </select></div>
        <div class="fg-g"><label class="fg-lbl">Nature</label>
          <select class="fg-i" name="nature" id="f_nat">
            <option value="V. Service">V. Service</option><option value="V. Fonction">V. Fonction</option>
          </select></div>
        <div class="fg-g full"><label class="fg-lbl">Direction / Service affectataire</label><input class="fg-i" name="affectation" id="f_aff"></div>
        <div class="fg-sec">État</div>
        <div class="fg-g"><label class="fg-lbl">État actuel</label>
          <select class="fg-i" name="etat" id="f_etat">
            <option value="FONCTIONNEL">Fonctionnel</option><option value="Reforme">Réformé</option>
            <option value="EN PANNE">En panne</option><option value="EN REPARATION">En réparation</option>
            <option value="ACCIDENTEE">Accidenté</option>
          </select></div>
        <div class="fg-g"><label class="fg-lbl">Remplacement</label><input class="fg-i" name="replacement" id="f_rep"></div>
        <div class="fg-g full"><label class="fg-lbl">Notes</label><textarea class="fg-i" name="notes" id="f_notes" rows="2" style="resize:vertical"></textarea></div>
      </div>
      <div class="fg-acts">
        <button type="button" class="btn btn-g" onclick="closeM('formModal')">Annuler</button>
        <button type="submit" class="btn btn-r">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL DÉCISION PDF — ÉTAPE 1 : UPLOAD ══ -->
<div class="modal-bg" id="decModal">
  <div class="mi" style="width:500px;">
    <div class="mi-hd">
      <h2>📄 Ajouter une décision — <span id="decRef" style="color:var(--red)"></span></h2>
      <button class="mi-close" onclick="closeM('decModal')">✕</button>
    </div>
    <div style="padding:18px 24px 20px;">
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.6;">
        Importez le PDF de décision. Les données seront extraites automatiquement pour confirmation.
      </p>
      <label class="upload-z" for="impFile" style="margin-bottom:12px;display:block;cursor:pointer;">
        <input type="file" id="impFile" name="decision_file" accept=".pdf,.jpg,.jpeg,.png" onchange="prevUpload(this)" style="display:none;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="margin:0 auto;display:block;opacity:.3"><path d="M12 16V8M9 11l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 16.7A4 4 0 0018 9h-1.26A8 8 0 104 17.3" stroke="currentColor" stroke-width="1.5"/></svg>
        <div class="upload-z-lbl" id="impLbl">Cliquer ou glisser un fichier PDF / image</div>
      </label>
      <div id="impPrev" style="display:none;margin-bottom:12px;" class="pdf-prev"></div>
      <div id="decExtractErr" style="display:none;background:#FEE2E2;color:#DC2626;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:12px;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('decModal')">Annuler</button>
        <button type="button" class="btn btn-navy" id="decExtractBtn" onclick="lancerExtraction()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Analyser le document
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL CONFIRMATION DONNÉES EXTRAITES — ÉTAPE 2 ══ -->
<div class="modal-bg" id="decConfirmModal">
  <div class="mi" style="width:560px;max-height:90vh;overflow-y:auto;">
    <div class="mi-hd" style="position:sticky;top:0;z-index:2;background:var(--bg);">
      <h2>✅ Confirmer les données extraites</h2>
      <button class="mi-close" onclick="closeM('decConfirmModal')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data" id="decConfirmForm" style="padding:18px 24px 20px;">
      <input type="hidden" name="action" value="upload_decision">
      <input type="hidden" name="vid" id="conf_vid">
      <input type="hidden" name="update_vehicle" value="1">
      <!-- fichier re-envoyé -->
      <input type="hidden" name="decision_base64" id="conf_b64">
      <input type="hidden" name="decision_filename" id="conf_fname">
      <input type="hidden" name="c_direction" id="conf_direction">
      <input type="hidden" name="c_entite" id="conf_entite">
      <input type="hidden" name="c_fonction" id="conf_fonction">

      <div style="background:#F0FDF4;border:1.5px solid #86EFAC;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:#15803D;line-height:1.6;">
        🤖 Données extraites automatiquement par OCR. Vérifiez et corrigez si nécessaire avant de sauvegarder.
      </div>

      <!-- Bandeau employé trouvé -->
      <div id="empBanner" style="display:none;background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;color:#1D4ED8;margin-bottom:6px;">👤 Employé trouvé dans la base de données</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px;">
          <div><span style="color:#6B7280;">Nom complet :</span> <strong id="emp_nom_affiche"></strong></div>
          <div><span style="color:#6B7280;">Matricule :</span> <strong id="emp_mle_affiche"></strong></div>
          <div><span style="color:#6B7280;">Fonction :</span> <span id="emp_fonct_affiche"></span></div>
          <div><span style="color:#6B7280;">Entité :</span> <span id="emp_entite_affiche"></span></div>
          <div style="grid-column:1/-1;"><span style="color:#6B7280;">Direction :</span> <span id="emp_dir_affiche"></span></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label class="fg-lbl">N° Immatriculation</label>
          <input class="fg-i" type="text" name="c_num_pe" id="conf_num_pe" style="font-family:monospace;font-weight:700;">
        </div>
        <div>
          <label class="fg-lbl">Matricule agent</label>
          <input class="fg-i" type="text" name="c_matricule" id="conf_matricule">
        </div>
        <div>
          <label class="fg-lbl">Marque</label>
          <input class="fg-i" type="text" name="c_marque" id="conf_marque">
        </div>
        <div>
          <label class="fg-lbl">Modèle / Genre</label>
          <input class="fg-i" type="text" name="c_genre" id="conf_genre">
        </div>
        <div class="full" style="grid-column:1/-1;">
          <label class="fg-lbl">Affectation (nom)</label>
          <input class="fg-i" type="text" name="c_affectation" id="conf_affectation">
        </div>
        <div>
          <label class="fg-lbl">Nature</label>
          <select class="fg-i" name="c_nature" id="conf_nature">
            <option value="">— Non détecté —</option>
            <option value="V. Fonction">V. Fonction</option>
            <option value="V. Service">V. Service</option>
          </select>
        </div>
        <div>
          <label class="fg-lbl">Carburant (L/mois)</label>
          <input class="fg-i" type="number" name="c_carburant" id="conf_carburant">
        </div>
        <div>
          <label class="fg-lbl">Date début</label>
          <input class="fg-i" type="date" name="c_date_debut" id="conf_date_debut">
        </div>
        <div>
          <label class="fg-lbl">Date fin</label>
          <input class="fg-i" type="date" name="c_date_fin" id="conf_date_fin">
        </div>
      </div>

      <div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;font-size:11px;color:#92400E;margin-bottom:16px;">
        ⚠️ En confirmant, la fiche du véhicule sera mise à jour avec ces données.
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('decConfirmModal');openM('decModal');">← Retour</button>
        <button type="submit" class="btn btn-navy" style="background:#15803D;">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Confirmer et sauvegarder
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL DELETE ══ -->
<div class="modal-bg" id="delModal">
  <div class="mi" style="width:380px;padding:28px;">
    <div style="text-align:center;">
      <div style="width:48px;height:48px;border-radius:12px;background:#FEE2E2;display:grid;place-items:center;margin:0 auto 14px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" stroke="#DC2626" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <h3 style="font-size:16px;font-weight:700;color:var(--navy);margin-bottom:8px;">Supprimer ce véhicule ?</h3>
      <p id="delLbl" style="font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6;"></p>
      <div style="display:flex;gap:9px;justify-content:center;">
        <button class="btn btn-g" onclick="closeM('delModal')">Annuler</button>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="vid" id="delVid">
          <button type="submit" class="btn btn-danger">Supprimer définitivement</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ══ JS ══ -->
<script>
const VEH = <?=json_encode(array_values($vehicles), JSON_UNESCAPED_UNICODE)?>;
const CAN_CREATE = <?=$canCreate?'true':'false'?>;
const CAN_UPDATE = <?=$canUpdate?'true':'false'?>;
const CAN_DELETE = <?=$canDelete?'true':'false'?>;
const DECISIONS = <?=json_encode($decisions_by_vehicle,  JSON_UNESCAPED_UNICODE)?>;
const VIG_HIST  = <?=json_encode($vignettes_by_vehicle,  JSON_UNESCAPED_UNICODE)?>;
const ASS_HIST  = <?=json_encode($assurances_by_vehicle, JSON_UNESCAPED_UNICODE)?>;
let activeId = null, curV = null;

const esc = s => s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : '';
const fmt = (v,d=2) => v!=null&&v!==''&&parseFloat(v)>0 ? parseFloat(v).toLocaleString('fr-TN',{minimumFractionDigits:d,maximumFractionDigits:d}) : '—';

/* ── STATUS helpers ── */
function stCls(e){
  const l=(e||'').toLowerCase();
  if(l==='fonctionnel') return 'ok';
  if(l==='reforme') return 'ref';
  if(l.includes('panne')) return 'panne';
  if(l.includes('reparation')) return 'rep';
  if(l.includes('accident')) return 'acc';
  return 'aut';
}
function stLbl(e){
  const u=(e||'').toUpperCase().trim();
  if(u==='FONCTIONNEL') return 'Fonctionnel';
  if(u==='REFORME') return 'Réformé';
  if(u.includes('PANNE')) return 'En panne';
  if(u.includes('REPARATION')) return 'En réparation';
  if(u.includes('ACCIDENT')) return 'Accidenté';
  if(!u) return 'Non renseigné';
  return e;
}
function dotCls(l){
  return {'tunis':'dt','monastir':'dm','djerba':'dd','sfax':'ds','tozeur':'dz'}[(l||'').toLowerCase()]||'dt';
}
function vIcon(m){
  const ml=(m||'').toLowerCase();
  if(ml.includes('camion')||ml.includes('tracteur')||ml.includes('remorque')) return ['#E0F2FE','🚛'];
  if(ml.includes('ambulance')) return ['#FEE2E2','🚑'];
  if(ml.includes('minibus')||ml.includes('microbus')) return ['#F3E8FF','🚌'];
  return ['#F0FDF4','🚗'];
}
function visiteBdg(d){
  if(!d) return '<span class="bdg bdg-none">Visite: Non renseignée</span>';
  const dt=new Date(d),now=new Date();
  const diff=(dt-now)/864e5;
  const f=dt.toLocaleDateString('fr-TN',{day:'2-digit',month:'2-digit',year:'numeric'});
  if(diff<0) return `<span class="bdg bdg-exp">⚠ Visite expirée — ${f}</span>`;
  if(diff<90) return `<span class="bdg bdg-warn">⏰ Bientôt — ${f}</span>`;
  return `<span class="bdg bdg-ok">✓ Visite OK — ${f}</span>`;
}

/* ── FILTER & RENDER LIST ── */
function filter(){
  const q=(document.getElementById('srch').value||'').toLowerCase().trim();
  const fe=(document.getElementById('fE').value||'').toLowerCase();
  const fn=document.getElementById('fN').value||'';
  const fl=document.getElementById('fL').value||'';
  const fd=document.getElementById('fD').value||'';
  const res=VEH.filter(v=>{
    if(q&&!(v.num_pe||'').toLowerCase().includes(q)&&!(v.marque||'').toLowerCase().includes(q)&&
       !(v.genre||'').toLowerCase().includes(q)&&!(v.affectation||'').toLowerCase().includes(q)) return false;
    if(fe&&!(v.etat||'').toLowerCase().includes(fe)) return false;
    if(fn&&v.nature!==fn) return false;
    if(fl&&v.localite!==fl) return false;
    if(fd==='avec'&&!v.decision_pdf) return false;
    if(fd==='sans'&&v.decision_pdf) return false;
    return true;
  });
  renderList(res,q);
  document.getElementById('rc').textContent=res.length+' véhicule'+(res.length!==1?'s':'');
}

function renderList(list,q=''){
  const c=document.getElementById('vlist');
  if(!list.length){c.innerHTML='<div class="nores">Aucun résultat.</div>';return;}
  const hl=(t,sq)=>sq&&t?t.replace(new RegExp('('+sq.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>'):esc(t||'');
  c.innerHTML=list.map(v=>{
    const sc=stCls(v.etat),sl=stLbl(v.etat),dc=dotCls(v.localite);
    const nc=v.nature==='V. Fonction'?'nf':'ns';
    const aff=v.affectation&&v.affectation!=='NON AFFECTEE'?v.affectation:'Non affectée';
    return `<div class="vc${v.id==activeId?' sel':''}" data-id="${v.id}" onclick="select(${v.id})">
      <div class="vct">
        <span class="vpe">${hl(v.num_pe,q)}</span>
        <span class="vnat ${nc}">${esc(v.nature)}</span>
        ${v.decision_pdf?'<span class="vpdf">📄 PDF</span>':''}
        <span class="vst ${sc}">${sl}</span>
      </div>
      <div class="vname">${hl(v.marque,q)} ${hl(v.genre||'',q)}</div>
      <div class="vmeta">
        <span class="dot ${dc}"></span>
        <span>${esc(v.localite||'')}</span>
        <span style="opacity:.35">·</span>
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;">${hl(aff,q)}</span>
      </div>
    </div>`;
  }).join('');
}

/* ── SELECT & BUILD DETAIL ── */
function select(id){
  activeId=id;
  document.querySelectorAll('.vc').forEach(c=>c.classList.remove('sel'));
  const c=document.querySelector(`.vc[data-id="${id}"]`);
  if(c){c.classList.add('sel');c.scrollIntoView({behavior:'smooth',block:'nearest'});}
  const v=VEH.find(x=>x.id==id);if(!v)return;
  curV=v;
  document.getElementById('emptyState').style.display='none';
  document.getElementById('detCont').style.display='block';
  document.getElementById('detCont').innerHTML=buildDet(v);
}

function buildDet(v){
  const [iBg,iEmoji]=vIcon(v.marque);
  const sc=stCls(v.etat),sl=stLbl(v.etat);
  const age=v.age_ans!=null&&v.age_ans!==''?parseInt(v.age_ans)+' an'+(parseInt(v.age_ans)>1?'s':''):'—';
  const isService=v.nature==='V. Service';
  const nonAff=!v.affectation||v.affectation==='NON AFFECTEE';

  /* field helper */
  const f=(lbl,val,cls='',full=false,hi='')=>val?
    `<div class="f${full?' full':''}${hi?' '+hi:''}">
      <div class="fl">${lbl}</div>
      <div class="fv ${cls}">${esc(val)}</div>
    </div>`:'';

  /* date formatter */
  const fd=(d)=>d?new Date(d).toLocaleDateString('fr-TN',{day:'2-digit',month:'long',year:'numeric'}):'Non renseignée';
  const fds=(d)=>d?new Date(d).toLocaleDateString('fr-TN'):'—';

  /* ── SECTION AFFECTATION ── */
  const affSection=`<div class="sec sec-aff">
    <div class="sec-t">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="1.5"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.5"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="1.5"/></svg>
      Affectation
    </div>
    <div class="aff-banner">
      <div class="aff-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" stroke="white" stroke-width="1.5"/><path d="M9 22V12h6v10" stroke="white" stroke-width="1.5"/></svg>
      </div>
      <div class="aff-text">
        <div class="aff-label">Direction / Service affectataire</div>
        <div class="aff-val">${nonAff?'<span style="color:var(--muted);font-weight:400;font-style:italic">Non affectée</span>':esc(v.affectation)}</div>
        ${v.localite?`<div class="aff-sub">📍 ${esc(v.localite)}</div>`:''}
      </div>
      <span class="bdg ${isService?'bdg-svc':'bdg-fnc'}">${esc(v.nature||'')}</span>
    </div>
    <div class="g3" style="margin-top:8px;">
      ${f('Localité',v.localite,'b')}
      ${f('Nature de l\'affectation',v.nature,isService?'':'',false,isService?'':'hi-navy')}
      ${f('État actuel',sl,sc==='ok'?'g':sc==='ref'?'gr':'r',false,sc!=='ok'?'hi':'')}
      ${v.date_visite?`<div class="f ${(() => {const diff=(new Date(v.date_visite)-new Date())/864e5;return diff<0?'hi':diff<90?'hi':'';})()}">
        <div class="fl">Expiration visite technique</div>
        <div class="fv ${(() => {const diff=(new Date(v.date_visite)-new Date())/864e5;return diff<0?'r':diff<90?'o':'g';})()}">${fd(v.date_visite)}</div>
      </div>`:''}
      ${v.replacement?f('Remplacement',v.replacement,'o',false,''):'' }
    </div>
  </div>`;

  /* ── SECTION IDENTIFICATION ── */
  const idSection=`<div class="sec">
    <div class="sec-t">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M5 6h6M5 9h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      Identification du véhicule
    </div>
    <div class="g4">
      <div class="f hi"><div class="fl">N° PE / Matricule</div><div class="fv mono">${esc(v.num_pe)}</div></div>
      <div class="f"><div class="fl">Marque</div><div class="fv">${esc(v.marque||'—')}</div></div>
      <div class="f"><div class="fl">Modèle / Genre</div><div class="fv">${esc(v.genre||'—')}</div></div>
      <div class="f"><div class="fl">Âge du véhicule</div><div class="fv">${age}</div></div>
    </div>
    <div class="g3" style="margin-top:8px;">
      <div class="f"><div class="fl">1ère mise en circulation</div><div class="fv">${fds(v.date_mise)}</div></div>
      <div class="f ${v.valeur_achat>0?'hi-green':''}"><div class="fl">Valeur d'achat</div><div class="fv ${v.valeur_achat>0?'g':''}">${v.valeur_achat>0?fmt(v.valeur_achat,2)+' TND':'—'}</div></div>
      ${v.energie?`<div class="f"><div class="fl">Énergie</div><div class="fv">${esc(v.energie)}</div></div>`:''}
      ${v.puissance?`<div class="f"><div class="fl">Puissance fiscale</div><div class="fv">${esc(v.puissance)}</div></div>`:''}
    </div>
    ${v.notes?`<div style="margin-top:8px;"><div class="f full"><div class="fl">Notes</div><div class="fv gr" style="font-style:italic">${esc(v.notes)}</div></div></div>`:''}
  </div>`;

  /* ── SECTION VIGNETTE ── */
  let vigSection='';
  if(v.vignette_montant){
    vigSection=`<div class="sec sec-vig">
      <div class="sec-t">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><rect x="2" y="3" width="12" height="10" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M5 9h2M9 9h2M5 7h6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        Vignette annuelle 2026
      </div>
      <div class="g3">
        <div class="f hi"><div class="fl">Montant vignette</div><div class="fv r lg">${fmt(v.vignette_montant,0)} DT</div></div>
        ${v.energie?`<div class="f"><div class="fl">Énergie</div><div class="fv">${esc(v.energie)}</div></div>`:''}
        ${v.puissance?`<div class="f"><div class="fl">Puissance fiscale</div><div class="fv">${esc(v.puissance)}</div></div>`:''}
      </div>
    </div>`;
  }

  /* ── SECTION DÉCISION PDF (tous les véhicules — historique) ── */
  const vDecs = DECISIONS[v.id] || [];
  let decSection='';
  {
    decSection=`<div class="sec ${vDecs.length?'sec-dec':''}">
    <div class="sec-t">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M9 2H4a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2V7L9 2z" stroke="currentColor" stroke-width="1.3"/><path d="M9 2v5h5M6 9h4M6 11.5h2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      Décisions d'Affectation
      ${vDecs.length?`<span style="margin-left:6px;background:#DCFCE7;color:#15803D;font-size:9px;font-weight:700;padding:1px 7px;border-radius:20px;">${vDecs.length} doc${vDecs.length>1?'s':''}</span>`:''}
    </div>`;

    if(vDecs.length){
      decSection+=`<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;">`;
      vDecs.forEach((d,i)=>{
        const fname=d.original_name||d.filepath.split('/').pop();
        const isPdf=d.filepath.toLowerCase().endsWith('.pdf');
        const dt=d.uploaded_at?new Date(d.uploaded_at).toLocaleDateString('fr-TN',{day:'2-digit',month:'2-digit',year:'numeric'}):'';
        decSection+=`<div class="dec-card" style="margin-bottom:0;">
          <div class="dec-ico">${isPdf?'📄':'🖼'}</div>
          <div class="dec-info">
            <div class="dec-name">${esc(fname)}</div>
            <div class="dec-sub">${dt?'Ajouté le '+dt:''} ${i===0?'<span style="background:#DCFCE7;color:#15803D;padding:1px 6px;border-radius:4px;font-size:9px;font-weight:700;margin-left:4px;">Dernier</span>':''}</div>
          </div>
          <div class="dec-acts">
            <a href="${esc(d.filepath)}" target="_blank" class="btn btn-g btn-sm">↗ Ouvrir</a>
            <a href="${esc(d.filepath)}" download class="btn btn-g btn-sm">↓</a>
            ${CAN_DELETE ? `<form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette décision ?');">
              <input type="hidden" name="action" value="delete_decision">
              <input type="hidden" name="did" value="${d.id}">
              <input type="hidden" name="vid" value="${v.id}">
              <button type="submit" class="btn btn-sm" style="background:#FEE2E2;color:#DC2626;border:none;">🗑</button>
            </form>` : ''}
          </div>
        </div>`;
      });
      decSection+=`</div>`;
    } else {
      decSection+=`<p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Aucune décision d\'affectation enregistrée.</p>`;
    }

    decSection+=`<button class="btn btn-navy btn-sm" onclick="openDec(${v.id})">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
      Ajouter une décision
    </button>`;

    decSection+='</div>';
  } // fin section décision

  /* ── SECTION DOCUMENTS ── */
  // Visite + Carte grise : document unique (remplaçable)
  const simpleDocs=[
    {key:'visite_pdf',     label:'Visite Technique', icon:'🔍', action:'upload_visite',      field:'visite_file',      color:'#1D4ED8', bg:'#DBEAFE', sub:'Carte de visite technique'},
    {key:'carte_grise_pdf',label:'Carte Grise',      icon:'🪪', action:'upload_carte_grise', field:'carte_grise_file', color:'#059669', bg:'#D1FAE5', sub:"Certificat d'immatriculation"},
  ];
  let docsSection=`<div class="sec" style="border-color:rgba(29,78,216,.2);background:rgba(29,78,216,.01);">
    <div class="sec-t">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M4 2h6l3 3v9a1 1 0 01-1 1H4a1 1 0 01-1-1V3a1 1 0 011-1z" stroke="currentColor" stroke-width="1.3"/><path d="M9 2v4h3M6 8h4M6 10.5h3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      Documents du véhicule
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">`;

  simpleDocs.forEach(d=>{
    const has=!!v[d.key];
    const fname=has?v[d.key].split('/').pop():'';
    docsSection+=`<div style="border:1.5px solid ${has?d.color+'44':'var(--rule)'};border-radius:11px;padding:12px 14px;background:${has?d.bg+'55':'var(--bg)'};">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
        <span style="font-size:18px;">${d.icon}</span>
        <div style="flex:1;">
          <div style="font-size:12px;font-weight:700;color:${has?d.color:'var(--ink)'};">${d.label}</div>
          <div style="font-size:10px;color:var(--muted);">${d.sub}</div>
        </div>
        ${has?`<span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:${d.bg};color:${d.color};">✓ OK</span>`
              :`<span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:#F3F4F6;color:#6B7280;">Manquant</span>`}
      </div>
      ${has?`<div style="font-size:11px;color:var(--muted);margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(fname)}</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <a href="${esc(v[d.key])}" target="_blank" class="btn btn-g btn-sm">↗ Ouvrir</a>
        <a href="${esc(v[d.key])}" download class="btn btn-g btn-sm">↓</a>
        <button class="btn btn-sm" style="background:#F3F4F6;color:#6B7280;" onclick="openDocUpload(${v.id},'${d.action}','${d.field}','${d.label}')">↺ Remplacer</button>
      </div>`
      :`<button class="btn btn-sm" style="background:${d.bg};color:${d.color};border:1px solid ${d.color}44;" onclick="openDocUpload(${v.id},'${d.action}','${d.field}','${d.label}')">
        + Ajouter ${d.label}
      </button>`}
    </div>`;
  });
  docsSection+=`</div>`;

  // ── Vignette : historique par année ──
  const vigs = VIG_HIST[v.id] || [];
  docsSection += `<div style="border:1.5px solid #D97706aa;border-radius:11px;padding:14px;margin-bottom:10px;background:#FEF3C755;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:18px;">🏷</span>
        <div>
          <div style="font-size:12px;font-weight:700;color:#D97706;">Vignette fiscale</div>
          <div style="font-size:10px;color:var(--muted);">Historique par année</div>
        </div>
        ${vigs.length?`<span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:#FEF3C7;color:#D97706;">${vigs.length} an${vigs.length>1?'s':''}</span>`:''}
      </div>
      <button class="btn btn-sm" style="background:#FEF3C7;color:#D97706;border:1px solid #D9770644;" onclick="openVigUpload(${v.id})">
        + Ajouter année
      </button>
    </div>`;
  if(vigs.length){
    docsSection+=`<div style="display:flex;flex-direction:column;gap:6px;">`;
    vigs.forEach(vh=>{
      const fname=vh.original_name||vh.filepath.split('/').pop();
      docsSection+=`<div class="dec-card" style="margin:0;">
        <div class="dec-ico">🏷</div>
        <div class="dec-info">
          <div class="dec-name">${esc(fname)}</div>
          <div class="dec-sub">Année ${esc(String(vh.annee))} · Ajouté le ${vh.uploaded_at?new Date(vh.uploaded_at).toLocaleDateString('fr-TN'):''}</div>
        </div>
        <div class="dec-acts">
          <a href="${esc(vh.filepath)}" target="_blank" class="btn btn-g btn-sm">↗</a>
          <a href="${esc(vh.filepath)}" download class="btn btn-g btn-sm">↓</a>
          ${CAN_DELETE ? `<form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette vignette ?');">
            <input type="hidden" name="action" value="delete_vignette">
            <input type="hidden" name="hid" value="${vh.id}">
            <input type="hidden" name="vid" value="${v.id}">
            <button type="submit" class="btn btn-sm" style="background:#FEE2E2;color:#DC2626;border:none;">🗑</button>
          </form>` : ''}
        </div>
      </div>`;
    });
    docsSection+=`</div>`;
  } else {
    docsSection+=`<p style="font-size:12px;color:var(--muted);">Aucune vignette enregistrée.</p>`;
  }
  docsSection+=`</div>`;

  // ── Assurance : historique par année ──
  const asss = ASS_HIST[v.id] || [];
  docsSection += `<div style="border:1.5px solid #7C3AEDaa;border-radius:11px;padding:14px;background:#EDE9FE55;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:18px;">🛡</span>
        <div>
          <div style="font-size:12px;font-weight:700;color:#7C3AED;">Assurance</div>
          <div style="font-size:10px;color:var(--muted);">Historique par année</div>
        </div>
        ${asss.length?`<span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:#EDE9FE;color:#7C3AED;">${asss.length} an${asss.length>1?'s':''}</span>`:''}
      </div>
      <button class="btn btn-sm" style="background:#EDE9FE;color:#7C3AED;border:1px solid #7C3AED44;" onclick="openAssUpload(${v.id})">
        + Ajouter année
      </button>
    </div>`;
  if(asss.length){
    docsSection+=`<div style="display:flex;flex-direction:column;gap:6px;">`;
    asss.forEach(ah=>{
      const fname=ah.original_name||ah.filepath.split('/').pop();
      const expBadge=ah.date_expiration
        ? `<span style="font-size:9px;padding:1px 6px;border-radius:4px;background:${new Date(ah.date_expiration)<new Date()?'#FEE2E2;color:#DC2626':'#DCFCE7;color:#15803D'};">Exp. ${new Date(ah.date_expiration).toLocaleDateString('fr-TN')}</span>`
        : '';
      docsSection+=`<div class="dec-card" style="margin:0;">
        <div class="dec-ico">🛡</div>
        <div class="dec-info">
          <div class="dec-name">${esc(fname)} ${expBadge}</div>
          <div class="dec-sub">Année ${esc(String(ah.annee))} · Ajouté le ${ah.uploaded_at?new Date(ah.uploaded_at).toLocaleDateString('fr-TN'):''}</div>
        </div>
        <div class="dec-acts">
          <a href="${esc(ah.filepath)}" target="_blank" class="btn btn-g btn-sm">↗</a>
          <a href="${esc(ah.filepath)}" download class="btn btn-g btn-sm">↓</a>
          ${CAN_DELETE ? `<form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette assurance ?');">
            <input type="hidden" name="action" value="delete_assurance">
            <input type="hidden" name="hid" value="${ah.id}">
            <input type="hidden" name="vid" value="${v.id}">
            <button type="submit" class="btn btn-sm" style="background:#FEE2E2;color:#DC2626;border:none;">🗑</button>
          </form>` : ''}
        </div>
      </div>`;
    });
    docsSection+=`</div>`;
  } else {
    docsSection+=`<p style="font-size:12px;color:var(--muted);">Aucune assurance enregistrée.</p>`;
  }
  docsSection+=`</div></div>`;

  return `<div class="dw">
  <!-- Header -->
  <div class="dh">
    <div class="dh-bc">Parc Auto › <span>${esc(v.num_pe)}</span></div>
    <div class="dh-row">
      <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
        <div class="dh-icon" style="background:${iBg}">${iEmoji}</div>
        <div class="dh-info">
          <div class="dh-title">${esc(v.marque||'')} ${esc(v.genre||'')}</div>
          <div class="dh-sub">${esc(v.num_pe)} · ${esc(v.localite||'')} · ${age}</div>
          <div class="dh-badges">
            <span class="bdg ${isService?'bdg-svc':'bdg-fnc'}">${esc(v.nature||'')}</span>
            ${visiteBdg(v.date_visite)}
            ${v.decision_pdf?'<span class="bdg bdg-pdf">📄 Décision enregistrée</span>':''}
            ${v.vignette_montant?`<span class="bdg" style="background:#FEE2E2;color:#9B0E23;border-color:#FECACA;">🏷 Vignette ${fmt(v.vignette_montant,0)} DT</span>`:''}
          </div>
        </div>
      </div>
      <div class="dh-actions">
        ${CAN_UPDATE ? `<button class="btn btn-g btn-sm" onclick="openEdit(${v.id})">
          <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 012 2L5 13H3v-2z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Modifier
        </button>` : ''}
        ${CAN_DELETE ? `<button class="btn btn-sm btn-danger" onclick="openDel(${v.id})">
          <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1M6 7v5M10 7v5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Supprimer
        </button>` : ''}
      </div>
    </div>
  </div>
  ${affSection}
  ${idSection}
  ${vigSection}
  ${docsSection}
  ${decSection}
  </div>`;
}

/* ── OPEN MODALS ── */
function openAdd(){
  document.getElementById('fmTitle').textContent='Ajouter un véhicule';
  document.getElementById('fAction').value='add';
  document.getElementById('fVid').value='';
  ['f_pe','f_marque','f_genre','f_val','f_dm','f_dv','f_aff','f_rep','f_notes'].forEach(id=>{
    const el=document.getElementById(id);if(el)el.value='';
  });
  document.getElementById('f_loc').value='Tunis';
  document.getElementById('f_nat').value='V. Service';
  document.getElementById('f_etat').value='FONCTIONNEL';
  openM('formModal');
}
function openEdit(id){
  const v=VEH.find(x=>x.id==id);if(!v)return;
  document.getElementById('fmTitle').textContent='Modifier — '+v.marque+' '+( v.genre||'');
  document.getElementById('fAction').value='update';
  document.getElementById('fVid').value=id;
  document.getElementById('f_pe').value=v.num_pe||'';
  document.getElementById('f_marque').value=v.marque||'';
  document.getElementById('f_genre').value=v.genre||'';
  document.getElementById('f_val').value=v.valeur_achat||'';
  document.getElementById('f_dm').value=v.date_mise||'';
  document.getElementById('f_dv').value=v.date_visite||'';
  document.getElementById('f_loc').value=v.localite||'Tunis';
  document.getElementById('f_nat').value=v.nature||'V. Service';
  document.getElementById('f_aff').value=v.affectation||'';
  document.getElementById('f_etat').value=['FONCTIONNEL','Reforme','EN PANNE','EN REPARATION','ACCIDENTEE'].includes(v.etat)?v.etat:'FONCTIONNEL';
  document.getElementById('f_rep').value=v.replacement||'';
  document.getElementById('f_notes').value=v.notes||'';
  openM('formModal');
}
function openDel(id){
  const v=VEH.find(x=>x.id==id);
  document.getElementById('delVid').value=id;
  document.getElementById('delLbl').textContent=v?v.marque+' '+( v.genre||'')+' — '+v.num_pe:'';
  openM('delModal');
}
function openDec(id){
  const v=VEH.find(x=>x.id==id);if(!v)return;
  curV=v;
  document.getElementById('decRef').textContent=v.num_pe;
  document.getElementById('conf_vid').value=id;
  document.getElementById('impLbl').textContent='Cliquer ou glisser un fichier PDF / image';
  document.getElementById('impPrev').style.display='none';
  document.getElementById('impFile').value='';
  document.getElementById('decExtractErr').style.display='none';
  document.getElementById('decExtractBtn').disabled=false;
  document.getElementById('decExtractBtn').textContent='Analyser le document';
  openM('decModal');
}

function prevUpload(input){
  if(!input.files[0])return;
  document.getElementById('impLbl').textContent='✓ '+input.files[0].name;
  const url=URL.createObjectURL(input.files[0]);
  const isPdf=input.files[0].type==='application/pdf';
  const wrap=document.getElementById('impPrev');
  wrap.innerHTML=isPdf
    ?`<iframe src="${url}" style="width:100%;height:260px;border:none;border-radius:6px;"></iframe>`
    :`<img src="${url}" style="max-width:100%;border-radius:6px;">`;
  wrap.style.display='block';
}

async function lancerExtraction(){
  const fileInput = document.getElementById('impFile');
  const file = fileInput.files[0];
  if(!file){
    afficherErrDec('Veuillez sélectionner un fichier PDF ou image.');
    return;
  }

  const btn = document.getElementById('decExtractBtn');
  btn.disabled = true;
  btn.innerHTML = '<span style="opacity:.7">⏳ Analyse en cours…</span>';
  document.getElementById('decExtractErr').style.display='none';

  // Lire le fichier en base64
  const b64 = await new Promise((res,rej)=>{
    const r=new FileReader();
    r.onload=()=>res(r.result.split(',')[1]);
    r.onerror=rej;
    r.readAsDataURL(file);
  });

  try {
    const resp = await fetch('extract_decision.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        image_base64: b64,
        image_type:   file.type,
      })
    });
    const data = await resp.json();

    if(data.error){
      afficherErrDec('Erreur OCR : ' + data.error);
      btn.disabled=false;
      btn.textContent='Analyser le document';
      return;
    }

    // Pré-remplir le modal de confirmation
    document.getElementById('conf_num_pe').value      = data.num_pe           || '';
    document.getElementById('conf_matricule').value   = data.matricule_agent  || '';
    document.getElementById('conf_marque').value      = data.marque           || '';
    document.getElementById('conf_genre').value       = data.genre            || '';
    document.getElementById('conf_affectation').value = data.affectation      || '';
    document.getElementById('conf_nature').value      = data.nature           || '';
    document.getElementById('conf_carburant').value   = data.carburant_litres || '';
    document.getElementById('conf_date_debut').value  = data.date_debut       || '';
    document.getElementById('conf_date_fin').value    = data.date_fin         || '';
    document.getElementById('conf_direction').value   = data.direction        || '';
    document.getElementById('conf_entite').value      = data.entite           || '';
    document.getElementById('conf_fonction').value    = data.fonction         || '';
    document.getElementById('conf_b64').value         = b64;
    document.getElementById('conf_fname').value       = file.name;

    // Afficher le bandeau employé si trouvé
    const banner = document.getElementById('empBanner');
    if(data.employe_trouve && data.affectation){
      document.getElementById('emp_nom_affiche').textContent    = data.affectation || '';
      document.getElementById('emp_mle_affiche').textContent    = data.matricule_agent || '';
      document.getElementById('emp_fonct_affiche').textContent  = data.fonction || '—';
      document.getElementById('emp_entite_affiche').textContent = data.entite   || '—';
      document.getElementById('emp_dir_affiche').textContent    = data.direction|| '—';
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
    }

    closeM('decModal');
    openM('decConfirmModal');

  } catch(e){
    afficherErrDec('Erreur réseau : '+e.message);
    btn.disabled=false;
    btn.textContent='Analyser le document';
  }
}

function afficherErrDec(msg){
  const el=document.getElementById('decExtractErr');
  el.textContent=msg;
  el.style.display='block';
}

function openM(id){document.getElementById(id).classList.add('open');}
function closeM(id){document.getElementById(id).classList.remove('open');}

// ── Analyse décision indépendante (sans véhicule pré-sélectionné) ──
function openAnalyseDecision(){
  document.getElementById('adLbl').textContent = 'Cliquer ou glisser un fichier PDF / image';
  document.getElementById('adPrev').style.display = 'none';
  document.getElementById('adFile').value = '';
  document.getElementById('adErr').style.display = 'none';
  document.getElementById('adBtn').disabled = false;
  document.getElementById('adBtn').textContent = 'Analyser le document';
  openM('analyseDecModal');
}

async function lancerAnalyseDecision(){
  const fileInput = document.getElementById('adFile');
  const file = fileInput.files[0];
  if(!file){ afficherErrAd('Veuillez sélectionner un fichier PDF ou image.'); return; }

  const btn = document.getElementById('adBtn');
  btn.disabled = true;
  btn.innerHTML = '<span style="opacity:.7">⏳ Analyse en cours…</span>';
  document.getElementById('adErr').style.display = 'none';

  const b64 = await new Promise((res,rej)=>{
    const r=new FileReader();
    r.onload=()=>res(r.result.split(',')[1]);
    r.onerror=rej;
    r.readAsDataURL(file);
  });

  try {
    const fd = new FormData();
    fd.append('image_base64', b64);
    fd.append('image_type', file.type);

    const resp = await fetch('Extract_decision.php', { method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({image_base64: b64, image_type: file.type})
    });
    const text = await resp.text();

    let data;
    try {
      const lb = text.lastIndexOf('}');
      let depth=0, start=-1;
      for(let i=lb;i>=0;i--){
        if(text[i]==='}') depth++;
        else if(text[i]==='{'){depth--;if(depth===0){start=i;break;}}
      }
      data = JSON.parse(text.substring(start, lb+1));
    } catch(e){
      afficherErrAd('Réponse invalide : ' + text.substring(0,200));
      btn.disabled=false; btn.textContent='Analyser le document'; return;
    }

    if(data.error){ afficherErrAd('Erreur OCR : '+data.error); btn.disabled=false; btn.textContent='Analyser le document'; return; }

    // Chercher le véhicule correspondant par num_pe
    const numPe = (data.num_pe||'').replace(/[\s\-]/g,'').toLowerCase();
    const vehicule = numPe ? VEH.find(v => (v.num_pe||'').replace(/[\s\-]/g,'').toLowerCase() === numPe) : null;

    // Remplir le modal de confirmation indépendant
    document.getElementById('adc_vid').value         = vehicule ? vehicule.id : '';
    document.getElementById('adc_num_pe').value      = data.num_pe           || '';
    document.getElementById('adc_matricule').value   = data.matricule_agent  || '';
    document.getElementById('adc_marque').value      = data.marque           || '';
    document.getElementById('adc_genre').value       = data.genre            || '';
    document.getElementById('adc_affectation').value = data.affectation      || '';
    document.getElementById('adc_nature').value      = data.nature           || '';
    document.getElementById('adc_carburant').value   = data.carburant_litres || '';
    document.getElementById('adc_date_debut').value  = data.date_debut       || '';
    document.getElementById('adc_date_fin').value    = data.date_fin         || '';
    document.getElementById('adc_direction').value   = data.direction        || '';
    document.getElementById('adc_entite').value      = data.entite           || '';
    document.getElementById('adc_b64').value         = b64;
    document.getElementById('adc_fname').value       = file.name;

    // Bandeau véhicule trouvé
    const vBanner = document.getElementById('adcVehBanner');
    const vNotFound = document.getElementById('adcVehNotFound');
    if(vehicule){
      document.getElementById('adcVehInfo').innerHTML =
        `<strong>${esc(vehicule.marque||'')} ${esc(vehicule.genre||'')}</strong> — <span style="font-family:monospace">${esc(vehicule.num_pe)}</span> · ${esc(vehicule.localite||'')}`;
      vBanner.style.display = 'block';
      vNotFound.style.display = 'none';
    } else {
      vBanner.style.display = 'none';
      vNotFound.style.display = numPe ? 'block' : 'none';
      document.getElementById('adcVehNotFoundNum').textContent = data.num_pe || '';
    }

    // Bandeau employé
    const eBanner = document.getElementById('adcEmpBanner');
    if(data.employe_trouve && data.affectation){
      document.getElementById('adcEmpNom').textContent   = data.affectation || '';
      document.getElementById('adcEmpMle').textContent   = data.matricule_agent || '';
      document.getElementById('adcEmpFonct').textContent = data.fonction || '—';
      document.getElementById('adcEmpDir').textContent   = data.direction || '—';
      eBanner.style.display = 'block';
    } else {
      eBanner.style.display = 'none';
    }

    closeM('analyseDecModal');
    openM('analyseDecConfirmModal');

  } catch(e){
    afficherErrAd('Erreur réseau : '+e.message);
    btn.disabled=false; btn.textContent='Analyser le document';
  }
}

function afficherErrAd(msg){
  const el=document.getElementById('adErr');
  el.textContent=msg; el.style.display='block';
}
function prevAdUpload(input){
  if(!input.files[0])return;
  document.getElementById('adLbl').textContent='✓ '+input.files[0].name;
  const url=URL.createObjectURL(input.files[0]);
  const isPdf=input.files[0].type==='application/pdf';
  const wrap=document.getElementById('adPrev');
  wrap.innerHTML=isPdf?`<iframe src="${url}" style="width:100%;height:240px;border:none;border-radius:6px;"></iframe>`:`<img src="${url}" style="max-width:100%;border-radius:6px;max-height:200px;object-fit:contain;">`;
  wrap.style.display='block';
}

document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){closeM('formModal');closeM('decModal');closeM('delModal');}
});
document.querySelectorAll('.modal-bg').forEach(el=>el.addEventListener('click',function(e){
  if(e.target===this)this.classList.remove('open');
}));

filter();

// Auto-reopen vehicle after form submit (e.g. ?open=123)
(function(){
  const params = new URLSearchParams(window.location.search);
  const openId = params.get('open');
  if(openId){
    // Remove ?open= from URL without reloading
    history.replaceState(null,'', window.location.pathname);
    // Wait for filter() to render cards, then open
    setTimeout(()=>{ const el=document.querySelector(`.vcard[data-id="${openId}"]`); if(el) el.click(); }, 80);
  }
})();
</script>
<!-- ══ MODAL DOCUMENT UPLOAD ══ -->
<div class="modal-bg" id="docModal">
  <div class="mi" style="width:480px;">
    <div class="mi-hd">
      <h2 id="docModalTitle">Ajouter un document</h2>
      <button class="mi-close" onclick="closeM('docModal')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data" id="docUploadForm" style="padding:18px 24px 20px;">
      <input type="hidden" name="action" id="docAction">
      <input type="hidden" name="vid" id="docVid">
      <input type="file" id="docFileInput" name="placeholder_field" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="prevDocUpload(this)">
      <label class="upload-z" for="docFileInput" style="margin-bottom:12px;display:block;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="margin:0 auto;display:block;opacity:.3"><path d="M12 16V8M9 11l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 16.7A4 4 0 0018 9h-1.26A8 8 0 104 17.3" stroke="currentColor" stroke-width="1.5"/></svg>
        <div class="upload-z-lbl" id="docFileLbl">Cliquer ou glisser un fichier PDF / image</div>
      </label>
      <div id="docFilePrev" style="display:none;margin-bottom:12px;" class="pdf-prev"></div>
      <p style="font-size:11px;color:var(--muted);margin-bottom:14px;">Formats acceptés : PDF, JPG, PNG</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('docModal')">Annuler</button>
        <button type="submit" class="btn btn-navy" id="docSubmitBtn">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDocUpload(id, action, fieldName, label){
  document.getElementById('docModalTitle').textContent='Ajouter — '+label;
  document.getElementById('docAction').value=action;
  document.getElementById('docVid').value=id;
  // Rename the file input dynamically
  const fi=document.getElementById('docFileInput');
  fi.name=fieldName;
  document.getElementById('docFileLbl').textContent='Cliquer ou glisser un fichier PDF / image';
  document.getElementById('docFilePrev').style.display='none';
  fi.value='';
  openM('docModal');
}
function prevDocUpload(input){
  if(!input.files[0])return;
  document.getElementById('docFileLbl').textContent='✓ '+input.files[0].name;
  const url=URL.createObjectURL(input.files[0]);
  const isPdf=input.files[0].type==='application/pdf';
  const wrap=document.getElementById('docFilePrev');
  wrap.innerHTML=isPdf
    ?`<iframe src="${url}" style="width:100%;height:200px;border:none;border-radius:6px;"></iframe>`
    :`<img src="${url}" style="max-width:100%;border-radius:6px;max-height:200px;object-fit:contain;">`;
  wrap.style.display='block';
}

// ── Vignette upload modal ──
function openVigUpload(id){
  document.getElementById('vigVid').value=id;
  document.getElementById('vigAnnee').value=new Date().getFullYear();
  document.getElementById('vigFileLbl').textContent='Cliquer ou glisser un fichier PDF / image';
  document.getElementById('vigFilePrev').style.display='none';
  document.getElementById('vigFileInput').value='';
  openM('vigModal');
}
function prevVigUpload(input){
  if(!input.files[0])return;
  document.getElementById('vigFileLbl').textContent='✓ '+input.files[0].name;
  const url=URL.createObjectURL(input.files[0]);
  const isPdf=input.files[0].type==='application/pdf';
  const wrap=document.getElementById('vigFilePrev');
  wrap.innerHTML=isPdf?`<iframe src="${url}" style="width:100%;height:200px;border:none;border-radius:6px;"></iframe>`:`<img src="${url}" style="max-width:100%;border-radius:6px;max-height:180px;object-fit:contain;">`;
  wrap.style.display='block';
}

// ── Assurance upload modal ──
function openAssUpload(id){
  document.getElementById('assVid').value=id;
  document.getElementById('assAnnee').value=new Date().getFullYear();
  document.getElementById('assDateExp').value='';
  document.getElementById('assFileLbl').textContent='Cliquer ou glisser un fichier PDF / image';
  document.getElementById('assFilePrev').style.display='none';
  document.getElementById('assFileInput').value='';
  openM('assModal');
}
function prevAssUpload(input){
  if(!input.files[0])return;
  document.getElementById('assFileLbl').textContent='✓ '+input.files[0].name;
  const url=URL.createObjectURL(input.files[0]);
  const isPdf=input.files[0].type==='application/pdf';
  const wrap=document.getElementById('assFilePrev');
  wrap.innerHTML=isPdf?`<iframe src="${url}" style="width:100%;height:200px;border:none;border-radius:6px;"></iframe>`:`<img src="${url}" style="max-width:100%;border-radius:6px;max-height:180px;object-fit:contain;">`;
  wrap.style.display='block';
}
</script>

<!-- ══ MODAL VIGNETTE PAR ANNEE ══ -->
<div class="modal-bg" id="vigModal">
  <div class="mi" style="width:460px;">
    <div class="mi-hd">
      <h2>🏷 Ajouter une Vignette</h2>
      <button class="mi-close" onclick="closeM('vigModal')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data" style="padding:18px 24px 20px;">
      <input type="hidden" name="action" value="upload_vignette_doc">
      <input type="hidden" name="vid" id="vigVid">
      <div style="margin-bottom:14px;">
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Année fiscale</label>
        <input type="number" name="annee_vignette" id="vigAnnee" min="2000" max="2099"
               style="width:120px;padding:8px 10px;border:1.5px solid var(--rule);border-radius:8px;font-size:14px;font-family:inherit;font-weight:700;">
      </div>
      <input type="file" id="vigFileInput" name="vignette_file" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="prevVigUpload(this)">
      <label class="upload-z" for="vigFileInput" style="margin-bottom:12px;display:block;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="margin:0 auto;display:block;opacity:.3"><path d="M12 16V8M9 11l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 16.7A4 4 0 0018 9h-1.26A8 8 0 104 17.3" stroke="currentColor" stroke-width="1.5"/></svg>
        <div class="upload-z-lbl" id="vigFileLbl">Cliquer ou glisser un fichier PDF / image</div>
      </label>
      <div id="vigFilePrev" style="display:none;margin-bottom:12px;" class="pdf-prev"></div>
      <p style="font-size:11px;color:var(--muted);margin-bottom:14px;">Un seul fichier par année. Si l'année existe déjà, il sera remplacé.</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('vigModal')">Annuler</button>
        <button type="submit" class="btn btn-navy" style="background:#D97706;">Enregistrer la vignette</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL ASSURANCE PAR ANNEE ══ -->
<div class="modal-bg" id="assModal">
  <div class="mi" style="width:460px;">
    <div class="mi-hd">
      <h2>🛡 Ajouter une Assurance</h2>
      <button class="mi-close" onclick="closeM('assModal')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data" style="padding:18px 24px 20px;">
      <input type="hidden" name="action" value="upload_assurance">
      <input type="hidden" name="vid" id="assVid">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Année</label>
          <input type="number" name="annee_assurance" id="assAnnee" min="2000" max="2099"
                 style="width:100%;padding:8px 10px;border:1.5px solid var(--rule);border-radius:8px;font-size:14px;font-family:inherit;font-weight:700;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Date d'expiration</label>
          <input type="date" name="date_exp_assurance" id="assDateExp"
                 style="width:100%;padding:8px 10px;border:1.5px solid var(--rule);border-radius:8px;font-size:13px;font-family:inherit;">
        </div>
      </div>
      <input type="file" id="assFileInput" name="assurance_file" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="prevAssUpload(this)">
      <label class="upload-z" for="assFileInput" style="margin-bottom:12px;display:block;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="margin:0 auto;display:block;opacity:.3"><path d="M12 16V8M9 11l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 16.7A4 4 0 0018 9h-1.26A8 8 0 104 17.3" stroke="currentColor" stroke-width="1.5"/></svg>
        <div class="upload-z-lbl" id="assFileLbl">Cliquer ou glisser un fichier PDF / image</div>
      </label>
      <div id="assFilePrev" style="display:none;margin-bottom:12px;" class="pdf-prev"></div>
      <p style="font-size:11px;color:var(--muted);margin-bottom:14px;">Un seul fichier par année. La date d'expiration permet d'afficher une alerte si dépassée.</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('assModal')">Annuler</button>
        <button type="submit" class="btn btn-navy" style="background:#7C3AED;">Enregistrer l'assurance</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL ANALYSE DÉCISION INDÉPENDANTE — ÉTAPE 1 ══ -->
<div class="modal-bg" id="analyseDecModal">
  <div class="mi" style="width:500px;">
    <div class="mi-hd">
      <h2>📄 Analyser une décision PDF</h2>
      <button class="mi-close" onclick="closeM('analyseDecModal')">✕</button>
    </div>
    <div style="padding:18px 24px 20px;">
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.6;">
        Importez un PDF de décision d'affectation. Le système détectera automatiquement le véhicule concerné et extraira toutes les données.
      </p>
      <label class="upload-z" for="adFile" style="margin-bottom:12px;display:block;cursor:pointer;">
        <input type="file" id="adFile" accept=".pdf,.jpg,.jpeg,.png" onchange="prevAdUpload(this)" style="display:none;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="margin:0 auto;display:block;opacity:.3"><path d="M12 16V8M9 11l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 16.7A4 4 0 0018 9h-1.26A8 8 0 104 17.3" stroke="currentColor" stroke-width="1.5"/></svg>
        <div class="upload-z-lbl" id="adLbl">Cliquer ou glisser un fichier PDF / image</div>
      </label>
      <div id="adPrev" style="display:none;margin-bottom:12px;" class="pdf-prev"></div>
      <div id="adErr" style="display:none;background:#FEE2E2;color:#DC2626;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:12px;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('analyseDecModal')">Annuler</button>
        <button type="button" class="btn btn-navy" id="adBtn" onclick="lancerAnalyseDecision()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Analyser le document
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL ANALYSE DÉCISION — ÉTAPE 2 : CONFIRMATION ══ -->
<div class="modal-bg" id="analyseDecConfirmModal">
  <div class="mi" style="width:600px;max-height:92vh;overflow-y:auto;">
    <div class="mi-hd" style="position:sticky;top:0;z-index:2;background:var(--white);padding-bottom:12px;border-bottom:1px solid var(--rule);">
      <h2>✅ Confirmer et sauvegarder la décision</h2>
      <button class="mi-close" onclick="closeM('analyseDecConfirmModal')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data" style="padding:18px 24px 24px;">
      <input type="hidden" name="action" value="upload_decision">
      <input type="hidden" name="vid" id="adc_vid">
      <input type="hidden" name="update_vehicle" value="1">
      <input type="hidden" name="decision_base64" id="adc_b64">
      <input type="hidden" name="decision_filename" id="adc_fname">
      <input type="hidden" name="c_direction" id="adc_direction">
      <input type="hidden" name="c_entite" id="adc_entite">

      <!-- Bandeau véhicule trouvé -->
      <div id="adcVehBanner" style="display:none;background:#F0FDF4;border:1.5px solid #86EFAC;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
        <div style="font-size:11px;font-weight:700;color:#15803D;margin-bottom:4px;">🚗 Véhicule trouvé dans le parc</div>
        <div id="adcVehInfo" style="font-size:13px;color:var(--ink);"></div>
      </div>

      <!-- Véhicule non trouvé -->
      <div id="adcVehNotFound" style="display:none;background:#FEF9C3;border:1.5px solid #FDE68A;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
        <div style="font-size:11px;font-weight:700;color:#D97706;margin-bottom:4px;">⚠️ Véhicule non trouvé dans le parc</div>
        <div style="font-size:12px;color:var(--muted);">Immatriculation détectée : <strong id="adcVehNotFoundNum"></strong> — vérifiez et corrigez si nécessaire.</div>
      </div>

      <!-- Bandeau employé -->
      <div id="adcEmpBanner" style="display:none;background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
        <div style="font-size:11px;font-weight:700;color:#1D4ED8;margin-bottom:6px;">👤 Employé trouvé dans la base de données</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:12px;">
          <div><span style="color:#6B7280;">Nom :</span> <strong id="adcEmpNom"></strong></div>
          <div><span style="color:#6B7280;">Matricule :</span> <strong id="adcEmpMle"></strong></div>
          <div><span style="color:#6B7280;">Fonction :</span> <span id="adcEmpFonct"></span></div>
          <div><span style="color:#6B7280;">Direction :</span> <span id="adcEmpDir"></span></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div>
          <label class="fg-lbl">N° Immatriculation</label>
          <input class="fg-i" type="text" name="c_num_pe" id="adc_num_pe" style="font-family:monospace;font-weight:700;">
        </div>
        <div>
          <label class="fg-lbl">Matricule agent</label>
          <input class="fg-i" type="text" name="c_matricule" id="adc_matricule">
        </div>
        <div>
          <label class="fg-lbl">Marque</label>
          <input class="fg-i" type="text" name="c_marque" id="adc_marque">
        </div>
        <div>
          <label class="fg-lbl">Modèle / Genre</label>
          <input class="fg-i" type="text" name="c_genre" id="adc_genre">
        </div>
        <div style="grid-column:1/-1;">
          <label class="fg-lbl">Affectation (nom)</label>
          <input class="fg-i" type="text" name="c_affectation" id="adc_affectation">
        </div>
        <div>
          <label class="fg-lbl">Nature</label>
          <select class="fg-i" name="c_nature" id="adc_nature">
            <option value="">— Non détecté —</option>
            <option value="V. Fonction">V. Fonction</option>
            <option value="V. Service">V. Service</option>
          </select>
        </div>
        <div>
          <label class="fg-lbl">Carburant (L/mois)</label>
          <input class="fg-i" type="number" name="c_carburant" id="adc_carburant">
        </div>
        <div>
          <label class="fg-lbl">Date début</label>
          <input class="fg-i" type="date" name="c_date_debut" id="adc_date_debut">
        </div>
        <div>
          <label class="fg-lbl">Date fin</label>
          <input class="fg-i" type="date" name="c_date_fin" id="adc_date_fin">
        </div>
      </div>

      <div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;font-size:11px;color:#92400E;margin-bottom:16px;">
        ⚠️ En confirmant, la fiche du véhicule sera mise à jour avec ces données et le PDF sera sauvegardé.
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn btn-g" onclick="closeM('analyseDecConfirmModal');openM('analyseDecModal');">← Retour</button>
        <button type="submit" class="btn btn-navy" style="background:#15803D;">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Confirmer et sauvegarder
        </button>
      </div>
    </form>
  </div>
</div>

</body>
</html>